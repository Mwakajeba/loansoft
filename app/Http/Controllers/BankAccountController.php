<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\GlTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class BankAccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Determine current branch context:
        // 1) Use helper if available (set by middleware)
        // 2) Fallback to authenticated user's branch_id
        $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : null;
        if (!$currentBranchId && Auth::check()) {
            $currentBranchId = Auth::user()->branch_id;
        }

        // Base query with branch scoping:
        // - include accounts available to all branches
        // - plus accounts tied to the current branch (if any)
        $bankAccountsQuery = BankAccount::with('chartAccount.accountClassGroup.accountClass')
            ->when($currentBranchId, function ($query) use ($currentBranchId) {
                $query->where(function ($q) use ($currentBranchId) {
                    $q->where('is_all_branches', true)
                      ->orWhere('branch_id', $currentBranchId);
                });
            })
            ->orderBy('created_at', 'desc');

        // Calculate statistics based on the same branch‑scoped query
        $totalAccounts = (clone $bankAccountsQuery)->count();

        $allBankAccounts = (clone $bankAccountsQuery)->with('chartAccount')->get()->map(function ($bankAccount) {
            $debits = GlTransaction::where('chart_account_id', $bankAccount->chart_account_id)
                ->where('nature', 'debit')
                ->sum('amount');
            $credits = GlTransaction::where('chart_account_id', $bankAccount->chart_account_id)
                ->where('nature', 'credit')
                ->sum('amount');
            $bankAccount->balance = $debits - $credits;
            return $bankAccount;
        });

        $totalBalance = $allBankAccounts->sum('balance');
        $positiveBalanceAccounts = $allBankAccounts->where('balance', '>', 0)->count();
        $negativeBalanceAccounts = $allBankAccounts->where('balance', '<', 0)->count();

        return view('bank-accounts.index', compact('totalAccounts', 'totalBalance', 'positiveBalanceAccounts', 'negativeBalanceAccounts'));
    }

    /**
     * DataTables AJAX source for bank accounts.
     */
    public function data(Request $request)
    {
        $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : null;
        if (!$currentBranchId && Auth::check()) {
            $currentBranchId = Auth::user()->branch_id;
        }

        $query = BankAccount::with('chartAccount.accountClassGroup.accountClass')
            ->when($currentBranchId, function ($q) use ($currentBranchId) {
                $q->where(function ($sub) use ($currentBranchId) {
                    $sub->where('is_all_branches', true)
                        ->orWhere('branch_id', $currentBranchId);
                });
            })
            ->orderBy('created_at', 'desc');

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('chart_account', function (BankAccount $bankAccount) {
                return $bankAccount->chartAccount->account_name ?? 'N/A';
            })
            ->addColumn('account_class', function (BankAccount $bankAccount) {
                return $bankAccount->chartAccount->accountClassGroup->accountClass->name ?? 'N/A';
            })
            ->addColumn('account_group', function (BankAccount $bankAccount) {
                return $bankAccount->chartAccount->accountClassGroup->name ?? 'N/A';
            })
            ->addColumn('balance_display', function (BankAccount $bankAccount) {
                $balance = $bankAccount->balance;
                $formatted = number_format($balance, 2);
                if ($balance >= 0) {
                    return '<span class="text-success fw-bold">' . $formatted . '</span>';
                }
                return '<span class="text-danger fw-bold">' . $formatted . '</span>';
            })
            ->editColumn('created_at', function (BankAccount $bankAccount) {
                return optional($bankAccount->created_at)->format('M d, Y');
            })
            ->addColumn('actions', function (BankAccount $bankAccount) {
                $encodedId = Hashids::encode($bankAccount->id);
                return view('bank-accounts._actions', compact('bankAccount', 'encodedId'))->render();
            })
            ->rawColumns(['balance_display', 'actions'])
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $user = Auth::user();
        $chartAccounts = ChartAccount::with('accountClassGroup.accountClass')
            ->whereHas('accountClassGroup.accountClass', function ($q) {
                $q->whereIn('name', ['Assets', 'Equity']);
            })
            ->get();

        $branches = Branch::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get();

        return view('bank-accounts.create', compact('chartAccounts', 'branches'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'chart_account_id' => 'required|exists:chart_accounts,id',
            'name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255|unique:bank_accounts,account_number',
            'branch_scope' => 'required|in:all,specific',
            'branch_id' => 'nullable|required_if:branch_scope,specific|exists:branches,id',
        ]);

        // Branch scoping
        $data['is_all_branches'] = $data['branch_scope'] === 'all';
        $data['branch_id'] = $data['is_all_branches'] ? null : $data['branch_id'];

        BankAccount::create([
            'chart_account_id' => $data['chart_account_id'],
            'name' => $data['name'],
            'account_number' => $data['account_number'],
            'branch_id' => $data['branch_id'],
            'is_all_branches' => $data['is_all_branches'],
        ]);

        return redirect()->route('accounting.bank-accounts')
            ->with('success', 'Bank account created successfully!');
    }

    /**
     * Display the specified resource.
     */
    public function show($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.bank-accounts')->withErrors(['Bank account not found.']);
        }

        $bankAccount = BankAccount::findOrFail($decoded[0]);
        $bankAccount->load('chartAccount.accountClassGroup.accountClass');

        return view('bank-accounts.show', compact('bankAccount'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.bank-accounts')->withErrors(['Bank account not found.']);
        }

        $user = Auth::user();
        $bankAccount = BankAccount::findOrFail($decoded[0]);

        $chartAccounts = ChartAccount::with('accountClassGroup.accountClass')
            ->orderBy('account_name')
            ->get();

        $branches = Branch::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get();

        return view('bank-accounts.edit', compact('bankAccount', 'chartAccounts', 'branches'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.bank-accounts')->withErrors(['Bank account not found.']);
        }

        $bankAccount = BankAccount::findOrFail($decoded[0]);

        $data = $request->validate([
            'chart_account_id' => 'required|exists:chart_accounts,id',
            'name' => 'required|string|max:255',
            'account_number' => 'required|string|max:255|unique:bank_accounts,account_number,' . $bankAccount->id,
            'branch_scope' => 'required|in:all,specific',
            'branch_id' => 'nullable|required_if:branch_scope,specific|exists:branches,id',
        ]);

        // Branch scoping
        $data['is_all_branches'] = $data['branch_scope'] === 'all';
        $data['branch_id'] = $data['is_all_branches'] ? null : $data['branch_id'];

        $bankAccount->update([
            'chart_account_id' => $data['chart_account_id'],
            'name' => $data['name'],
            'account_number' => $data['account_number'],
            'branch_id' => $data['branch_id'],
            'is_all_branches' => $data['is_all_branches'],
        ]);

        return redirect()->route('accounting.bank-accounts')
            ->with('success', 'Bank account updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.bank-accounts')->withErrors(['Bank account not found.']);
        }

        $bankAccount = BankAccount::findOrFail($decoded[0]);

        // Prevent delete if used in GL Transactions
        $hasGlTransactions = $bankAccount->glTransactions()->exists();
        if ($hasGlTransactions) {
            return redirect()->route('accounting.bank-accounts')->withErrors(['This bank account cannot be deleted because its chart account is used in GL Transactions.']);
        }

        try {
            $bankAccount->delete();
            return redirect()->route('accounting.bank-accounts')->with('success', 'Bank account deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete bank account. Please try again.');
        }
    }
}