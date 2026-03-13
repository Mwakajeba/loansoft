<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Fee;
use App\Models\FeeRange;
use App\Models\Company;
use App\Models\Branch;
use App\Models\ChartAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Vinkla\Hashids\Facades\Hashids;

class FeeController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $companyId = $user->company_id ?? null;

        if ($companyId) {
            $fees = Fee::with(['company', 'branch', 'chartAccount', 'createdBy'])
                ->byCompany($companyId)
                ->orderBy('name')
                ->get();
        } else {
            $fees = Fee::with(['company', 'branch', 'chartAccount', 'createdBy'])
                ->orderBy('name')
                ->get();
        }

        $stats = [
            'total' => $fees->count(),
            'active' => $fees->where('status', 'active')->count(),
            'inactive' => $fees->where('status', 'inactive')->count(),
            'fixed' => $fees->where('fee_type', 'fixed')->count(),
            'percentage' => $fees->where('fee_type', 'percentage')->count(),
        ];

        return view('accounting.fees.index', compact('fees', 'stats'));
    }

    public function create()
    {
        $user = auth()->user();
        $companyId = $user->company_id ?? null;
        $branchId = $user->branch_id ?? null;

        $companies = Company::orderBy('name')->get();
        $branches = Branch::where('id', $branchId)->orderBy('name')->get();

        $chartAccounts = ChartAccount::whereHas('accountClassGroup.accountClass', function($q) {
            $q->whereIn('name', ['Revenue', 'Liabilities']);
        })->orderBy('account_name')->get();
        $statusOptions = Fee::getStatusOptions();
        $feeTypeOptions = Fee::getFeeTypeOptions();
        $deductionCriteriaOptions = Fee::getDeductionCriteriaOptions();

        return view('accounting.fees.create', compact('companies', 'branches', 'chartAccounts', 'statusOptions', 'feeTypeOptions', 'deductionCriteriaOptions'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'chart_account_id' => 'required|exists:chart_accounts,id',
            'fee_type' => 'required|in:fixed,percentage',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'deduction_criteria' => 'required|in:do_not_include_in_loan_schedule,distribute_fee_evenly_to_all_repayments,charge_fee_on_release_date,charge_fee_on_first_repayment,charge_fee_on_last_repayment,charge_same_fee_to_all_repayments',
            'include_in_schedule' => 'nullable|boolean', // Add validation
            'company_id' => 'nullable|exists:companies,id',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        if ($request->has('include_in_schedule')) {
            $existing = Fee::where('include_in_schedule', true)->first();
            if ($existing) {
                return redirect()->back()
                    ->withErrors(['include_in_schedule' => 'Only one fee can be included in the schedule.'])
                    ->withInput();
            }
        }

        $user = auth()->user();
        $companyId = $user->company_id ?? $request->company_id ?? Company::first()->id ?? 1;
        $branchId = $user->branch_id ?? $request->branch_id ?? Branch::first()->id ?? 1;

        DB::beginTransaction();
        try {
            $fee = Fee::create([
                'name' => $request->name,
                'chart_account_id' => $request->chart_account_id,
                'fee_type' => $request->fee_type,
                'amount' => $request->fee_type === 'range' ? 0 : $request->amount,
                'description' => $request->description,
                'status' => $request->status,
                'deduction_criteria' => $request->deduction_criteria,
                'include_in_schedule' => $request->has('include_in_schedule'),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            // If fee type is range, create fee ranges
            if ($request->fee_type === 'range' && $request->has('ranges')) {
                $order = 0;
                foreach ($request->ranges as $rangeData) {
                    FeeRange::create([
                        'fee_id' => $fee->id,
                        'from_amount' => $rangeData['from_amount'],
                        'to_amount' => $rangeData['to_amount'],
                        'amount' => $rangeData['amount'],
                        'order' => $order++,
                    ]);
                }
            }

            DB::commit();
            return redirect()->route('accounting.fees.index')->with('success', 'Fee created successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Failed to create fee: ' . $e->getMessage()])->withInput();
        }
    }

    public function show($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.fees.index')->withErrors(['Fee not found.']);
        }

        $fee = Fee::findOrFail($decoded[0]);
        $fee->load(['company', 'branch', 'chartAccount', 'createdBy', 'updatedBy', 'feeRanges']);

        return view('accounting.fees.show', compact('fee'));
    }

    public function edit($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.fees.index')->withErrors(['Fee not found.']);
        }

        $fee = Fee::findOrFail($decoded[0]);
        $fee->load('feeRanges');

        $user = auth()->user();
        $companyId = $user->company_id ?? null;
        $branchId = $user->branch_id ?? null;

        $companies = Company::orderBy('name')->get();
        $branches = Branch::where('id', $branchId)->orderBy('name')->get();

        $chartAccounts = ChartAccount::whereHas('accountClassGroup.accountClass', function($q) {
            $q->whereIn('name', ['Revenue', 'Liabilities']);
        })->orderBy('account_name')->get();
        $statusOptions = Fee::getStatusOptions();
        $feeTypeOptions = Fee::getFeeTypeOptions();
        $deductionCriteriaOptions = Fee::getDeductionCriteriaOptions();

        return view('accounting.fees.edit', compact('fee', 'companies', 'branches', 'chartAccounts', 'statusOptions', 'feeTypeOptions', 'deductionCriteriaOptions'));
    }

    public function update(Request $request, $encodedId)
    {
        // Decode fee ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.fees.index')->withErrors(['Fee not found.']);
        }

        $fee = Fee::findOrFail($decoded[0]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'chart_account_id' => 'required|exists:chart_accounts,id',
            'fee_type' => 'required|in:fixed,percentage,range',
            'amount' => 'required_if:fee_type,fixed,percentage|numeric|min:0',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'deduction_criteria' => 'required|in:do_not_include_in_loan_schedule,distribute_fee_evenly_to_all_repayments,charge_fee_on_release_date,charge_fee_on_first_repayment,charge_fee_on_last_repayment,charge_same_fee_to_all_repayments',
            'include_in_schedule' => 'nullable|boolean',
            'company_id' => 'nullable|exists:companies,id',
            'branch_id' => 'nullable|exists:branches,id',
            // Validation for fee ranges
            'ranges' => 'required_if:fee_type,range|array|min:1',
            'ranges.*.from_amount' => 'required|numeric|min:0',
            'ranges.*.to_amount' => 'required|numeric|min:0|gte:ranges.*.from_amount',
            'ranges.*.amount' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        if ($request->has('include_in_schedule')) {
            $existing = Fee::where('include_in_schedule', true)
                ->where('id', '!=', $fee->id)
                ->first();
            if ($existing) {
                return redirect()->back()
                    ->withErrors(['include_in_schedule' => 'Only one fee can be included in the schedule.'])
                    ->withInput();
            }
        }

        $user = auth()->user();
        $companyId = $user->company_id ?? $request->company_id ?? Company::first()->id ?? 1;
        $branchId = $user->branch_id ?? $request->branch_id ?? Branch::first()->id ?? 1;

        DB::beginTransaction();
        try {
            $fee->update([
                'name' => $request->name,
                'chart_account_id' => $request->chart_account_id,
                'fee_type' => $request->fee_type,
                'amount' => $request->fee_type === 'range' ? 0 : $request->amount,
                'description' => $request->description,
                'status' => $request->status,
                'deduction_criteria' => $request->deduction_criteria,
                'include_in_schedule' => $request->has('include_in_schedule'),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'updated_by' => $user->id,
            ]);

            // Delete existing ranges and create new ones if fee type is range
            if ($request->fee_type === 'range') {
                $fee->feeRanges()->delete();
                
                if ($request->has('ranges')) {
                    $order = 0;
                    foreach ($request->ranges as $rangeData) {
                        FeeRange::create([
                            'fee_id' => $fee->id,
                            'from_amount' => $rangeData['from_amount'],
                            'to_amount' => $rangeData['to_amount'],
                            'amount' => $rangeData['amount'],
                            'order' => $order++,
                        ]);
                    }
                }
            } else {
                // Delete ranges if changing from range to another type
                $fee->feeRanges()->delete();
            }

            DB::commit();
            return redirect()->route('accounting.fees.index')->with('success', 'Fee updated successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Failed to update fee: ' . $e->getMessage()])->withInput();
        }
    }

    public function destroy($encodedId)
    {
        // Decode the encoded ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.fees.index')->withErrors(['Fee not found.']);
        }

        $fee = Fee::findOrFail($decoded[0]);

        try {
            $fee->delete();
            return redirect()->route('accounting.fees.index')->with('success', 'Fee deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete fee. Please try again.');
        }
    }

    public function changeStatus(Request $request, $encodedId)
    {
        // Decode the encoded ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.fees.index')->withErrors(['Fee not found.']);
        }

        $fee = Fee::findOrFail($decoded[0]);

        $newStatus = $fee->status === 'active' ? 'inactive' : 'active';
        $fee->update(['status' => $newStatus]);

        $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';
        return redirect()->route('accounting.fees.index')->with('success', "Fee {$statusText} successfully!");
    }
}
