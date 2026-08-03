<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesDcbRequestInput;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashCollateral;
use App\Models\Customer;
use App\Models\Fee;
use App\Models\Filetype;
use App\Models\GlTransaction;
use App\Models\Group;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanFile;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\ChartAccount;
use App\Models\Payment;
use App\Models\PaymentItem;
use App\Models\Penalty;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use App\Models\Region;
use App\Models\District;
use Illuminate\Http\Request;
use App\Services\LoanDisbursementCompletionService;
use App\Services\LoanDisbursementGlService;
use App\Services\LoanDeletionService;
use App\Services\LoanRestructuringService;
use App\Jobs\BulkLoanImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Jobs\BulkLoanCreationJob;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\FailedLoanImportExport;
use App\Exports\LoanImportTemplateExport;
use App\Exports\OpeningBalanceTemplateExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class LoanController extends Controller
{
    use NormalizesDcbRequestInput;

    private const GUARANTOR_RELATIONS = [
        'Father',
        'Mother',
        'Sister',
        'In Laws',
        'Brother',
        'Son',
        'Daughter',
        'Friend',
        'Neighbor',
        'Colleague',
        'Other Relative',
        'Other',
    ];

    private function applyGuarantorCategoryFilter($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw('LOWER(category) = ?', ['guarantor'])
                ->orWhereRaw('LOWER(category) = ?', ['guarantory'])
                ->orWhereRaw('LOWER(category) = ?', ['guaranty'])
                ->orWhereRaw('LOWER(category) like ?', ['guarantor%'])
                ->orWhereRaw('LOWER(category) like ?', ['guarant%']);
        });
    }

    private function buildLoanFeesData(Loan $loan): array
    {
        $loanFees = collect();
        $totalConfiguredFees = 0;
        $deductedReleaseFees = collect();
        $totalDeductedReleaseFees = 0.0;

        if ($loan->product && $loan->product->fees_ids) {
            $feeIds = is_array($loan->product->fees_ids)
                ? $loan->product->fees_ids
                : json_decode($loan->product->fees_ids, true);

            if (is_array($feeIds) && !empty($feeIds)) {
                $loanFees = Fee::whereIn('id', $feeIds)->get()->map(function ($fee) use ($loan) {
                    $fee->calculated_amount = $fee->monetaryAmountForPrincipal((float) $loan->amount, $loan->custom_fee_amounts);
                    return $fee;
                });

                $totalConfiguredFees = $loanFees->sum('calculated_amount');

                $releaseFeeModels = $loanFees
                    ->filter(fn ($fee) => ($fee->deduction_criteria ?? '') === 'charge_fee_on_release_date'
                        && strtolower((string) ($fee->status ?? 'active')) === 'active')
                    ->values();

                $releaseFeeChartIds = $releaseFeeModels
                    ->pluck('chart_account_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $feeIncomeByChart = [];
                if (! empty($releaseFeeChartIds)) {
                    $feeIncomeByChart = GlTransaction::query()
                        ->where('transaction_id', $loan->id)
                        ->where('transaction_type', LoanDisbursementGlService::TRANSACTION_TYPE)
                        ->where('nature', 'credit')
                        ->whereIn('chart_account_id', $releaseFeeChartIds)
                        ->selectRaw('chart_account_id, SUM(amount) as total')
                        ->groupBy('chart_account_id')
                        ->pluck('total', 'chart_account_id')
                        ->map(fn ($v) => (float) $v)
                        ->all();
                }

                $cashDisbursed = (float) GlTransaction::query()
                    ->where('transaction_id', $loan->id)
                    ->where('transaction_type', LoanDisbursementGlService::TRANSACTION_TYPE)
                    ->where('nature', 'credit')
                    ->when(! empty($releaseFeeChartIds), function ($q) use ($releaseFeeChartIds) {
                        $q->whereNotIn('chart_account_id', $releaseFeeChartIds);
                    })
                    ->sum('amount');

                $calculatedReleaseTotal = round((float) $releaseFeeModels->sum('calculated_amount'), 2);
                $feesWereDeducted = ! empty($feeIncomeByChart)
                    || (
                        $cashDisbursed > 0
                        && $calculatedReleaseTotal > 0.009
                        && $cashDisbursed < ((float) $loan->amount - 0.009)
                    );

                $deductedReleaseFees = $feesWereDeducted
                    ? $releaseFeeModels->map(function ($fee) use ($feeIncomeByChart) {
                        $amount = round((float) ($fee->calculated_amount ?? 0), 2);
                        $glDeducted = isset($fee->chart_account_id)
                            ? round((float) ($feeIncomeByChart[(int) $fee->chart_account_id] ?? 0), 2)
                            : 0.0;
                        $deductedAmount = $glDeducted > 0.009 ? $glDeducted : $amount;

                        return (object) [
                            'id' => $fee->id,
                            'name' => $fee->name,
                            'fee_type' => $fee->fee_type,
                            'deduction_criteria' => $fee->deduction_criteria,
                            'configured_amount' => (float) ($fee->amount ?? 0),
                            'calculated_amount' => $amount,
                            'deducted_amount' => $deductedAmount,
                            'posted_in_gl' => $glDeducted > 0.009,
                        ];
                    })
                        ->filter(fn ($fee) => (float) $fee->deducted_amount > 0.009)
                        ->values()
                    : collect();

                $totalDeductedReleaseFees = round((float) $deductedReleaseFees->sum('deducted_amount'), 2);
            }
        }

        $feesPaidFromRepayments = (float) $loan->repayments->sum('fee_amount');
        $feesPaidFromReceipts = 0;
        $feePaymentTransactions = collect();

        foreach ($loan->repayments as $repayment) {
            if ((float) $repayment->fee_amount > 0) {
                $feePaymentTransactions->push((object) [
                    'payment_date' => $repayment->payment_date ?? $repayment->created_at,
                    'source' => 'Repayment',
                    'reference' => 'Repayment #' . $repayment->id,
                    'fee_name' => 'Schedule Fee',
                    'amount' => (float) $repayment->fee_amount,
                ]);
            }
        }

        $configuredFeeIds = $loanFees->pluck('id')->filter()->map(fn($id) => (int) $id)->all();
        $configuredFeeChartAccountIds = $loanFees->pluck('chart_account_id')->filter()->map(fn($id) => (int) $id)->all();

        $receipts = $loan->receipts()->with('receiptItems.fee')->get();
        foreach ($receipts as $receipt) {
            foreach ($receipt->receiptItems as $item) {
                $itemFeeId = $item->fee_id ? (int) $item->fee_id : null;
                $itemChartAccountId = $item->chart_account_id ? (int) $item->chart_account_id : null;

                $isConfiguredFeePayment = ($itemFeeId && in_array($itemFeeId, $configuredFeeIds, true))
                    || (!$itemFeeId && $itemChartAccountId && in_array($itemChartAccountId, $configuredFeeChartAccountIds, true));

                if (!$isConfiguredFeePayment) {
                    continue;
                }

                $amount = (float) $item->amount;
                $feesPaidFromReceipts += $amount;
                $feePaymentTransactions->push((object) [
                    'payment_date' => $receipt->date ?? $receipt->created_at,
                    'source' => 'Receipt',
                    'reference' => 'Receipt #' . ($receipt->receipt_no ?? $receipt->id),
                    'fee_name' => $item->fee->name ?? 'Fee',
                    'amount' => $amount,
                ]);
            }
        }

        foreach ($deductedReleaseFees as $deductedFee) {
            $feePaymentTransactions->push((object) [
                'payment_date' => $loan->disbursed_on ?? $loan->date_applied ?? $loan->created_at,
                'source' => 'Release Deduction',
                'reference' => 'Loan Disbursement #' . $loan->id,
                'fee_name' => $deductedFee->name,
                'amount' => (float) $deductedFee->deducted_amount,
            ]);
        }

        $totalFeesPaid = $feesPaidFromRepayments + $feesPaidFromReceipts + $totalDeductedReleaseFees;
        $remainingFees = max(0, $totalConfiguredFees - $totalFeesPaid);
        $netDisbursed = round(max(0, (float) $loan->amount - $totalDeductedReleaseFees), 2);

        return [
            'loanFees' => $loanFees,
            'totalConfiguredFees' => round((float) $totalConfiguredFees, 2),
            'feesPaidFromRepayments' => round((float) $feesPaidFromRepayments, 2),
            'feesPaidFromReceipts' => round((float) $feesPaidFromReceipts, 2),
            'deductedReleaseFees' => $deductedReleaseFees,
            'totalDeductedReleaseFees' => $totalDeductedReleaseFees,
            'netDisbursedAmount' => $netDisbursed,
            'totalFeesPaid' => round((float) $totalFeesPaid, 2),
            'remainingFees' => round((float) $remainingFees, 2),
            'feePaymentTransactions' => $feePaymentTransactions->sortByDesc(function ($item) {
                return $item->payment_date;
            })->values(),
        ];
    }

    /**
     * Show Loan Fees Receipt
     */
    public function feesReceipt($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $loan = Loan::with('customer', 'product')->find($decoded[0]);
        if (!$loan) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        // Get configured active fees for this loan product
        $fees = [];
        $totalFees = 0;
        if ($loan->product && $loan->product->fees_ids) {
            $feeIds = is_array($loan->product->fees_ids) ? $loan->product->fees_ids : json_decode($loan->product->fees_ids, true);
            if (is_array($feeIds)) {
                $configuredFees = \DB::table('fees')
                    ->whereIn('id', $feeIds)
                    ->where('status', 'active')
                    ->get();
                foreach ($configuredFees as $feeRow) {
                    $feeModel = Fee::find($feeRow->id);
                    $calculated = $feeModel
                        ? $feeModel->monetaryAmountForPrincipal((float) $loan->amount, $loan->custom_fee_amounts)
                        : 0;
                    $fees[] = (object) [
                        'id' => $feeRow->id,
                        'name' => $feeRow->name,
                        'fee_type' => $feeRow->fee_type,
                        'chart_account_id' => $feeRow->chart_account_id,
                        'deduction_criteria' => $feeRow->deduction_criteria,
                        'calculated_amount' => round($calculated, 2),
                    ];
                    $totalFees += $calculated;
                }
            }
        }

        // Fetch required data for the receipt form
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $customers = Customer::all();

        // Fee payment summary (per configured fee), with fallback for legacy rows that have no fee_id.
        $receiptItemsForLoan = \App\Models\ReceiptItem::query()
            ->whereHas('receipt', function ($q) use ($loan) {
                $q->where('reference_type', 'loan')
                    ->where(function ($sub) use ($loan) {
                        $sub->where('reference', 'LOAN-' . $loan->id)
                            ->orWhere('reference', (string) $loan->id)
                            ->orWhere('reference', $loan->id);
                    });
            })
            ->get(['fee_id', 'chart_account_id', 'amount']);

        $fees = collect($fees)->map(function ($fee) use ($receiptItemsForLoan) {
            $paid = (float) $receiptItemsForLoan->sum(function ($item) use ($fee) {
                if (!empty($item->fee_id) && (int) $item->fee_id === (int) $fee->id) {
                    return (float) $item->amount;
                }
                // Legacy fallback: match by chart account when fee_id was not captured.
                if (empty($item->fee_id) && !empty($fee->chart_account_id) && (int) $item->chart_account_id === (int) $fee->chart_account_id) {
                    return (float) $item->amount;
                }
                return 0;
            });
            $fee->paid_amount = round($paid, 2);
            $fee->remaining_amount = round(max(0, (float) $fee->calculated_amount - $paid), 2);
            $fee->payment_status = $fee->remaining_amount <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
            return $fee;
        });

        $totalPaidFees = (float) $fees->sum('paid_amount');
        $totalRemainingFees = (float) $fees->sum('remaining_amount');

        return view('loans.fees_receipt', compact('loan', 'fees', 'totalFees', 'bankAccounts', 'customers', 'totalPaidFees', 'totalRemainingFees'));
    }

    /**
     * Store Loan Fees Receipt
     */
    public function storeReceipt(Request $request, $encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $loan = Loan::find($decoded[0]);
        if (!$loan) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payee_type' => 'required|string',
            'customer_id' => 'nullable|exists:customers,id',
            'payee_name' => 'nullable|string',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:2048',
            'line_items' => 'required|array|min:1',
            'line_items.*.fee_id' => 'required|exists:fees,id',
            'line_items.*.amount' => 'required|numeric|min:0.01',
            'line_items.*.description' => 'nullable|string',
        ]);

        // Handle file upload
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('receipts', 'public');
        }

        DB::beginTransaction();
        try {
            $configuredFeeIds = [];
            if ($loan->product && $loan->product->fees_ids) {
                $configuredFeeIds = is_array($loan->product->fees_ids)
                    ? $loan->product->fees_ids
                    : (json_decode($loan->product->fees_ids, true) ?: []);
            }

            // Create receipt
            $receipt = new \App\Models\Receipt();
            $receipt->reference = 'LOAN-' . $loan->id;
            $receipt->reference_type = 'loan';
            $receipt->reference_number = $loan->loanNo ?? $loan->id;
            $receipt->date = $validated['date'];
            $receipt->bank_account_id = $validated['bank_account_id'];
            $receipt->payee_type = $validated['payee_type'];
            $receipt->payee_id = $validated['customer_id'] ?? null;
            $receipt->payee_name = $validated['payee_name'] ?? null;
            $receipt->description = $validated['description'] ?? null;
            $receipt->attachment = $attachmentPath;
            $receipt->user_id = auth()->id();
            $receipt->branch_id = $loan->branch_id;
            $receipt->save();

            // Save receipt items
            foreach ($validated['line_items'] as $item) {
                $fee = Fee::findOrFail($item['fee_id']);
                if (!in_array($fee->id, $configuredFeeIds)) {
                    throw new \Exception("Fee '{$fee->name}' is not configured for this loan product.");
                }
                if (!$fee->chart_account_id) {
                    throw new \Exception("Fee '{$fee->name}' has no chart account configured.");
                }
                $receiptItem = new \App\Models\ReceiptItem();
                $receiptItem->receipt_id = $receipt->id;
                $receiptItem->fee_id = $fee->id;
                $receiptItem->chart_account_id = $fee->chart_account_id;
                $receiptItem->amount = $item['amount'];
                $receiptItem->description = $item['description'] ?? ('Fee payment: ' . $fee->name);
                $receiptItem->save();
            }
            // GL Transactions
            // Debit Bank Account (total amount)
            $bankAccount = BankAccount::find($validated['bank_account_id']);
            $branchId = $loan->branch_id;
            $customerId = $loan->customer_id;
            $userId = auth()->id();
            $totalAmount = collect($validated['line_items'])->sum('amount');
            GlTransaction::create([
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $customerId,
                'amount' => $totalAmount,
                'nature' => 'debit',
                'transaction_id' => $receipt->id,
                'transaction_type' => 'receipt',
                'date' => $validated['date'],
                'description' => 'Loan Fees Receipt for Loan #' . ($loan->loanNo ?? $loan->id),
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]);

            // Credit each chart account in line items
            foreach ($validated['line_items'] as $item) {
                $fee = Fee::findOrFail($item['fee_id']);
                GlTransaction::create([
                    'chart_account_id' => $fee->chart_account_id,
                    'customer_id' => $customerId,
                    'amount' => $item['amount'],
                    'nature' => 'credit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $validated['date'],
                    'description' => $item['description'] ?? ('Loan Fee: ' . $fee->name . ' for Loan #' . ($loan->loanNo ?? $loan->id)),
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);
            }

            DB::commit();
            return redirect()->route('loans.list')->with('success', 'Receipt created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Failed to create receipt: ' . $e->getMessage()]);
        }
    }
    // Ajax endpoint for DataTables: Written Off Loans

    public function getWrittenOffLoansData(Request $request)
    {
        if ($request->ajax()) {
            $loans = Loan::with(['customer', 'product', 'branch'])
                ->where('status', 'written_off')
                ->select('loans.*');

            return DataTables::eloquent($loans)
                ->addColumn('loan_no', function ($loan) {
                    return $loan->loanNo ?? $loan->id;
                })
                ->addColumn('customer_name', function ($loan) {
                    return optional($loan->customer)->name ?? 'N/A';
                })
                ->addColumn('product_name', function ($loan) {
                    return optional($loan->product)->name ?? 'N/A';
                })
                ->addColumn('formatted_amount', function ($loan) {
                    return '' . number_format($loan->amount, 2);
                })
                ->addColumn('formatted_total', function ($loan) {
                    return '' . number_format($loan->amount_total, 2);
                })
                ->addColumn('branch_name', function ($loan) {
                    return optional($loan->branch)->name ?? 'N/A';
                })
                ->addColumn('date_applied', function ($loan) {
                    return $loan->date_applied;
                })
                ->addColumn('actions', function ($loan) {
                    $encodedId = \Vinkla\Hashids\Facades\Hashids::encode($loan->id);

                    if (auth()->user()->can('create receipt voucher')) {
                        return '<a href="' . route('accounting.loans.create-receipt', $encodedId) . '" class="btn btn-sm btn-outline-success" title="Add Receipt"><i class="bx bx-receipt"></i></a>';
                    }

                    return '<span class="text-muted">-</span>';
                })
                ->rawColumns(['customer_name', 'actions'])
                ->make(true);
        }
    }

    public function index()
    {
        $user = auth()->user();
        $branchId = $user->branch_id;

        $stats = [
            'active' => Loan::where('branch_id', $branchId)->where('status', 'active')->count(),
            'applied' => Loan::where('branch_id', $branchId)->where('status', 'applied')->count(),
            'checked' => Loan::where('branch_id', $branchId)->where('status', 'checked')->count(),
            'approved' => Loan::where('branch_id', $branchId)->where('status', 'approved')->count(),
            'authorized' => Loan::where('branch_id', $branchId)->where('status', 'authorized')->count(),
            'defaulted' => Loan::where('branch_id', $branchId)->where('status', 'defaulted')->count(),
            'rejected' => Loan::where('branch_id', $branchId)->where('status', 'rejected')->count(),
            'written_off' => Loan::where('branch_id', $branchId)->where('status', 'written_off')->count(),
            'completed' => Loan::where('branch_id', $branchId)->where('status', 'completed')->count(),
            'restructured' => Loan::where('branch_id', $branchId)->where('status', 'restructured')->count(),
        ];

        // Data for opening balance modal
        $products = LoanProduct::where('is_active', true)->get();
        $branches = \App\Models\Branch::where('status', 'active')->get();
        // $chartAccounts = ChartAccount::with(['accountClassGroup.accountClass'])
        //     ->whereHas('accountClassGroup.accountClass', function ($query) {
        //         $query->where('name', 'LIKE', '%Equity%');
        //     })
        //     ->get();
        $chartAccounts = ChartAccount::all();

        return view('loans.index', compact('stats', 'products', 'branches', 'chartAccounts'));
    }

    public function listLoans()
    {
        $branchId = auth()->user()->branch_id;
        $loans = Loan::with('customer', 'product', 'branch')
            ->where('branch_id', $branchId)
            ->where('status', 'active')
            ->latest()->get();

        // Get data for import modal and repayment modal
        $branches = Branch::all();
        $loanProducts = LoanProduct::all();
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $cashDeposits = \App\Models\CashCollateral::with(['customer', 'type'])->where('amount', '>', 0)->get();

        return view('loans.list', compact('loans', 'branches', 'loanProducts', 'bankAccounts', 'cashDeposits'));
    }

    // Ajax endpoint for DataTables
    public function getLoansData(Request $request)
    {
        if ($request->ajax()) {
            $branchId = auth()->user()->branch_id;
            $status = $request->get('status', 'active'); // Default to active loans

            // Optimize: Select only needed columns and limit eager loading
            $loans = Loan::with([
                'customer:id,name,customerNo',
                'product:id,name',
                'branch:id,name',
                'group:id,name',
                'loanOfficer:id,name',
                // Only load latest approval for comment column
                'approvals' => function ($query) {
                    $query->select('id', 'loan_id', 'comments', 'approved_at')
                        ->orderBy('approved_at', 'desc')
                        ->limit(1);
                }
            ])
                ->where('branch_id', $branchId)
                ->where('status', $status)
                ->select(
                    'loans.id',
                    'loans.customer_id',
                    'loans.product_id',
                    'loans.branch_id',
                    'loans.group_id',
                    'loans.loan_officer_id',
                    'loans.bank_account_id',
                    'loans.disbursed_on',
                    'loans.loanNo',
                    'loans.amount',
                    'loans.interest',
                    'loans.amount_total',
                    'loans.period',
                    'loans.status',
                    'loans.date_applied',
                    'loans.created_at',
                    'loans.updated_at'
                );


            return DataTables::eloquent($loans)
                ->addColumn('customer_name', function ($loan) {
                    $customerName = optional($loan->customer)->name ?? 'N/A';
                    $initial = strtoupper(substr($customerName, 0, 1));

                    return '<div class="d-flex align-items-center">
                            <div class="avatar avatar-sm bg-primary rounded-circle me-2 d-flex align-items-center justify-content-center shadow" style="width:36px; height:36px;">
                                <span class="avatar-title text-white fw-bold" style="font-size:1.25rem;">' . $initial . '</span>
                            </div>
                            <div>
                                <div class="fw-bold">' . e($customerName) . '</div>
                            </div>
                        </div>';
                })
                ->addColumn('product_name', function ($loan) {
                    return optional($loan->product)->name ?? 'N/A';
                })
                ->addColumn('formatted_amount', function ($loan) {
                    return '' . number_format($loan->amount, 2);
                })
                ->addColumn('formatted_total', function ($loan) {
                    return '' . number_format($loan->amount_total, 2);
                })
                ->addColumn('interest_display', function ($loan) {
                    return round($loan->interest, 2) . '%';
                })
                ->addColumn('status_badge', function ($loan) {
                    $badgeClass = '';
                    $statusText = ucfirst($loan->status);

                    switch ($loan->status) {
                        case 'applied':
                            $badgeClass = 'bg-warning';
                            $statusText = 'Applied';
                            break;
                        case 'checked':
                            $badgeClass = 'bg-info';
                            $statusText = 'Checked';
                            break;
                        case 'approved':
                            $badgeClass = 'bg-primary';
                            $statusText = 'Approved';
                            break;
                        case 'authorized':
                            $badgeClass = 'bg-success';
                            $statusText = 'Authorized';
                            break;
                        case 'active':
                            $badgeClass = 'bg-success';
                            $statusText = 'Active';
                            break;
                        case 'defaulted':
                            $badgeClass = 'bg-danger';
                            $statusText = 'Defaulted';
                            break;
                        case 'rejected':
                            $badgeClass = 'bg-danger';
                            $statusText = 'Rejected';
                            break;
                        case 'completed':
                            $badgeClass = 'bg-success';
                            $statusText = 'Completed';
                            break;
                        case 'restructured':
                            $badgeClass = 'bg-info';
                            $statusText = 'Restructured';
                            break;
                        default:
                            $badgeClass = 'bg-secondary';
                            break;
                    }

                    return '<span class="badge ' . $badgeClass . '">' . $statusText . '</span>';
                })
                ->addColumn('branch_name', function ($loan) {
                    return optional($loan->branch)->name ?? 'N/A';
                })
                ->addColumn('formatted_date', function ($loan) {
                    return $loan->date_applied ? \Carbon\Carbon::parse($loan->date_applied)->format('M d, Y') : 'N/A';
                })
                ->addColumn('comment', function ($loan) {
                    // Don't show comment for active loans
                    if ($loan->status === 'active') {
                        return '<span class="text-muted">-</span>';
                    }

                    // Use the already loaded latest approval (optimized query)
                    $latestApproval = $loan->approvals->first();
                    if ($latestApproval && $latestApproval->comments) {
                        return '<div class="text-truncate" style="max-width: 200px;" title="' . e($latestApproval->comments) . '">
                                    <small class="text-muted">' . e($latestApproval->comments) . '</small>
                                </div>';
                    }
                    return '<span class="text-muted">-</span>';
                })
                ->addColumn('actions', function ($loan) {
                    $actions = '';
                    $encodedId = \Vinkla\Hashids\Facades\Hashids::encode($loan->id);

                    // View action
                    if (auth()->user()->can('view loan details')) {
                        $actions .= '<a href="' . route('loans.show', $encodedId) . '" class="btn btn-sm btn-outline-info me-1" title="View"><i class="bx bx-show"></i></a>';
                    }

                    // Edit action — application pipeline or direct loans (not disbursed)
                    if ($loan->canBeEdited(auth()->user())) {
                        $editUrl = $loan->usesApplicationEditForm()
                            ? route('loans.application.edit', $encodedId)
                            : route('loans.edit', $encodedId);
                        $actions .= '<a href="' . $editUrl . '" class="btn btn-sm btn-outline-primary me-1" title="Edit"><i class="bx bx-edit"></i></a>';

                        if ($loan->status === Loan::STATUS_REJECTED && $loan->isApplicationLoan()) {
                            $fixUrl = route('loans.application.edit', $encodedId);
                            $actions .= '<a href="' . $fixUrl . '" class="btn btn-sm btn-outline-success me-1" title="Fix & Re-apply"><i class="bx bx-refresh"></i></a>';
                        }
                    }

                    // Receipt action for applied loans
                    if ($loan->status === 'applied' && auth()->user()->can('create receipt voucher')) {
                        $actions .= '<a href="' . route('accounting.loans.create-receipt', $encodedId) . '" class="btn btn-sm btn-outline-success me-1" title="Create Receipt"><i class="bx bx-receipt"></i></a>';
                    }

                    // Repayment action - only for users allowed to process repayments
                    if (in_array($loan->status, ['active', 'disbursed']) && auth()->user()->can('process loan payments')) {
                        $actions .= '<button class="btn btn-sm btn-outline-success me-1 quick-repayment-btn"'
                            . ' data-loan-id="' . $loan->id . '"'
                            . ' data-customer-name="' . e(optional($loan->customer)->name ?? 'Unknown') . '"'
                            . ' data-loan-no="' . e($loan->loanNo ?? $loan->id) . '"'
                            . ' title="Record Repayment"><i class="bx bx-credit-card"></i></button>';
                    }

                    // Approval action - show for loans that can be approved by current user
                    if (in_array($loan->status, ['applied', 'checked', 'approved', 'authorized'])) {
                        $user = auth()->user();
                        if ($loan->canBeApprovedByUser($user)) {
                            $nextAction = $loan->getNextApprovalAction();
                            $nextLevel = $loan->getNextApprovalLevel();
                            $actionLabel = $loan->getApprovalLevelName($nextLevel);

                            $btnClass = match ($nextAction) {
                                'check' => 'btn-outline-info',
                                'approve' => 'btn-outline-primary',
                                'authorize' => 'btn-outline-success',
                                'disburse' => 'btn-outline-warning',
                                default => 'btn-outline-secondary'
                            };

                            $btnIcon = match ($nextAction) {
                                'check' => 'bx-check',
                                'approve' => 'bx-check-circle',
                                'authorize' => 'bx-check-double',
                                'disburse' => 'bx-money',
                                default => 'bx-check'
                            };

                            $actions .= '<button class="btn btn-sm ' . $btnClass . ' approve-btn me-1" data-id="' . $encodedId . '" data-action="' . $nextAction . '" data-level="' . $nextLevel . '" title="' . ucfirst($actionLabel) . '"><i class="bx ' . $btnIcon . '"></i></button>';
                        }
                    }

                    // Delete action — blocked for disbursed / completed loans
                    if ($loan->canBeDeleted(auth()->user())) {
                        $actions .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $encodedId . '" data-name="' . e(optional($loan->customer)->name ?? 'Unknown') . '" title="Delete"><i class="bx bx-trash"></i></button>';
                    }

                    // // Change status action (available to users who can edit loans)
                    // if (auth()->user()->can('edit loan')) {
                    //     $actions .= '<button class="btn btn-sm btn-outline-secondary change-status-btn me-1" data-id="' . $encodedId . '" title="Change Status"><i class="bx bx-transfer"></i></button>';
                    // }

                    return '<div class="text-center">' . $actions . '</div>';
                })
                ->filterColumn('customer_name', function ($query, $keyword) {
                    $query->whereHas('customer', function ($q) use ($keyword) {
                        $q->whereRaw("LOWER(name) LIKE LOWER(?)", ["%{$keyword}%"]);
                    });
                })
                ->filterColumn('product_name', function ($query, $keyword) {
                    $query->whereHas('product', function ($q) use ($keyword) {
                        $q->whereRaw("LOWER(name) LIKE LOWER(?)", ["%{$keyword}%"]);
                    });
                })
                ->filterColumn('branch_name', function ($query, $keyword) {
                    $query->whereHas('branch', function ($q) use ($keyword) {
                        $q->whereRaw("LOWER(name) LIKE LOWER(?)", ["%{$keyword}%"]);
                    });
                })
                ->filterColumn('formatted_amount', function ($query, $keyword) {
                    $query->whereRaw("LOWER(amount) LIKE LOWER(?)", ["%{$keyword}%"]);
                })
                ->filterColumn('formatted_total', function ($query, $keyword) {
                    $query->whereRaw("LOWER(amount_total) LIKE LOWER(?)", ["%{$keyword}%"]);
                })
                ->filterColumn('interest_display', function ($query, $keyword) {
                    $query->whereRaw("LOWER(interest) LIKE LOWER(?)", ["%{$keyword}%"]);
                })
                ->filterColumn('period', function ($query, $keyword) {
                    $query->whereRaw("LOWER(period) LIKE LOWER(?)", ["%{$keyword}%"]);
                })
                ->filterColumn('status_badge', function ($query, $keyword) {
                    $query->whereRaw("LOWER(status) LIKE LOWER(?)", ["%{$keyword}%"]);
                })
                ->filterColumn('formatted_date', function ($query, $keyword) {
                    $query->whereRaw("LOWER(date_applied) LIKE LOWER(?)", ["%{$keyword}%"]);
                })
                ->rawColumns(['customer_name', 'status_badge', 'comment', 'actions'])
                ->make(true);
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }

    // Get chart accounts by loan type
    public function getChartAccountsByType($type)
    {
        try {
            if ($type === 'new') {
                // For new loans, get bank accounts linked to cash and bank chart accounts (assets)
                $accounts = BankAccount::whereHas('chartAccount.accountClassGroup', function ($query) {
                    $query->where('name', 'LIKE', '%cash%')
                        ->orWhere('name', 'LIKE', '%bank%')
                        ->orWhere('name', 'LIKE', '%Cash%')
                        ->orWhere('name', 'LIKE', '%Bank%')
                        ->orWhere('name', 'LIKE', '%Asset%')
                        ->orWhere('name', 'LIKE', '%asset%');
                })
                    ->forUserBranches()
                    ->with('chartAccount')
                    ->select('id', 'name', 'account_number')
                    ->orderBy('name')
                    ->get()
                    ->map(function ($account) {
                        return [
                            'id' => $account->id,
                            'name' => $account->name,
                            'account_number' => $account->account_number,
                            'chart_account' => $account->chartAccount ? $account->chartAccount->account_name : ''
                        ];
                    });

                return response()->json([
                    'success' => true,
                    'accounts' => $accounts,
                    'type' => 'Bank Accounts (Cash & Bank)'
                ]);
            } elseif ($type === 'old') {
                // For old loans, get bank accounts linked to equity chart accounts
                $accounts = BankAccount::whereHas('chartAccount.accountClassGroup', function ($query) {
                    $query->where('name', 'LIKE', '%equity%')
                        ->orWhere('name', 'LIKE', '%Equity%')
                        ->orWhere('name', 'LIKE', '%Retained Earnings%')
                        ->orWhere('name', 'LIKE', '%Business Capital%')
                        ->orWhere('name', 'LIKE', '%Capital%');
                })
                    ->forUserBranches()
                    ->with('chartAccount')
                    ->select('id', 'name', 'account_number')
                    ->orderBy('name')
                    ->get()
                    ->map(function ($account) {
                        return [
                            'id' => $account->id,
                            'name' => $account->name,
                            'account_number' => $account->account_number,
                            'chart_account' => $account->chartAccount ? $account->chartAccount->account_name : ''
                        ];
                    });

                return response()->json([
                    'success' => true,
                    'accounts' => $accounts,
                    'type' => 'Bank Accounts (Equity)'
                ]);
            }

            return response()->json(['success' => false, 'message' => 'Invalid loan type']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching accounts: ' . $e->getMessage()
            ]);
        }
    }

    public function importLoans(Request $request)
    {
        $request->validate([
            'import_file' => 'required|file|mimes:csv,txt,xlsx,xls',
            'loan_type' => 'required|in:new,old',
            'branch_id' => 'required|exists:branches,id',
            'product_id' => 'required|exists:loan_products,id',
            'account_id' => 'required|exists:bank_accounts,id',
        ]);

        try {
            $file = $request->file('import_file');
            $path = $file->getRealPath();

            // Validate file content exists
            if (!file_exists($path)) {
                return redirect()->back()->withErrors([
                    'import_file' => 'Unable to read the uploaded file.'
                ]);
            }

            $extension = strtolower($file->getClientOriginalExtension());
            $data = [];
            $header = [];

            // Read file based on extension
            if (in_array($extension, ['xlsx', 'xls'])) {
                // Read Excel file
                $spreadsheet = IOFactory::load($path);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();

                if (empty($rows)) {
                    return redirect()->back()->withErrors([
                        'import_file' => 'Excel file is empty.'
                    ]);
                }

                // Find header row (skip instruction rows)
                $headerRowIndex = 0;

                // Look for header row - it should contain at least 'customer_no' and 'amount'
                for ($i = 0; $i < min(20, count($rows)); $i++) {
                    $potentialHeader = array_map(function($cell) {
                        $value = is_null($cell) ? '' : (string)$cell;
                        return strtolower(trim($value));
                    }, $rows[$i]);

                    // Skip rows that are clearly not headers
                    $nonEmptyCells = array_filter($potentialHeader, function($val) {
                        return !empty($val) &&
                               !preg_match('/^(instruction|note|delete|fill|use|template|loan import)/i', $val);
                    });

                    if (count($nonEmptyCells) < 4) {
                        continue;
                    }

                    // Normalize column names
                    $normalizedHeader = array_map(function($col) {
                        $col = strtolower(trim($col));
                        $col = preg_replace('/\s+/', '', $col);
                        $col = preg_replace('/[^a-z0-9_]/', '', $col);

                        $variations = [
                            'customer_no' => ['customerno', 'customer_no', 'customernumber', 'customer_number'],
                            'customer_name' => ['customername', 'customer_name', 'name'],
                            'amount' => ['amount', 'loanamount', 'loan_amount'],
                            'period' => ['period', 'tenure', 'duration'],
                            'interest' => ['interest', 'interestrate', 'interest_rate'],
                            'date_applied' => ['dateapplied', 'date_applied', 'applieddate', 'applicationdate'],
                            'interest_cycle' => ['interestcycle', 'interest_cycle', 'cycle'],
                            'loan_officer' => ['loanofficer', 'loan_officer', 'loanofficer_id', 'loan_officer_id'],
                            'group_id' => ['groupid', 'group_id', 'group'],
                            'sector' => ['sector', 'businesssector'],
                        ];

                        foreach ($variations as $standard => $aliases) {
                            if (in_array($col, $aliases)) {
                                return $standard;
                            }
                        }
                        return $col;
                    }, $potentialHeader);

                    // Check if this row contains required columns
                    if (in_array('customer_no', $normalizedHeader) && in_array('amount', $normalizedHeader)) {
                        $header = $normalizedHeader;
                        $headerRowIndex = $i;
                        break;
                    }
                }

                if (empty($header)) {
                    return redirect()->back()->withErrors([
                        'import_file' => 'Could not find header row. Please ensure the file has columns: customer_no, amount, period, interest, date_applied, interest_cycle, loan_officer, group_id, sector'
                    ]);
                }

                // Remove rows before header and the header row itself
                $rows = array_slice($rows, $headerRowIndex + 1);

                // Convert rows to associative arrays
                foreach ($rows as $row) {
                    $rowData = [];
                    foreach ($header as $index => $headerName) {
                        $rowData[$headerName] = trim($row[$index] ?? '');
                    }
                    if (!empty(array_filter($rowData, function($val) { return $val !== ''; }))) {
                        $data[] = $rowData;
                    }
                }
            } else {
                // Read CSV file
                $csvData = array_map('str_getcsv', file($path));

                // Find header row
                $headerRowIndex = 0;

                for ($i = 0; $i < min(10, count($csvData)); $i++) {
                    $potentialHeader = array_map(function($cell) {
                        return strtolower(trim($cell ?? ''));
                    }, $csvData[$i]);

                    // Normalize column names
                    $normalizedHeader = array_map(function($col) {
                        $col = strtolower(trim($col));
                        $col = preg_replace('/\s+/', '', $col);
                        $variations = [
                            'customer_no' => ['customerno', 'customer_no', 'customernumber'],
                            'customer_name' => ['customername', 'customer_name', 'name'],
                            'amount' => ['amount', 'loanamount'],
                            'period' => ['period', 'tenure'],
                            'interest' => ['interest', 'interestrate'],
                            'date_applied' => ['dateapplied', 'date_applied'],
                            'interest_cycle' => ['interestcycle', 'interest_cycle'],
                            'loan_officer' => ['loanofficer', 'loan_officer', 'loanofficer_id'],
                            'group_id' => ['groupid', 'group_id'],
                            'sector' => ['sector'],
                        ];

                        foreach ($variations as $standard => $aliases) {
                            if (in_array($col, $aliases)) {
                                return $standard;
                            }
                        }
                        return $col;
                    }, $potentialHeader);

                    if (in_array('customer_no', $normalizedHeader) && in_array('amount', $normalizedHeader)) {
                        $header = $normalizedHeader;
                        $headerRowIndex = $i;
                        break;
                    }
                }

                if (empty($header)) {
                    return redirect()->back()->withErrors([
                        'import_file' => 'Could not find header row. Please ensure the file has columns: customer_no, amount, period, interest, date_applied, interest_cycle, loan_officer, group_id, sector'
                    ]);
                }

                // Remove rows before header and the header row itself
                $csvData = array_slice($csvData, $headerRowIndex + 1);

                // Convert rows to associative arrays
                foreach ($csvData as $row) {
                    if (count($row) >= count($header)) {
                        $rowData = [];
                        foreach ($header as $index => $headerName) {
                            $rowData[$headerName] = trim($row[$index] ?? '');
                        }
                        if (!empty(array_filter($rowData, function($val) { return $val !== ''; }))) {
                            $data[] = $rowData;
                        }
                    }
                }
            }

            if (empty($data)) {
                return redirect()->back()->withErrors([
                    'import_file' => 'No data rows found in the file after header.'
                ]);
            }

            // Validate file structure
            $requiredColumns = ['customer_no', 'amount', 'period', 'interest', 'date_applied', 'interest_cycle', 'loan_officer', 'group_id', 'sector'];
            $missingColumns = array_diff($requiredColumns, $header);

            if (!empty($missingColumns)) {
                $foundColumns = implode(', ', array_keys(array_intersect_key($header, array_flip($requiredColumns))));
                $allFoundColumns = implode(', ', array_keys($header));
                return redirect()->back()->withErrors([
                    'import_file' => 'Missing required columns: ' . implode(', ', $missingColumns) .
                    '. Found columns: ' . ($allFoundColumns ?: 'none') .
                    '. Please ensure your file has the correct header row.'
                ]);
            }

            $product = LoanProduct::with('principalReceivableAccount')->findOrFail($request->product_id);
            $userId = auth()->id();
            $branchId = $request->branch_id;

            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            $errors = [];
            $failedRecords = []; // Store failed records with full data

            // Create unique import ID for progress tracking
            $importId = 'import_' . $userId . '_' . time();
            $totalRows = count($data);

            // Initialize progress tracking
            Cache::put($importId, [
                'status' => 'processing',
                'current' => 0,
                'total' => $totalRows,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'percentage' => 0
            ], 600); // 10 minutes expiry

            // Add debugging
            \Log::info('Import started', [
                'total_rows' => $totalRows,
                'product_id' => $request->product_id,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'skip_errors' => $request->has('skip_errors'),
                'import_id' => $importId
            ]);

            $skipErrors = $request->has('skip_errors');

            // Process data in chunks of 20 synchronously for immediate results
            $chunkSize = 20;
            $chunks = array_chunk($data, $chunkSize);
            $totalChunks = count($chunks);

            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            $errors = [];
            $failedRecords = [];

            // Process each chunk synchronously
            foreach ($chunks as $chunkIndex => $chunk) {
                $job = new \App\Jobs\BulkLoanImportJob(
                    $chunk,
                    $request->product_id,
                    $request->account_id,
                    $branchId,
                    $userId,
                    $skipErrors,
                    $chunkIndex,
                    $totalChunks,
                    $importId
                );

                try {
                    $job->handle();

                    // Get updated counts from cache
                    $progress = Cache::get($importId, []);
                    $successCount = $progress['success'] ?? 0;
                    $errorCount = $progress['failed'] ?? 0;
                    $skippedCount = $progress['skipped'] ?? 0;
                } catch (\Exception $e) {
                    Log::error('Error processing loan import chunk', [
                        'chunk_index' => $chunkIndex,
                        'error' => $e->getMessage()
                    ]);
                    $errorCount += count($chunk);
                }
            }

            // Update final progress
            Cache::put($importId, [
                'status' => 'completed',
                'current' => $totalRows,
                'total' => $totalRows,
                'success' => $successCount,
                'failed' => $errorCount,
                'skipped' => $skippedCount,
                'percentage' => 100
            ], 600);

            $message = "Import completed. Successfully imported: {$successCount} loans.";
            if ($skippedCount > 0) {
                $message .= " Skipped: {$skippedCount} loans.";
            }
            if ($errorCount > 0) {
                $message .= " Failed: {$errorCount} loans.";
            }

            // Return response
            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'imported' => $successCount,
                    'skipped' => $skippedCount,
                    'failed' => $errorCount,
                    'import_id' => $importId,
                    'status' => 'completed'
                ]);
            }

            return redirect()->back()
                ->with('success', $message)
                ->with('import_id', $importId);
        } catch (\Exception $e) {
            // Update progress to error state
            if (isset($importId)) {
                Cache::put($importId, [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ], 600);
            }

            return redirect()->back()->withErrors([
                'import_file' => 'Error processing import: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Get import progress
     */
    public function getImportProgress(Request $request)
    {
        $importId = $request->get('import_id');

        if (!$importId) {
            return response()->json([
                'error' => 'Import ID is required'
            ], 400);
        }

        $progress = Cache::get($importId);

        if (!$progress) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'Import progress not found'
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Download failed records export
     */
    public function downloadFailedRecords(Request $request, $file)
    {
        $filePath = storage_path('app/exports/' . $file);

        if (!file_exists($filePath)) {
            return redirect()->back()->withErrors(['File not found']);
        }

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    private function validateLoanRow($rowData, $rowNumber)
    {
        try {
            // Check required fields
            $required = ['customer_no', 'amount', 'period', 'interest', 'date_applied', 'interest_cycle', 'loan_officer', 'group_id', 'sector'];
            foreach ($required as $field) {
                if (empty($rowData[$field])) {
                    return ['error' => "Row $rowNumber: Missing required field '$field'"];
                }
            }

            // Validate customer number exists
            $customer = Customer::where('customerNo', $rowData['customer_no'])->first();
            if (!$customer) {
                return ['error' => "Row $rowNumber: Customer number '{$rowData['customer_no']}' not found"];
            }

            if (!is_numeric($rowData['amount']) || $rowData['amount'] <= 0) {
                return ['error' => "Row $rowNumber: Invalid amount"];
            }

            if (!is_numeric($rowData['period']) || $rowData['period'] <= 0) {
                return ['error' => "Row $rowNumber: Invalid period"];
            }

            if (!is_numeric($rowData['interest']) || $rowData['interest'] < 0) {
                return ['error' => "Row $rowNumber: Invalid interest"];
            }

            // Parse date_applied: accept YYYY-MM-DD or Excel serial numbers
            $dateValue = $rowData['date_applied'];
            $parsedDate = null;
            if (is_numeric($dateValue)) {
                try {
                    $carbon = \Carbon\Carbon::instance(ExcelDate::excelToDateTimeObject((float) $dateValue));
                    $parsedDate = $carbon->format('Y-m-d');
                } catch (\Throwable $t) {
                    return ['error' => "Row $rowNumber: Invalid date_applied (Excel serial)"];
                }
            } else {
                try {
                    $carbon = \Carbon\Carbon::createFromFormat('Y-m-d', (string) $dateValue);
                    $parsedDate = $carbon->format('Y-m-d');
                } catch (\Throwable $t) {
                    return ['error' => "Row $rowNumber: Invalid date_applied (expected YYYY-MM-DD)"];
                }
            }
            if (strtotime($parsedDate) > time()) {
                return ['error' => "Row $rowNumber: Invalid date_applied (future date)"];
            }

            $validCycles = ['daily', 'weekly', 'monthly', 'quarterly', 'semi_annually', 'annually'];
            if (!in_array(strtolower($rowData['interest_cycle']), $validCycles, true)) {
                return ['error' => "Row $rowNumber: Invalid interest_cycle"];
            }

            if (!is_numeric($rowData['loan_officer']) || !User::find($rowData['loan_officer'])) {
                return ['error' => "Row $rowNumber: Invalid loan_officer"];
            }

            if (!is_numeric($rowData['group_id']) || !Group::find($rowData['group_id'])) {
                return ['error' => "Row $rowNumber: Invalid group_id"];
            }

            return [
                'customer_id' => $customer->id,
                'customer_no' => $rowData['customer_no'],
                'amount' => (float) $rowData['amount'],
                'period' => (int) $rowData['period'],
                'interest' => (float) $rowData['interest'],
                'date_applied' => $parsedDate,
                'interest_cycle' => strtolower($rowData['interest_cycle']),
                'loan_officer' => (int) $rowData['loan_officer'],
                'group_id' => (int) $rowData['group_id'],
                'sector' => $rowData['sector'],
            ];
        } catch (\Exception $e) {
            return ['error' => "Row $rowNumber: Validation error - " . $e->getMessage()];
        }
    }

    private function getRecentImportLogs($since)
    {
        try {
            $logFile = storage_path('logs/laravel.log');
            if (!file_exists($logFile)) {
                return [];
            }
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return [];
            }
            $sinceTs = strtotime((string) $since);
            $matched = [];
            // scan from end; collect up to 100 relevant lines
            for ($i = count($lines) - 1; $i >= 0 && count($matched) < 100; $i--) {
                $line = $lines[$i];
                // naive timestamp parse: look for today's date or any timestamp after $since
                $isRelevantText = (stripos($line, 'Import started') !== false) ||
                    (stripos($line, 'Processing row') !== false) ||
                    (stripos($line, 'Row validation failed') !== false) ||
                    (stripos($line, 'Product limits validation failed') !== false) ||
                    (stripos($line, 'Collateral validation failed') !== false) ||
                    (stripos($line, 'Existing loan check failed') !== false) ||
                    (stripos($line, 'Error creating loan') !== false);
                if ($isRelevantText) {
                    $matched[] = $line;
                }
            }
            return array_reverse($matched);
        } catch (\Throwable $t) {
            return [];
        }
    }

    private function buildImportTips(array $errors, LoanProduct $product)
    {
        $tips = [];
        foreach ($errors as $e) {
            $msgLower = strtolower($e);
            // 1) Interest rate outside limits (from product limits message)
            if (preg_match('/interest rate must be between/i', $e)) {
                // Keep message as-is; it already contains precise bounds
                $tips[] = trim($e);
                continue;
            }
            // 2) Customer not found -> include number
            if (preg_match("/customer number '([^']+)' not found/i", $e, $m)) {
                $tips[] = 'not customer found with ' . $m[1] . ' number';
                continue;
            }
            // 3) Incorrect date format
            if (str_contains($msgLower, 'invalid date_applied')) {
                $tips[] = 'incorrect date format';
                continue;
            }
            // 4) Loan officer invalid -> include id
            if (preg_match('/invalid loan_officer/i', $e)) {
                if (preg_match('/loan_officer[\s:]*(\d+)/i', $e, $m2)) {
                    $tips[] = 'no loan officer with ' . $m2[1] . ' id';
                } else {
                    $tips[] = 'no loan officer with provided id';
                }
                continue;
            }
            // 5) Amount/period outside product limits
            if (preg_match('/amount must be between/i', $e)) {
                $tips[] = trim($e);
                continue;
            }
            if (preg_match('/period must be between/i', $e)) {
                $tips[] = trim($e);
                continue;
            }
            // 6) Group invalid
            if (preg_match('/invalid group_id/i', $e)) {
                $tips[] = 'group_id is invalid';
                continue;
            }
            // 7) Existing active loan
            if (preg_match('/already has an active loan/i', $e)) {
                // Show the message exactly as it is written for clarity
                $tips[] = 'Customer already has an active loan for this product';
                continue;
            }
            // 8) Collateral
            if (preg_match('/insufficient collateral/i', $e)) {
                $tips[] = 'insufficient collateral for requested amount';
                continue;
            }
        }
        // Dedupe & keep order
        $tips = array_values(array_unique($tips));
        // If none matched, add a generic tip
        if (empty($tips)) {
            $tips[] = 'review the CSV/XLSX values against product limits and required fields';
        }
        // Prefix items with 'fix: ' expectation is done in the view heading, so return plain items
        return $tips;
    }

    private function createLoanFromImport($validated, $product, $accountId, $userId, $branchId)
    {
        $convertedInterest = $this->convertInterestRate(
            (float) $validated['interest'],
            $validated['interest_cycle'] ?? 'monthly'
        );

        // Create Loan
        $loan = Loan::create([
            'product_id' => $product->id,
            'period' => $validated['period'],
            'interest' => $convertedInterest,
            'amount' => $validated['amount'],
            'customer_id' => $validated['customer_id'],
            'group_id' => $validated['group_id'],
            'bank_account_id' => $accountId,
            'date_applied' => $validated['date_applied'],
            'disbursed_on' => $validated['date_applied'],
            'sector' => $validated['sector'],
            'branch_id' => $branchId,
            'status' => 'active',
            'interest_cycle' => $validated['interest_cycle'],
            'loan_officer_id' => $validated['loan_officer'],
            'custom_fee_amounts' => null,
        ]);

        // Calculate interest and repayment dates
        $interestAmount = $loan->calculateInterestAmount($convertedInterest);
        $repaymentDates = $loan->getRepaymentDates();

        // Update Loan with totals and schedule
        $loan->update([
            'interest_amount' => $interestAmount,
            'amount_total' => $loan->amount + $interestAmount,
            'first_repayment_date' => $repaymentDates['first_repayment_date'],
            'last_repayment_date' => $repaymentDates['last_repayment_date'],
        ]);

        // Generate repayment schedule
        $loan->generateRepaymentSchedule($convertedInterest);

        // Post matured interest for past loans (penalties after commit when in transaction)
        $loan->postMaturedInterestForPastLoan();
        $loan->accruePenaltiesForPastLoanWhenReady();

        $disbursementGlService = app(LoanDisbursementGlService::class);
        $disbursementGlService->postDisbursement(
            $loan,
            $validated['date_applied'],
            $userId,
            $branchId
        );
        $notes = $disbursementGlService->disbursementDescription($loan);
    }

    public function loansByStatus($status)
    {
        $branchId = auth()->user()->branch_id;

        // Validate status
        $validStatuses = ['applied', 'checked', 'approved', 'authorized', 'active', 'defaulted', 'rejected', 'completed', 'restructured'];
        if (!in_array($status, $validStatuses)) {
            return redirect()->route('loans.index')->withErrors(['Invalid loan status.']);
        }

        $loans = Loan::with('customer', 'product', 'branch')
            ->where('branch_id', $branchId)
            ->where('status', $status)
            ->latest()->get();

        // Get status display name
        $statusNames = [
            'applied' => 'Applied Loans',
            'checked' => 'Checked Applications',
            'approved' => 'Approved Applications',
            'authorized' => 'Authorized Applications',
            'active' => 'Active Loans',
            'defaulted' => 'Defaulted Loans',
            'rejected' => 'Rejected Applications',
            'completed' => 'Completed Loans',
            'restructured' => 'Restructured Loans'
        ];

        $pageTitle = $statusNames[$status] ?? ucfirst($status) . ' Loans';

        // Get data for import modal and repayment modal
        $branches = \App\Models\Branch::all();
        $loanProducts = \App\Models\LoanProduct::all();
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $cashDeposits = \App\Models\CashCollateral::with(['customer', 'type'])->where('amount', '>', 0)->get();

        return view('loans.list', compact('loans', 'pageTitle', 'status', 'branches', 'loanProducts', 'bankAccounts', 'cashDeposits'));
    }

    public function create()
    {
        $branchId = auth()->user()->branch_id;
        $customers = Customer::with('groups')
            ->where('category', 'Borrower')
            ->where('branch_id', $branchId)
            ->get();
        // Removed heavy debug dump of customers to avoid timeouts
        $products = LoanProduct::where('is_active', true)->get();
        $productFeesMeta = $this->buildProductFeesMetaMap($products);

        $loanOfficers = User::where('branch_id', auth()->user()->branch_id)->excludeSuperAdmin()->get();

        $interestCycles = [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'bimonthly' => 'Bi-monthly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'semi_annually' => 'Semi Annually',
            'annually' => 'Annually'
        ];
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $sectors = ['Agriculture', 'Business', 'Education', 'Health', 'Other']; // Example sectors
        return view('loans.create', compact('customers', 'products', 'sectors', 'bankAccounts', 'loanOfficers', 'interestCycles', 'productFeesMeta'));
    }

    /**
     * Calculate loan summary before creation
     */
    public function calculateLoanSummary(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:loan_products,id',
                'period' => 'required|integer|min:1',
                'interest' => 'required|numeric|min:0',
                'amount' => 'required|numeric|min:0',
                'interest_cycle' => 'required|string|max:50',
                'account_id' => 'nullable|exists:bank_accounts,id', // Optional for GL summary
                'custom_fee_amounts' => 'nullable|array',
                'custom_fee_amounts.*' => 'nullable|numeric|min:0',
            ]);

            $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
            $principal = (float) $validated['amount'];
            $customMap = Fee::normalizeCustomFeeAmountsMap($request->input('custom_fee_amounts'));

            // Convert interest rate based on selected cycle
            $convertedInterest = $this->convertInterestRate($validated['interest'], $validated['interest_cycle']);

            // Calculate release date fees
            $releaseFeeTotal = 0;
            $releaseFees = [];
            $allFees = [];
            if ($product && $product->fees_ids) {
                $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
                if (is_array($feeIds)) {
                    // Get all fees for the product
                    $allProductFees = \DB::table('fees')
                        ->whereIn('id', $feeIds)
                        ->where('status', 'active')
                        ->get();

                    // Get release date fees
                    $fees = $allProductFees->where('deduction_criteria', 'charge_fee_on_release_date');

                    foreach ($fees as $fee) {
                        $feeModel = Fee::find($fee->id);
                        $calculatedFee = $feeModel ? $feeModel->monetaryAmountForPrincipal($principal, $customMap ?: null) : 0;
                        $feeType = $fee->fee_type;

                        $releaseFeeTotal += $calculatedFee;
                        $releaseFees[] = [
                            'id' => $fee->id,
                            'name' => $fee->name,
                            'type' => $feeType,
                            'amount' => $calculatedFee,
                            'criteria' => $fee->deduction_criteria,
                        ];
                    }

                    // Store all fees for duplicate detection
                    foreach ($allProductFees as $fee) {
                        $feeModel = Fee::find($fee->id);
                        $calculatedFee = $feeModel ? $feeModel->monetaryAmountForPrincipal($principal, $customMap ?: null) : 0;
                        $feeType = $fee->fee_type;

                        $allFees[] = [
                            'id' => $fee->id,
                            'name' => $fee->name,
                            'type' => $feeType,
                            'amount' => $calculatedFee,
                            'criteria' => $fee->deduction_criteria,
                            'include_in_schedule' => $fee->include_in_schedule ?? 0,
                        ];
                    }
                }
            }

            // Calculate net disbursed amount
            $netDisbursed = $principal - $releaseFeeTotal;

            // Use calculator service for full calculation
            $calculatorService = new \App\Services\LoanCalculatorService();
            $calculation = $calculatorService->calculateLoan([
                'product_id' => $validated['product_id'],
                'amount' => $principal,
                'period' => $validated['period'],
                'interest_rate' => $validated['interest'],
                'interest_cycle' => $validated['interest_cycle'],
                'start_date' => now()->format('Y-m-d'),
                'custom_fee_amounts' => $customMap,
            ]);

            if (!$calculation['success']) {
                return response()->json([
                    'success' => false,
                    'error' => $calculation['error'] ?? 'Calculation failed'
                ], 400);
            }

            // Detect duplicate fees (fees that are charged on release date AND also in schedule)
            $duplicateFees = [];
            $releaseFeeIds = collect($releaseFees)->pluck('id')->toArray();

            // Get all fees that are included in schedule
            $scheduleFees = [];
            if ($product && $product->fees_ids) {
                $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
                if (is_array($feeIds)) {
                    $scheduleFeesData = \DB::table('fees')
                        ->whereIn('id', $feeIds)
                        ->where('status', 'active')
                        ->where(function($query) {
                            $query->where('include_in_schedule', 1)
                                  ->orWhereIn('deduction_criteria', [
                                      'distribute_fee_evenly_to_all_repayments',
                                      'charge_same_fee_to_all_repayments',
                                      'charge_fee_on_first_repayment',
                                      'charge_fee_on_last_repayment'
                                  ]);
                        })
                        ->get();

                    foreach ($scheduleFeesData as $fee) {
                        if (in_array($fee->id, $releaseFeeIds)) {
                            // This fee is both charged on release date AND in schedule
                            $feeModel = Fee::find($fee->id);
                            $calculatedFee = $feeModel ? $feeModel->monetaryAmountForPrincipal($principal, $customMap ?: null) : 0;

                            $duplicateFees[] = [
                                'name' => $fee->name,
                                'amount' => round($calculatedFee, 2),
                                'criteria' => $fee->deduction_criteria,
                                'include_in_schedule' => $fee->include_in_schedule ?? 0,
                            ];
                        }
                    }
                }
            }

            // Calculate GL Summary
            $glDebits = [];
            $glCredits = [];

            // Get bank account chart account ID (from request)
            $bankAccountId = $request->input('account_id');
            $bankAccount = null;
            $bankChartAccountId = null;
            if ($bankAccountId) {
                $bankAccount = \App\Models\BankAccount::with('chartAccount')->find($bankAccountId);
                if ($bankAccount) {
                    $bankChartAccountId = $bankAccount->chart_account_id;
                }
            }

            // Get principal receivable account
            $principalReceivableAccount = $product->principalReceivableAccount;
            $principalReceivableAccountId = $principalReceivableAccount ? $principalReceivableAccount->id : null;

            // GL Entry 1: Principal Receivable (Debit)
            if ($principalReceivableAccountId) {
                $glDebits[] = [
                    'account_name' => $principalReceivableAccount->name ?? 'Principal Receivable',
                    'account_code' => $principalReceivableAccount->code ?? '',
                    'amount' => round($principal, 2),
                    'description' => 'Loan Principal'
                ];
            }

            // GL Entry 2: Bank Account (Credit) - for net disbursement amount
            if ($bankChartAccountId && $bankAccount && $bankAccount->chartAccount) {
                $glCredits[] = [
                    'account_name' => $bankAccount->name ?? 'Bank Account',
                    'account_code' => $bankAccount->chartAccount->code ?? '',
                    'amount' => round($netDisbursed, 2),
                    'description' => 'Loan Disbursement'
                ];
            }

            // GL Entry 3: Release Date Fees
            foreach ($releaseFees as $fee) {
                $feeModel = \App\Models\Fee::with('chartAccount')->find($fee['id']);
                if ($feeModel && $feeModel->chart_account_id) {
                    $feeChartAccount = $feeModel->chartAccount;

                    // Credit: Fee Income Account
                    $glCredits[] = [
                        'account_name' => $feeChartAccount->account_name ?? $fee['name'],
                        'account_code' => $feeChartAccount->account_code ?? '',
                        'amount' => round($fee['amount'], 2),
                        'description' => $fee['name'] . ' Fee Income'
                    ];

                    // Debit: Bank Account (for fee payment)
                    if ($bankChartAccountId && $bankAccount && $bankAccount->chartAccount) {
                        $glDebits[] = [
                            'account_name' => $bankAccount->name ?? 'Bank Account',
                            'account_code' => $bankAccount->chartAccount->code ?? '',
                            'amount' => round($fee['amount'], 2),
                            'description' => $fee['name'] . ' Fee Payment'
                        ];
                    }
                }
            }

            // Calculate totals
            $totalDebits = array_sum(array_column($glDebits, 'amount'));
            $totalCredits = array_sum(array_column($glCredits, 'amount'));

            // If there's a remaining balance, credit/debit the selected bank account to balance
            $balanceDifference = $totalDebits - $totalCredits;
            if (abs($balanceDifference) > 0.01 && $bankChartAccountId && $bankAccount && $bankAccount->chartAccount) {
                if ($balanceDifference > 0) {
                    // Need to credit more to balance
                    $glCredits[] = [
                        'account_name' => $bankAccount->name ?? 'Bank Account',
                        'account_code' => $bankAccount->chartAccount->code ?? '',
                        'amount' => round($balanceDifference, 2),
                        'description' => 'Balance Adjustment'
                    ];
                    $totalCredits += $balanceDifference;
                } else {
                    // Need to debit more to balance
                    $glDebits[] = [
                        'account_name' => $bankAccount->name ?? 'Bank Account',
                        'account_code' => $bankAccount->chartAccount->code ?? '',
                        'amount' => round(abs($balanceDifference), 2),
                        'description' => 'Balance Adjustment'
                    ];
                    $totalDebits += abs($balanceDifference);
                }
            }

            return response()->json([
                'success' => true,
                'summary' => [
                    'loan_amount' => $principal,
                    'interest_rate' => $convertedInterest,
                    'period' => $validated['period'],
                    'interest_cycle' => $validated['interest_cycle'],
                    'total_interest' => $calculation['totals']['total_interest'],
                    'total_fees' => $calculation['totals']['total_fees'],
                    'release_date_fees' => round($releaseFeeTotal, 2),
                    'net_disbursed' => round($netDisbursed, 2),
                    'monthly_payment' => $calculation['totals']['monthly_payment'],
                    'total_amount' => $calculation['totals']['total_amount'],
                    'release_fees_breakdown' => $releaseFees,
                    'duplicate_fees' => $duplicateFees,
                    'all_fees' => $allFees,
                    'gl_summary' => [
                        'debits' => $glDebits,
                        'credits' => $glCredits,
                        'total_debits' => round($totalDebits, 2),
                        'total_credits' => round($totalCredits, 2),
                    ],
                ],
                'calculation' => $calculation
            ]);

        } catch (\Exception $e) {
            \Log::error('Loan summary calculation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function store(Request $request)
    {
        // Debug: Log all request data
        \Log::info('Store method request data:', $request->all());

        if (!$request->filled('first_repayment_date')) {
            $request->merge(['first_repayment_date' => null]);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'period' => 'required|integer|min:1',
            'interest' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'date_applied' => 'required|date|before_or_equal:today',
            'first_repayment_date' => 'nullable|date|after_or_equal:date_applied',
            'customer_id' => 'required|exists:customers,id',
            'interest_cycle' => 'required|string|max:50',
            'loan_officer' => 'required|exists:users,id',
            'group_id' => 'required|exists:groups,id',
            'account_id' => 'required|exists:bank_accounts,id',
            'sector' => 'required|string',
            'custom_fee_amounts' => 'nullable|array',
            'custom_fee_amounts.*' => 'nullable|numeric|min:0',
        ]);

        // Debug: Log the validated data to check customer_id
        \Log::info('Store method validated data:', $validated);



        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        // Restrict application if product has no approval levels
        if ($product->has_approval_levels && (empty($product->approval_levels) || count($product->approval_levels) === 0)) {
            return back()->withErrors(['error' => 'Loan application must have levels of approval configured.'])->withInput();
        }
        $this->validateProductLimits($validated, $product);

        if ($redirect = $this->validateCustomFeesForProduct($request, $product)) {
            return $redirect;
        }
        $customFeeAmounts = $this->normalizedCustomFeeAmountsForProduct($request, $product);

        // 🔐 Check collateral OUTSIDE transaction
        if ($product->requiresCollateral()) {
            $requiredCollateral = $product->calculateRequiredCollateral($validated['amount']);
            $availableCollateral = CashCollateral::getCashCollateralBalance($validated['customer_id']);

            if ($availableCollateral < $requiredCollateral) {
                return redirect()->back()->withErrors([
                    'collateral' => 'The customer does not have enough cash collateral to qualify for this loan.
                Required: TZS ' . number_format($requiredCollateral, 2) .
                        ', Available: TZS ' . number_format($availableCollateral, 2) . '.',
                ])->withInput();
            }
        }

        // Check if customer already has an active loan for this product (for top-up logic)
        $existingLoan = Loan::where('customer_id', $validated['customer_id'])
            ->where('product_id', $validated['product_id'])
            ->where('status', 'active')
            ->first();

        // Check if customer has reached maximum number of loans for this product
        if ($product->hasReachedMaxLoans($validated['customer_id'])) {
            $remainingLoans = $product->getRemainingLoans($validated['customer_id']);
            $maxLoans = $product->maximum_number_of_loans;

            \Log::info("Maximum loan validation triggered", [
                'customer_id' => $validated['customer_id'],
                'product_id' => $product->id,
                'product_name' => $product->name,
                'max_loans' => $maxLoans,
                'remaining_loans' => $remainingLoans
            ]);

            if ($remainingLoans === 0) {
                // If customer has an existing active loan, suggest top-up
                if ($existingLoan) {
                    $topupAmount = $product->topupAmount($validated['amount']);
                    return redirect()->back()->withErrors([
                        'loan_product' => "Customer has reached the maximum number of loans ({$maxLoans}) for this product. However, you can apply for a top-up instead. Top-up Amount: TZS " . number_format($topupAmount, 2),
                    ])->withInput();
                } else {
                    // No existing loan but max reached - this shouldn't happen in normal flow
                    return redirect()->back()->withErrors([
                        'loan_product' => "Customer has reached the maximum number of loans ({$maxLoans}) for this product. Cannot create additional loans.",
                    ])->withInput();
                }
            }
        }


        $userId = auth()->id();
        $branchId = auth()->user()->branch_id;
        $loan = null;

        try {
            DB::transaction(function () use ($validated, $product, $userId, $branchId, &$loan, $customFeeAmounts) {
                // Step 1: Create Loan with initial status

                // Convert interest rate based on selected cycle (base is monthly)
                $convertedInterest = $this->convertInterestRate($validated['interest'], $validated['interest_cycle']);

                // Step 1: Create Loan
                $loan = Loan::create([
                    'product_id' => $validated['product_id'],
                    'period' => $validated['period'],
                    'interest' => $convertedInterest, // Store converted interest rate
                    'amount' => $validated['amount'],
                    'customer_id' => $validated['customer_id'],
                    'group_id' => $validated['group_id'],
                    'bank_account_id' => $validated['account_id'],
                    'date_applied' => $validated['date_applied'],
                    'disbursed_on' => $validated['date_applied'],
                    'sector' => $validated['sector'],
                    'branch_id' => $branchId,
                    'status' => 'active',
                    'interest_cycle' => $validated['interest_cycle'], // Use cycle from form
                    'loan_officer_id' => $validated['loan_officer'],
                    'custom_fee_amounts' => $customFeeAmounts ?: null,
                ]);
                info('loaan-->' . $loan);

                // Step 2: Calculate interest and repayment dates (use converted interest)
                $interestAmount = $loan->calculateInterestAmount($convertedInterest);
                $repaymentDates = $loan->resolveRepaymentDates($validated['first_repayment_date'] ?? null);

                // Step 3: Update Loan with totals and schedule
                $loan->update([
                    'interest_amount' => $interestAmount,
                    'amount_total' => $loan->amount + $interestAmount,
                    'first_repayment_date' => $repaymentDates['first_repayment_date'],
                    'last_repayment_date' => $repaymentDates['last_repayment_date'],
                ]);

                // Step 4: Generate repayment schedule (use converted interest)
                $loan->generateRepaymentSchedule($convertedInterest);

                // Step 4.5: Post matured interest for past loans (penalties after commit)
                $loan->postMaturedInterestForPastLoan();
                $loan->accruePenaltiesForPastLoanWhenReady();

                // Log generated schedule details
                $schedule = $loan->schedule()->orderBy('due_date')->get();
                info('Generated Loan Schedule:', [
                    'loan_id' => $loan->id,
                    'loan_amount' => $loan->amount,
                    'periods' => $schedule->count(),
                    'total_principal' => $schedule->sum('principal'),
                    'total_interest' => $schedule->sum('interest'),
                    'total_fees' => $schedule->sum('fee_amount'),
                    'total_penalties' => $schedule->sum('penalty_amount'),
                    'schedule_items' => $schedule->map(function ($item, $index) {
                        return [
                            'installment' => $index + 1,
                            'due_date' => $item->due_date,
                            'principal' => $item->principal,
                            'interest' => $item->interest,
                            'fee_amount' => $item->fee_amount,
                            'penalty_amount' => $item->penalty_amount,
                            'total_due' => $item->principal + $item->interest + $item->fee_amount + $item->penalty_amount
                        ];
                    })->toArray()
                ]);

                // Step 5: Record Payment
                $bankAccount = BankAccount::findOrFail($validated['account_id']);

                // Validate bank account is accessible within current branch scope
                $user = auth()->user();
                $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : null;
                if (!$currentBranchId) {
                    $currentBranchId = $user->branch_id;
                }

                $hasDirectScope = $bankAccount->is_all_branches
                    || ($currentBranchId && (int) $bankAccount->branch_id === (int) $currentBranchId);

                if (!$hasDirectScope) {
                    throw new \Exception('You do not have access to this bank account.');
                }

                $disbursementGlService = app(LoanDisbursementGlService::class);
                $disbursementGlService->postDisbursement(
                    $loan,
                    $validated['date_applied'],
                    $userId,
                    $branchId
                );
                $notes = $disbursementGlService->disbursementDescription($loan);
            });

            // SMS to customer & company (if enabled in SMS settings)
            try {
                $loan->refresh();
                app(\App\Services\LoanSmsNotificationService::class)->sendDisbursementNotification($loan);
            } catch (\Exception $e) {
                \Log::error('Failed to send loan creation SMS', [
                    'loan_id' => $loan->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('loans.list')->with('success', 'Loan application created successfully.');
        } catch (\Throwable $th) {
            return back()->withErrors([
                'error' => 'Failed to process loan application: ' . $th->getMessage()
            ])->withInput();
        }
    }


    public function edit($encodedId)
    {
        $decoded = \Vinkla\Hashids\Facades\Hashids::decode($encodedId);
        if (empty($decoded)) {
            abort(404, 'Invalid loan ID');
        }
        $loanId = $decoded[0];
        $loan = Loan::findOrFail($loanId);

        if (!auth()->user()->can('edit loan')) {
            abort(403, 'You do not have permission to edit loans.');
        }

        if (!$loan->canBeEdited(auth()->user()) || $loan->usesApplicationEditForm()) {
            return redirect()->route('loans.list')->withErrors([
                'error' => 'This loan cannot be edited. Completed and restructured loans cannot be modified.',
            ]);
        }

        $loanOfficers = User::where('branch_id', auth()->user()->branch_id)->excludeSuperAdmin()->get();

        $interestCycles = [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'bimonthly' => 'Bi-monthly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'semi_annually' => 'Semi Annually',
            'annually' => 'Annually'
        ];

        // Fetch supporting data
        $customers = Customer::all();
        // Only fetch groups where this customer is a member
        $groups = \DB::table('groups')
            ->join('group_members', 'groups.id', '=', 'group_members.group_id')
            ->where('group_members.customer_id', $loan->customer_id)
            ->select('groups.*')
            ->get();
        $products = LoanProduct::where('is_active', true)->get();
        $productFeesMeta = $this->buildProductFeesMetaMap(LoanProduct::all());
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $sectors = ['Agriculture', 'Business', 'Education', 'Health', 'Other']; // You can move this to config if reusable

        return view('loans.edit', [
            'loan' => $loan,
            'customers' => $customers,
            'groups' => $groups,
            'products' => $products,
            'bankAccounts' => $bankAccounts,
            'sectors' => $sectors,
            'interestCycles' => $interestCycles,
            'loanOfficers' => $loanOfficers,
            'productFeesMeta' => $productFeesMeta,
        ]);
    }

    public function update(Request $request, $encodedId)
    {


        \Log::info('LoanController@update reached');
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Invalid loan ID.']);
        }
        $loanId = $decoded[0];
        $loan = Loan::find($loanId);
        if (!$loan) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        if (!auth()->user()->can('edit loan')) {
            abort(403, 'You do not have permission to edit loans.');
        }

        if (!$loan->canBeEdited(auth()->user()) || $loan->usesApplicationEditForm()) {
            return redirect()->route('loans.list')->withErrors([
                'error' => 'This loan cannot be updated. Completed and restructured loans cannot be modified.',
            ]);
        }

        \Log::info('Updating loan application', [
            'loan_id' => $loan->id,
            'user_id' => auth()->id(),
            'data' => $request->all()
        ]);

        if (!$request->filled('first_repayment_date')) {
            $request->merge(['first_repayment_date' => null]);
        }

        // Edit only: if group_id is missing, default to Individual (1)
        if (!$request->filled('group_id')) {
            $request->merge(['group_id' => Group::getIndividualGroupId()]);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'period' => 'required|integer|min:1',
            'interest' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'date_applied' => 'required|date|before_or_equal:today',
            'first_repayment_date' => 'nullable|date|after_or_equal:date_applied',
            'customer_id' => 'required|exists:customers,id',
            'interest_cycle' => 'required|string|max:50',
            'loan_officer' => 'required|exists:users,id',
            'group_id' => 'required|exists:groups,id',
            'account_id' => 'required|exists:bank_accounts,id',
            'sector' => 'required|string',
            'custom_fee_amounts' => 'nullable|array',
            'custom_fee_amounts.*' => 'nullable|numeric|min:0',
        ]);
        Log::info('Update validated data:', $validated);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        $this->validateProductLimits($validated, $product);

        if ($redirect = $this->validateCustomFeesForProduct($request, $product)) {
            return $redirect;
        }
        $customFeeAmounts = $this->normalizedCustomFeeAmountsForProduct($request, $product);

        $userId = auth()->id();
        $branchId = auth()->user()->branch_id;

        try {
            DB::transaction(function () use ($loan, $validated, $product, $userId, $branchId, $customFeeAmounts) {
                $loanId = $loan->id;
                // Only count non–soft-deleted repayments (reversed receipts soft-delete repayments)
                $repaymentCount = Repayment::where('loan_id', $loanId)->count();
                if ($repaymentCount > 0) {
                    throw new \Exception('This loan has repayments. Please delete repayments first before updating the loan.');
                }
                // Check for receipts
                $receiptCount = \DB::table('receipts')
                    ->where('reference_number', $loanId)
                    ->where('reference_type', 'Loan Disbursement')
                    ->count();
                if ($receiptCount > 0) {
                    throw new \Exception('This loan has receipts. Please delete receipts first before updating the loan.');
                }

                // Delete related records (same as destroy)
                // Delete GL Transactions for this loan
                \DB::table('gl_transactions')
                    ->where('transaction_id', $loanId)
                    ->where('transaction_type', 'Loan Disbursement')
                    ->delete();

                // Delete Payments and PaymentItems for this loan
                $payments = \DB::table('payments')
                    ->where('reference_type', 'Loan Payment')
                    ->where('reference', $loanId)
                    ->get();
                $paymentIds = $payments->pluck('id')->toArray();
                if (!empty($paymentIds)) {
                    \DB::table('payment_items')->whereIn('payment_id', $paymentIds)->delete();
                }
                \DB::table('payments')
                    ->where('reference_type', 'Loan Payment')
                    ->where('reference', $loanId)
                    ->delete();

                // Delete Loan Schedule
                \DB::table('loan_schedules')->where('loan_id', $loanId)->delete();

                // Delete Journals and JournalItems if table exists
                if (\Schema::hasTable('journals')) {
                    $journals = \DB::table('journals')
                        ->where('reference_type', 'Loan Disbursement')
                        ->where(function ($query) use ($loanId) {
                            $query->where('reference', $loanId);
                        })
                        ->get();
                    $journalIds = $journals->pluck('id')->toArray();
                    if (!empty($journalIds) && \Schema::hasTable('journal_items')) {
                        \DB::table('journal_items')->whereIn('journal_id', $journalIds)->delete();
                    }
                    \DB::table('journals')
                        ->where('reference_type', 'Loan Disbursement')
                        ->where('reference', $loanId)
                        ->delete();
                }

                $convertedInterest = $this->convertInterestRate(
                    (float) $validated['interest'],
                    $validated['interest_cycle']
                );

                // Now update loan and proceed with transactions (like store)
                $loan->fill([
                    'product_id' => $validated['product_id'],
                    'period' => $validated['period'],
                    'interest' => $convertedInterest,
                    'amount' => $validated['amount'],
                    'customer_id' => $validated['customer_id'],
                    'group_id' => $validated['group_id'],
                    'bank_account_id' => $validated['account_id'],
                    'date_applied' => $validated['date_applied'],
                    'disbursed_on' => $validated['date_applied'],
                    'interest_cycle' => $validated['interest_cycle'], // Use cycle from form
                    'loan_officer_id' => $validated['loan_officer'],
                    'sector' => $validated['sector'],
                    'branch_id' => $branchId,
                    'custom_fee_amounts' => $customFeeAmounts ?: null,
                ]);

                // Calculate interest and repayment dates
                $interestAmount = $loan->calculateInterestAmount($convertedInterest);
                $repaymentDates = $loan->resolveRepaymentDates($validated['first_repayment_date'] ?? null);
                $loan->fill([
                    'interest_amount' => $interestAmount,
                    'amount_total' => $loan->amount + $interestAmount,
                    'first_repayment_date' => $repaymentDates['first_repayment_date'],
                    'last_repayment_date' => $repaymentDates['last_repayment_date'],
                ]);
                $loan->save();
                $loan->generateRepaymentSchedule($convertedInterest);

                // Post matured interest and penalties for past loans
                $loan->postMaturedInterestForPastLoan();
                $loan->accruePenaltiesForPastLoanWhenReady();

                // Create payment record
                $bankAccount = BankAccount::findOrFail($validated['account_id']);

                // Validate bank account is accessible within current branch scope
                $user = auth()->user();
                $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : null;
                if (!$currentBranchId) {
                    $currentBranchId = $user->branch_id;
                }

                $hasDirectScope = $bankAccount->is_all_branches
                    || ($currentBranchId && (int) $bankAccount->branch_id === (int) $currentBranchId);

                if (!$hasDirectScope) {
                    throw new \Exception('You do not have access to this bank account.');
                }

                app(LoanDisbursementGlService::class)->postDisbursement(
                    $loan,
                    $validated['date_applied'],
                    $userId,
                    $branchId
                );
            });
            return redirect()->route('loans.list')->with('success', 'Loan updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage()
            ])->withInput();
        }
    }


    /**
     * Convert interest rate based on interest cycle
     * Base is monthly (as stored in loan product)
     */
    protected function convertInterestRate(float $monthlyRate, string $selectedCycle): float
    {
        return \App\Support\InterestRateConverter::fromMonthlyToCycle($monthlyRate, $selectedCycle);
    }

    //////PRODUCT LIMITS ////////////////////////////////
    protected function validateProductLimits(array $data, LoanProduct $product)
    {
        if (!$product->is_active) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'product_id' => 'The selected loan product is inactive.',
            ]);
        }

        // Skip period validation if range is 1-4 months
        if (!($product->minimum_period == 1 && $product->maximum_period == 4)) {
            if ($data['period'] < $product->minimum_period || $data['period'] > $product->maximum_period) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'period' => "Period must be between {$product->minimum_period} and {$product->maximum_period} months.",
                ]);
            }
        }

        if ($data['interest'] < $product->minimum_interest_rate || $data['interest'] > $product->maximum_interest_rate) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'interest' => "Interest rate must be between {$product->minimum_interest_rate}% and {$product->maximum_interest_rate}%.",
            ]);
        }

        if ($data['amount'] < $product->minimum_principal || $data['amount'] > $product->maximum_principal) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => "Amount must be between {$product->minimum_principal} and {$product->maximum_principal}.",
            ]);
        }
    }


    public function destroy(Request $request, $encodedId)
    {
        return $this->handleLoanDelete($request, $encodedId, cascadeTopupChain: false);
    }

    /**
     * Delete loan plus every loan in the same top-up / restructure chain.
     */
    public function destroyWithTopupChain(Request $request, $encodedId)
    {
        return $this->handleLoanDelete($request, $encodedId, cascadeTopupChain: true);
    }

    protected function handleLoanDelete(Request $request, $encodedId, bool $cascadeTopupChain)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return $this->loanDeleteErrorResponse($request, 'Loan not found.', $encodedId, false);
        }

        $loan = Loan::findOrFail($decoded[0]);
        $loanId = (int) $loan->id;
        $deletionService = new LoanDeletionService();

        if (!$cascadeTopupChain && !$loan->canBeDeleted(auth()->user())) {
            $hasTopupLinks = $deletionService->hasTopupLinks($loanId);
            $blockMessage = $hasTopupLinks
                ? 'This loan cannot be deleted on its own because it is linked to a top-up or restructure. Use "Delete entire top-up chain" to remove all related loans.'
                : 'This loan cannot be deleted in its current status (for example completed or restructured without a top-up link).';

            return $this->loanDeleteErrorResponse($request, $blockMessage, $encodedId, $hasTopupLinks);
        }

        try {
            if ($cascadeTopupChain) {
                $deletionService->deleteTopupChainPermanently($loanId);
            } else {
                $deletionService->deletePermanently($loanId);
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $cascadeTopupChain
                        ? 'Linked loans and all related records were deleted successfully.'
                        : 'Loan and related records deleted successfully.',
                ]);
            }

            return redirect()
                ->route('loans.by-status', 'applied')
                ->with('success', $cascadeTopupChain
                    ? 'Linked loans and all related records deleted successfully.'
                    : 'Loan and related records deleted successfully.');
        } catch (\Throwable $e) {
            Log::error('Loan delete failed', [
                'loan_id' => $loanId,
                'cascade' => $cascadeTopupChain,
                'error' => $e->getMessage(),
            ]);

            $hasTopupLinks = $deletionService->hasTopupLinks($loanId);
            $humanMessage = LoanDeletionService::humanizeException($e, $hasTopupLinks);
            $offerCascade = $hasTopupLinks
                || LoanDeletionService::messageReferencesTopups($e->getMessage());

            return $this->loanDeleteErrorResponse($request, $humanMessage, $encodedId, $offerCascade, $loanId);
        }
    }

    protected function loanDeleteErrorResponse(
        Request $request,
        string $message,
        string $encodedId,
        bool $offerTopupCascade,
        ?int $loanId = null
    ) {
        $summary = ($offerTopupCascade && $loanId)
            ? (new LoanDeletionService())->getTopupChainSummary($loanId)
            : null;

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'topup_chain_available' => $offerTopupCascade,
                'encoded_id' => $encodedId,
                'topup_summary' => $summary,
                'destroy_topup_chain_url' => $offerTopupCascade
                    ? route('loans.destroy-topup-chain', $encodedId)
                    : null,
            ], 422);
        }

        return redirect()
            ->back()
            ->withErrors(['error' => $message])
            ->with('loan_delete_topup_offer', $offerTopupCascade)
            ->with('loan_delete_encoded_id', $encodedId);
    }
    //////////////////SHOW LOAN DETAIL/////////////////////
    public function show($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.index')->withErrors(['Loan not found.']);
        }

        $loan = Loan::with([
            'customer.region',
            'customer.district',
            'customer.branch',
            'customer.company',
            'customer.user',
            'product',
            'bankAccount',
            'group',
            'loanFiles',
            'schedule.repayments',
            'repayments',
            'approvals.user',
            'approvals' => function ($query) {
                $query->orderBy('approval_level', 'asc');
            },
            'guarantors' // add this if not eager loaded already
        ])->findOrFail($decoded[0]);

        // So LoanSchedule::balance_interest_component can call usesDailyInterestAccrual() without N+1
        $loan->schedule->each(static function ($schedule) use ($loan) {
            $schedule->setRelation('loan', $loan);
        });

        // Load receipts safely:
        // - loan_repayment / Repayment: bind through repayments.loan_id (source of truth)
        // - loan: bind through legacy reference/reference_number loan id linkage
        $activeReceipts = Receipt::where(function ($query) use ($loan) {
            $query->where(function ($repaymentQuery) use ($loan) {
                $repaymentQuery->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                    ->whereHas('repayments', function ($q) use ($loan) {
                        $q->where('loan_id', $loan->id);
                    });
            })->orWhere(function ($disbursementQuery) use ($loan) {
                $disbursementQuery->where('reference_type', 'loan')
                    ->where(function ($q) use ($loan) {
                        $q->where('reference', $loan->id)
                            ->orWhere('reference_number', (string) $loan->id);
                    });
            });
        })
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Load reversed receipts (soft-deleted) using same matching logic
        $reversedReceipts = Receipt::onlyTrashed()
            ->where(function ($query) use ($loan) {
                $query->where(function ($repaymentQuery) use ($loan) {
                    $repaymentQuery->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                        ->whereHas('repayments', function ($q) use ($loan) {
                            $q->where('loan_id', $loan->id);
                        });
                })->orWhere(function ($disbursementQuery) use ($loan) {
                    $disbursementQuery->where('reference_type', 'loan')
                        ->where(function ($q) use ($loan) {
                            $q->where('reference', $loan->id)
                                ->orWhere('reference_number', (string) $loan->id);
                        });
                });
            })
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Get IDs of guarantors already attached to this loan
        $guarantorIdsAlreadyAdded = $loan->guarantors->pluck('id')->toArray();

        // Fetch guarantors excluding already assigned ones
        $guarantorCustomers = $this->applyGuarantorCategoryFilter(Customer::query())
            ->whereNotIn('id', $guarantorIdsAlreadyAdded)
            ->get();

        $filetypes = Filetype::all();
        $regions = Region::orderBy('name')->get(['id', 'name']);
        $districts = District::orderBy('name')->get(['id', 'name', 'region_id']);
        $relationOptions = self::GUARANTOR_RELATIONS;
        $feesData = $this->buildLoanFeesData($loan);

        // Get bank accounts for repayment modal (branch-scoped)
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();

        // Load active receipts (same safe matching logic)
        $activeReceipts = Receipt::where(function ($query) use ($loan) {
            $query->where(function ($repaymentQuery) use ($loan) {
                $repaymentQuery->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                    ->whereHas('repayments', function ($q) use ($loan) {
                        $q->where('loan_id', $loan->id);
                    });
            })->orWhere(function ($disbursementQuery) use ($loan) {
                $disbursementQuery->where('reference_type', 'loan')
                    ->where(function ($q) use ($loan) {
                        $q->where('reference', $loan->id)
                            ->orWhere('reference_number', (string) $loan->id);
                    });
            });
        })
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Load reversed receipts (same safe matching logic)
        $reversedReceipts = Receipt::onlyTrashed()
            ->where(function ($query) use ($loan) {
                $query->where(function ($repaymentQuery) use ($loan) {
                    $repaymentQuery->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                        ->whereHas('repayments', function ($q) use ($loan) {
                            $q->where('loan_id', $loan->id);
                        });
                })->orWhere(function ($disbursementQuery) use ($loan) {
                    $disbursementQuery->where('reference_type', 'loan')
                        ->where(function ($q) use ($loan) {
                            $q->where('reference', $loan->id)
                                ->orWhere('reference_number', (string) $loan->id);
                        });
                });
            })
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Set the encoded ID for the loan object
        $loan->encodedId = $encodedId;

        return view('loans.show', compact('loan', 'guarantorCustomers', 'filetypes', 'bankAccounts', 'activeReceipts', 'reversedReceipts', 'regions', 'districts', 'relationOptions') + $feesData);
    }


    ////////////////////UPLOAD LOAN DOCUMENT/////////////////////

    public function loanDocument(Request $request)
    {
        \App\Support\Upload\FileUploadLimits::prepareLongRunningUpload();
        $maxFileSize = \App\Support\Upload\FileUploadLimits::maxKilobytes();
        $maxBytes = \App\Support\Upload\FileUploadLimits::maxBytes();
        $maxMb = \App\Support\Upload\FileUploadLimits::maxMegabytesLabel();
        $allowedMimes = (array) config('upload.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt']);
        $isAjax = $request->expectsJson() || $request->ajax();

        $fail = function (array $errors, int $status = 422) use ($isAjax) {
            if ($isAjax) {
                return response()->json([
                    'success' => false,
                    'message' => collect($errors)->flatten()->first() ?? 'Document upload failed.',
                    'errors' => $errors,
                ], $status);
            }
            return back()->withErrors($errors);
        };

        // Early check for file presence; if missing, inspect native PHP upload errors first.
        if (!$request->hasFile('files')) {
            if (isset($_FILES['files']['error']) && is_array($_FILES['files']['error'])) {
                foreach ($_FILES['files']['error'] as $idx => $errorCode) {
                    if ($errorCode === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $errorMessage = match ($errorCode) {
                        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server limit (upload_max_filesize).',
                        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form limit (MAX_FILE_SIZE).',
                        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
                        default => 'The file failed to upload due to an unknown error.',
                    };
                    return $fail(["files.$idx" => $errorMessage]);
                }
            }

            return $fail(['files' => 'No file was uploaded. Please choose a file and try again.']);
        }

        $request->validate([
            'loan_id' => 'required|exists:loans,id',
            'filetypes' => 'required|array|min:1',
            'filetypes.*' => 'required|exists:filetypes,id',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:' . $maxFileSize . '|mimes:' . implode(',', $allowedMimes),
        ]);

        // Validate each uploaded file is valid at PHP level and provide helpful messages
        $files = (array) $request->file('files');
        $filetypes = (array) $request->input('filetypes', []);
        if (count($filetypes) !== count($files)) {
            return $fail(['filetypes' => 'Each selected document file must have a corresponding document type.']);
        }

        foreach ($files as $idx => $uploaded) {
            if (!$uploaded) {
                return $fail(["files.$idx" => 'File not received by PHP (empty upload).']);
            }
            if ($uploaded->getSize() > $maxBytes) {
                return $fail(["files.$idx" => "The document is too large. Maximum file size is {$maxMb}MB."]);
            }
            if (!$uploaded->isValid()) {
                $errorCode = $uploaded->getError();
                $errorMessage = match ($errorCode) {
                    UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server limit (upload_max_filesize).',
                    UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form limit (MAX_FILE_SIZE).',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
                    default => 'The file failed to upload due to an unknown error.',
                };
                return $fail(["files.$idx" => $errorMessage]);
            }
        }

        $loanId = $request->loan_id;

        $uploadedCount = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            foreach ($files as $index => $file) {
                if (!isset($filetypes[$index]) || empty($filetypes[$index])) {
                    return $fail(["filetypes.$index" => 'Missing document type for one of the uploaded files.']);
                }
                // Store file in configured storage
                $storagePath = config('upload.storage_path', 'loan_documents');
                $storageDisk = config('upload.storage_disk', 'public');
                $filePath = $file->store($storagePath, $storageDisk);

                // Get original filename
                $originalName = $file->getClientOriginalName();

                // Save record in loan_files
                LoanFile::create([
                    'loan_id' => $loanId,
                    'file_type_id' => $filetypes[$index],
                    'file_path' => $filePath,
                    'original_name' => $originalName,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);

                $uploadedCount++;
            }

            DB::commit();

            if ($uploadedCount > 0) {
                $message = $uploadedCount === 1
                    ? 'Document uploaded successfully.'
                    : "{$uploadedCount} documents uploaded successfully.";
                if ($isAjax) {
                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'uploaded_count' => $uploadedCount,
                    ]);
                }
                return back()->with('success', $message);
            } else {
                return $fail(['error' => 'No files were uploaded.']);
            }
        } catch (\Exception $e) {
            DB::rollback();
            \Log::error('Document upload error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $fail(['error' => 'Failed to upload documents: ' . $e->getMessage()], 500);
        }
    }


    ////////////////////DELETE LOAN DOCUMENT/////////////////////
    public function destroyLoanDocument(LoanFile $loanFile)
    {
        try {
            // Delete physical file if exists
            $storageDisk = config('upload.storage_disk', 'public');
            if ($loanFile->file_path && \Storage::disk($storageDisk)->exists($loanFile->file_path)) {
                \Storage::disk($storageDisk)->delete($loanFile->file_path);
            }

            $loanFile->delete();

            return response()->json(['success' => true, 'message' => 'Document deleted successfully.']);
        } catch (\Exception $e) {
            \Log::error('Failed to delete loan document: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete document.'], 500);
        }
    }
    ///////////////////ADD GUARANTOR/////////////////
    public function addGuarantor(Request $request, Loan $loan)
    {
        $validated = $request->validate([
            'guarantor_id' => 'nullable|exists:customers,id|required_without:direct_guarantor_name',
            'direct_guarantor_name' => 'nullable|string|max:255|required_without:guarantor_id',
            'direct_guarantor_phone1' => 'nullable|string|max:20|required_with:direct_guarantor_name',
            'direct_guarantor_sex' => 'nullable|in:M,F|required_with:direct_guarantor_name',
            'direct_guarantor_region_id' => 'nullable|exists:regions,id|required_with:direct_guarantor_name',
            'direct_guarantor_district_id' => 'nullable|exists:districts,id|required_with:direct_guarantor_name',
            'relation' => 'required|string|max:100|in:' . implode(',', self::GUARANTOR_RELATIONS),
        ]);

        $guarantorId = $validated['guarantor_id'] ?? null;

        if (!$guarantorId) {
            $newGuarantor = Customer::create([
                'customerNo' => 100000 + (Customer::max('id') ?? 0) + 1,
                'name' => $validated['direct_guarantor_name'],
                'phone1' => $validated['direct_guarantor_phone1'],
                'dob' => now()->subYears(18)->toDateString(),
                'sex' => $validated['direct_guarantor_sex'],
                'relation' => $validated['relation'],
                'region_id' => $validated['direct_guarantor_region_id'],
                'district_id' => $validated['direct_guarantor_district_id'],
                'category' => 'Guarantor',
                'password' => Hash::make('1234567890'),
                'branch_id' => auth()->user()->branch_id,
                'company_id' => auth()->user()->company_id,
                'registrar' => auth()->id(),
                'dateRegistered' => now()->toDateString(),
                'has_cash_collateral' => false,
            ]);

            $guarantorId = $newGuarantor->id;
        }

        $guarantor = Customer::find($guarantorId);
        $categoryValue = strtolower(trim((string) optional($guarantor)->category));
        $isGuarantorCategory = str_starts_with($categoryValue, 'guarant');

        if (!$guarantor || !$isGuarantorCategory) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => 'Selected customer is not a guarantor.'], 422);
            }

            return redirect()->back()->withErrors(['guarantor_id' => 'Selected customer is not a guarantor.']);
        }

        if ($loan->guarantors()->where('customers.id', $guarantorId)->exists()) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => 'This guarantor is already attached to the loan.'], 422);
            }

            return redirect()->back()->withErrors(['guarantor_id' => 'This guarantor is already attached to the loan.']);
        }

        $loan->guarantors()->attach($guarantorId, ['relation' => $validated['relation'] ?? null]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => 'Guarantor added successfully.']);
        }

        return redirect()->back()->with('success', 'Guarantor added successfully.');
    }
    ///////REMOVE GUARANTOR/////
    public function removeGuarantor(Loan $loan, $guarantorId)
    {
        $loan->guarantors()->detach($guarantorId);

        return redirect()->back()->with('success', 'Guarantor removed successfully.');
    }

    // Loan Application Methods
    public function applicationIndex(Request $request)
    {
        $branchId = auth()->user()->branch_id;
        $status = $request->get('status', 'applied');

        $loanApplications = Loan::with('customer', 'product', 'branch', 'approvals')
            ->where('branch_id', $branchId)
            ->where('status', $status)
            ->latest()
            ->paginate(10);

        return view('loans.application.index', compact('loanApplications', 'status'));
    }

    public function applicationCreate()
    {
        $branchId = auth()->user()->branch_id;
        $customers = Customer::where('category', 'borrower')
            ->where('branch_id', $branchId)
            ->with('groups:id,name')
            ->select('id', 'name', 'phone1', 'customerNo', 'branch_id')
            ->orderBy('name')
            ->get();
        $groups = Group::where('branch_id', $branchId)->get();
        $products = LoanProduct::where('is_active', true)->get();
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $sectors = ['Agriculture', 'Business', 'Education', 'Health', 'Other'];

        // Align supporting data with direct loan creation form
        $loanOfficers = User::where('branch_id', auth()->user()->branch_id)->excludeSuperAdmin()->get();
        $interestCycles = [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'bimonthly' => 'Bi-monthly',
            'monthly' => 'Monthly',
            'quarterly' => 'Quarterly',
            'semi_annually' => 'Semi Annually',
            'annually' => 'Annually'
        ];

        $products = LoanProduct::where('is_active', true)->get();
        $productFeesMeta = $this->buildProductFeesMetaMap($products);

        return view('loans.application.create', compact(
            'customers',
            'groups',
            'products',
            'sectors',
            'bankAccounts',
            'loanOfficers',
            'interestCycles',
            'productFeesMeta'
        ));
    }

    public function applicationStore(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'period' => 'required|integer|min:1',
            'interest' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'date_applied' => 'required|date|before_or_equal:today',
            'customer_id' => 'required|exists:customers,id',
            'group_id' => 'nullable|exists:groups,id',
            'sector' => 'required|string',
            'interest_cycle' => 'required|string|in:daily,weekly,bimonthly,monthly,quarterly,semi_annually,annually',
            'custom_fee_amounts' => 'nullable|array',
            'custom_fee_amounts.*' => 'nullable|numeric|min:0',
        ]);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        $this->validateProductLimits($validated, $product);

        if ($redirect = $this->validateCustomFeesForProduct($request, $product)) {
            return $redirect;
        }
        $customFeeAmounts = $this->normalizedCustomFeeAmountsForProduct($request, $product);

        $userId = auth()->id();
        $branchId = auth()->user()->branch_id;

        //check if customer is active
        // $customer = Customer::findOrFail($validated['customer_id']);
        // if (!$customer->is_active) {
        //     return back()->withErrors(['error' => 'Customer is not active.']);
        // }

        //check if loan product is active
        if (!$product->is_active) {
            return back()->withErrors(['error' => 'Loan product is not active.']);
        }

        //check the min and max amount for the loan product
        if ($validated['amount'] < $product->minimum_principal || $validated['amount'] > $product->maximum_principal) {
            return back()->withErrors(['error' => 'Loan amount must be between ' . $product->minimum_principal . ' and ' . $product->maximum_principal . '.']);
        }

        //check the min and max interest rate for the loan product
        if ($validated['interest'] < $product->minimum_interest_rate || $validated['interest'] > $product->maximum_interest_rate) {
            return back()->withErrors(['error' => 'Interest rate must be between ' . $product->minimum_interest_rate . ' and ' . $product->maximum_interest_rate . '.']);
        }

        //check the min and max period for the loan product
        // Skip period validation if range is 1-4 months
        if (!($product->minimum_period == 1 && $product->maximum_period == 4)) {
            if ($validated['period'] < $product->minimum_period || $validated['period'] > $product->maximum_period) {
                return back()->withErrors(['error' => 'Period must be between ' . $product->minimum_period . ' and ' . $product->maximum_period . '.']);
            }
        }

        if ($product->requiresCollateral()) {
            $requiredCollateral = $product->calculateRequiredCollateral((float) $validated['amount']);
            $availableCollateral = CashCollateral::getCashCollateralBalance($validated['customer_id']);

            if ($availableCollateral < $requiredCollateral) {
                return back()->withErrors([
                    'collateral' => 'The customer does not have enough cash collateral to qualify for this loan. Required: TZS '
                        . number_format($requiredCollateral, 2)
                        . ', Available: TZS '
                        . number_format($availableCollateral, 2) . '.',
                ])->withInput();
            }
        }

        // Check if customer has reached maximum number of loans for this product

        // Check if customer already has an active loan for this product (for top-up logic)
        $existingLoan = Loan::where('customer_id', $validated['customer_id'])
            ->where('product_id', $validated['product_id'])
            ->where('status', 'active')
            ->first();

        if ($product->hasReachedMaxLoans($validated['customer_id'])) {
            $remainingLoans = $product->getRemainingLoans($validated['customer_id']);
            $maxLoans = $product->maximum_number_of_loans;

            \Log::info("Maximum loan validation triggered", [
                'customer_id' => $validated['customer_id'],
                'product_id' => $product->id,
                'product_name' => $product->name,
                'max_loans' => $maxLoans,
                'remaining_loans' => $remainingLoans
            ]);

            if ($remainingLoans === 0) {
                // If customer has an existing active loan, suggest top-up
                if ($existingLoan) {
                    $topupAmount = $product->topupAmount($validated['amount']);
                    return redirect()->back()->withErrors([
                        'loan_product' => "Customer has reached the maximum number of loans ({$maxLoans}) for this product. However, you can apply for a top-up instead. Top-up Amount: TZS " . number_format($topupAmount, 2),
                    ])->withInput();
                } else {
                    // No existing loan but max reached - this shouldn't happen in normal flow
                    return redirect()->back()->withErrors([
                        'loan_product' => "Customer has reached the maximum number of loans ({$maxLoans}) for this product. Cannot create additional loans.",
                    ])->withInput();
                }
            }
        }



        try {
            DB::beginTransaction();

            // All loan applications start as 'applied' status
            $initialStatus = Loan::STATUS_APPLIED;

            // Convert interest rate based on selected cycle (base is monthly)
            $convertedInterest = $this->convertInterestRate($validated['interest'], $validated['interest_cycle']);

            $loan = Loan::create([
                'product_id' => $validated['product_id'],
                'period' => $validated['period'],
                'interest' => $convertedInterest, // Store converted interest rate
                'amount' => $validated['amount'],
                'customer_id' => $validated['customer_id'],
                'group_id' => $validated['group_id'],
                'bank_account_id' => null, // Set to null for loan applications
                'date_applied' => $validated['date_applied'],
                'sector' => $validated['sector'],
                'interest_cycle' => $validated['interest_cycle'], // Use from form
                'loan_officer_id' => $userId, // Set to current user for loan applications
                'branch_id' => $branchId,
                'status' => $initialStatus,
                'interest_amount' => 0, // Will be calculated below
                'amount_total' => 0, // Will be calculated below
                'first_repayment_date' => null,
                'last_repayment_date' => null,
                'disbursed_on' => null,
                'top_up_id' => null,
                'custom_fee_amounts' => $customFeeAmounts ?: null,
            ]);

            // Use converted per-period rate for totals (same as direct loan)
            $interestAmount = $loan->calculateInterestAmount($convertedInterest);
            $loan->update([
                'interest_amount' => $interestAmount,
                'amount_total' => $validated['amount'] + $interestAmount,
            ]);

            // Note: For loan applications, we don't disburse immediately even if no approval levels are required
            // The disbursement will happen during the approval process when a bank account is selected

            DB::commit();

            $message = 'Loan application submitted successfully and awaiting approval.';

            return redirect()->route('loans.by-status', 'applied')->with('success', $message);
        } catch (\Throwable $th) {
            DB::rollBack();
            return back()->withErrors([
                'error' => 'Failed to submit loan application: ' . $th->getMessage()
            ])->withInput();
        }
    }

    public function applicationShow($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.index')->withErrors(['Loan not found.']);
        }

        $loan = Loan::with([
            'customer.region',
            'customer.district',
            'customer.branch',
            'customer.company',
            'customer.user',
            'product',
            'bankAccount',
            'group',
            'loanFiles',
            'schedule',
            'approvals.user',
            'approvals' => function ($query) {
                $query->orderBy('approval_level', 'asc');
            },
            'guarantors' // add this if not eager loaded already
        ])->findOrFail($decoded[0]);

        // Get IDs of guarantors already attached to this loan
        $guarantorIdsAlreadyAdded = $loan->guarantors->pluck('id')->toArray();

        // Fetch guarantors excluding already assigned ones
        $guarantorCustomers = $this->applyGuarantorCategoryFilter(Customer::query())
            ->whereNotIn('id', $guarantorIdsAlreadyAdded)
            ->get();

        $filetypes = Filetype::all();
        $regions = Region::orderBy('name')->get(['id', 'name']);
        $districts = District::orderBy('name')->get(['id', 'name', 'region_id']);
        $relationOptions = self::GUARANTOR_RELATIONS;
        $feesData = $this->buildLoanFeesData($loan);

        // Branch-scoped bank accounts for repayment modal
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();

        // Set the encoded ID for the loan object
        $loan->encodedId = $encodedId;

        return view('loans.show', compact('loan', 'guarantorCustomers', 'filetypes', 'bankAccounts', 'regions', 'districts', 'relationOptions') + $feesData);
    }

    public function applicationEdit($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Loan application not found.']);
        }

        $loanApplication = Loan::findOrFail($decoded[0]);

        if (!$loanApplication->canBeEdited() || !$loanApplication->usesApplicationEditForm()) {
            return redirect()->route('loans.by-status', 'applied')->withErrors([
                'error' => 'This loan cannot be edited. Only loans in the approval pipeline (before disbursement) can be modified.',
            ]);
        }

        $branchId = auth()->user()->branch_id;
        $customers = Customer::where('category', 'borrower')
            ->where('branch_id', $branchId)
            ->with('groups')
            ->get();
        $groups = Group::where('branch_id', $branchId)->get();
        $products = LoanProduct::all();
        $productFeesMeta = $this->buildProductFeesMetaMap($products);
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $sectors = ['Agriculture', 'Business', 'Education', 'Health', 'Other'];

        return view('loans.application.edit', compact('loanApplication', 'customers', 'groups', 'products', 'sectors', 'bankAccounts', 'productFeesMeta'));
    }

    public function applicationUpdate(Request $request, $encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Loan application not found.']);
        }

        $loanApplication = Loan::findOrFail($decoded[0]);

        if (!$loanApplication->canBeEdited() || !$loanApplication->usesApplicationEditForm()) {
            return redirect()->route('loans.by-status', 'applied')->withErrors([
                'error' => 'This loan cannot be updated. Only loans in the approval pipeline (before disbursement) can be modified.',
            ]);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'period' => 'required|integer|min:1',
            'interest' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'date_applied' => 'required|date|before_or_equal:today',
            'customer_id' => 'required|exists:customers,id',
            'group_id' => 'nullable|exists:groups,id',
            'sector' => 'required|string',
            'interest_cycle' => 'required|string|in:daily,weekly,bimonthly,monthly,quarterly,semi_annually,annually',
            'custom_fee_amounts' => 'nullable|array',
            'custom_fee_amounts.*' => 'nullable|numeric|min:0',
        ]);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        $this->validateProductLimits(                                                           $validated, $product);

        if ($redirect = $this->validateCustomFeesForProduct($request, $product)) {
            return $redirect;
        }
        $customFeeAmounts = $this->normalizedCustomFeeAmountsForProduct($request, $product);

        try {
            $convertedInterest = $this->convertInterestRate(
                (float) $validated['interest'],
                $validated['interest_cycle']
            );

            $previousStatus = $loanApplication->status;

            $loanApplication->fill([
                'product_id' => $validated['product_id'],
                'period' => $validated['period'],
                'interest' => $convertedInterest,
                'amount' => $validated['amount'],
                'interest_cycle' => $validated['interest_cycle'],
                'customer_id' => $validated['customer_id'],
                'group_id' => $validated['group_id'],
                'date_applied' => $validated['date_applied'],
                'sector' => $validated['sector'],
                'custom_fee_amounts' => $customFeeAmounts ?: null,
            ]);

            $interestAmount = $loanApplication->calculateInterestAmount($convertedInterest);
            $loanApplication->interest_amount = $interestAmount;
            $loanApplication->amount_total = $validated['amount'] + $interestAmount;

            // If loan was rejected, or edited after partial approval, reset to applied and clear approvals
            $resetApproval = $previousStatus === Loan::STATUS_REJECTED
                || in_array($previousStatus, [
                    Loan::STATUS_CHECKED,
                    Loan::STATUS_APPROVED,
                    Loan::STATUS_AUTHORIZED,
                ], true);
            if ($resetApproval) {
                $loanApplication->status = Loan::STATUS_APPLIED;
                LoanApproval::where('loan_id', $loanApplication->id)->delete();
            }

            $loanApplication->save();

            $message = $resetApproval
                ? 'Loan application updated. Status reset to Applied — approval must be completed again.'
                : 'Loan application updated successfully.';

            return redirect()->route('loans.by-status', 'applied')->with('success', $message);
        } catch (\Throwable $th) {
            return back()->withErrors([
                'error' => 'Failed to update loan application: ' . $th->getMessage()
            ])->withInput();
        }
    }

    /**
     * Dynamic approval method - handles all approval levels
     */
    public function approveLoan($encodedId, Request $request)
    {
        \Log::notice('approveLoan() called', [
            'encodedId' => $encodedId,
            'request_method' => $request->method(),
            'request_url' => $request->url(),
            'request_data' => $request->all()
        ]);

        try {
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                \Log::error('Failed to decode ID', ['encodedId' => $encodedId]);
                return redirect()->back()->withErrors(['Loan application not found.']);
            }

            $loan = Loan::findOrFail($decoded[0]);
            Log::info("=== LOAN EDIT METHOD ===", ["encoded_id" => $encodedId, "loan_id" => $loan->id, "loan_data" => ["amount" => $loan->amount, "interest" => $loan->interest, "period" => $loan->period, "interest_cycle" => $loan->interest_cycle, "customer_id" => $loan->customer_id, "group_id" => $loan->group_id, "product_id" => $loan->product_id, "bank_account_id" => $loan->bank_account_id, "loan_officer_id" => $loan->loan_officer_id, "sector" => $loan->sector]]);
            $user = auth()->user();

            // Debug information
            \Log::notice('Approval attempt context', [
                'loan_id' => $loan->id,
                'loan_status' => $loan->status,
                'user_id' => $user->id,
                'user_roles' => $user->roles->pluck('id')->toArray(),
                'product_approval_levels' => $loan->product->approval_levels ?? 'none',
                'approval_roles' => $loan->getApprovalRoles(),
                'next_level' => $loan->getNextApprovalLevel(),
                'next_role' => $loan->getNextApprovalRole(),
                'next_action' => $loan->getNextApprovalAction(),
                'can_approve' => $loan->canBeApprovedByUser($user),
                // 'has_approved' => $loan->hasUserApproved($user)
            ]);

            // Validate user has permission to approve
            if (!$loan->canBeApprovedByUser($user)) {
                \Log::warning('User does not have permission to approve', [
                    'user_id' => $user->id,
                    'required_role' => $loan->getNextApprovalRole(),
                    'user_roles' => $user->roles->pluck('id')->toArray()
                ]);
                return redirect()->back()->withErrors(['You do not have permission to approve this loan. Required role: ' . $loan->getApprovalLevelName($loan->getNextApprovalLevel())]);
            }

            // Check if user has already approved this loan
            // if ($loan->hasUserApproved($user)) {
            //     \Log::warning('User has already approved this loan', [
            //         'user_id' => $user->id,
            //         'loan_id' => $loan->id
            //     ]);
            //     return redirect()->back()->withErrors(['You have already approved this loan.']);
            // }

            $validated = $request->validate([
                'comments' => 'nullable|string|max:1000',
            ]);

            $nextAction = $loan->getNextApprovalAction();
            $nextLevel = $loan->getNextApprovalLevel();
            $roleName = $loan->getApprovalLevelName($nextLevel);

            \Log::notice('Computed next step', [
                'loan_id' => $loan->id,
                'nextAction' => $nextAction,
                'nextLevel' => $nextLevel,
                'roleName' => $roleName,
            ]);

            if (!$nextAction || !$nextLevel) {
                \Log::error('Unable to determine next approval action', [
                    'nextAction' => $nextAction,
                    'nextLevel' => $nextLevel
                ]);
                return redirect()->back()->withErrors(['Unable to determine next approval action.']);
            }

            $dcbDisburseCompleted = false;

            // If disbursing, require and set bank account and disbursement date before proceeding
            if ($nextAction === 'disburse') {
                if (!$request->filled('disbursement_method')) {
                    $request->merge(['disbursement_method' => 'bank']);
                }

                $this->normalizeDcbRequestInput($request, 'dcb_disburse', 'dcb');

                $rules = [
                    'disbursement_method' => 'required|in:bank,dcb',
                    'bank_account_id' => 'required|exists:bank_accounts,id',
                    'disbursement_date' => 'required|date|before_or_equal:today',
                ];
                if ($request->input('disbursement_method') === 'dcb') {
                    $rules['dcb_institution_code'] = 'required|string|max:64';
                    $rules['dcb_destination_account'] = 'required|string|max:64';
                    $rules['dcb_msisdn'] = 'required|string|max:20';
                    $rules['dcb_beneficiary_name'] = 'nullable|string|max:120';
                }
                $request->validate($rules);

                if (!$loan->bank_account_id || (int) $loan->bank_account_id !== (int) $request->input('bank_account_id')) {
                    $loan->update(['bank_account_id' => (int) $request->input('bank_account_id')]);
                    \Log::notice('Bank account set for disbursement', [
                        'loan_id' => $loan->id,
                        'bank_account_id' => (int) $request->input('bank_account_id'),
                    ]);
                }

                if ($request->input('disbursement_method') === 'dcb') {
                    $dcbService = app(\App\Services\DcbPaymentService::class);
                    if (!$dcbService->isEnabled()) {
                        throw new \Exception('DCB payments are not enabled. Configure DCB in Settings.');
                    }

                    $dcbResult = $dcbService->disburseLoan($loan, [
                        'institution_code' => $request->input('dcb_institution_code'),
                        'destination_account' => $request->input('dcb_destination_account'),
                        'msisdn' => $request->input('dcb_msisdn'),
                        'beneficiary_name' => $request->input('dcb_beneficiary_name'),
                        'disbursement_date' => $request->input('disbursement_date'),
                        'approval_comments' => $validated['comments'] ?? null,
                    ]);

                    if (!($dcbResult['success'] ?? false)) {
                        throw new \Exception($dcbResult['message'] ?? 'DCB disbursement failed.');
                    }

                    if ($dcbResult['pending'] ?? false) {
                        $pendingMessage = 'DCB transfer initiated. Customer may need to approve on their phone. The loan will be marked disbursed when payment is confirmed.';

                        if ($request->ajax() || $request->wantsJson()) {
                            return response()->json([
                                'success' => true,
                                'message' => $pendingMessage,
                                'pending' => true,
                                'client_reference' => $dcbResult['transaction']->client_reference ?? null,
                            ]);
                        }

                        return redirect()->back()->with('success', $pendingMessage);
                    }

                    $dcbDisburseCompleted = (bool) ($dcbResult['completed'] ?? true);
                    $loan->refresh();
                }
            }

            \Log::notice('Starting approval transaction', [
                'nextAction' => $nextAction,
                'nextLevel' => $nextLevel,
                'roleName' => $roleName
            ]);

            // Get disbursement date if provided
            $disbursementDate = $nextAction === 'disburse' && $request->has('disbursement_date')
                ? \Carbon\Carbon::parse($request->input('disbursement_date'))
                : null;

            DB::transaction(function () use ($loan, $user, $validated, $nextAction, $nextLevel, $roleName, $disbursementDate, $request, $dcbDisburseCompleted) {
                \Log::notice('Creating approval record', [
                    'loan_id' => $loan->id,
                    'user_id' => $user->id,
                    'role_name' => $roleName,
                    'approval_level' => $nextLevel,
                    'action' => $nextAction
                ]);

                // Update loan status based on action
                $oldStatus = $loan->status;
                switch ($nextAction) {
                    case 'check':
                        $loan->update(['status' => Loan::STATUS_CHECKED]);
                        $actionForRecord = 'checked';
                        break;
                    case 'approve':
                        $loan->update(['status' => Loan::STATUS_APPROVED]);
                        $actionForRecord = 'approved';
                        break;
                    case 'authorize':
                        $loan->update(['status' => Loan::STATUS_AUTHORIZED]);
                        $actionForRecord = 'authorized';
                        break;
                    case 'disburse':
                        if ($dcbDisburseCompleted) {
                            $actionForRecord = 'active';
                            break;
                        }

                        if ($loan->status === Loan::STATUS_ACTIVE) {
                            throw new \Exception('This loan has already been disbursed.');
                        }

                        if (app(LoanDisbursementGlService::class)->hasDisbursementGl($loan->id)) {
                            throw new \Exception('Disbursement accounting entries already exist for this loan.');
                        }

                        if (!$loan->bank_account_id) {
                            throw new \Exception('Bank account must be selected before disbursement. Please update the loan with a bank account first.');
                        }

                        $disburseDate = $disbursementDate ?? now();

                        app(LoanDisbursementCompletionService::class)->complete(
                            $loan,
                            $disburseDate,
                            $user->id,
                            $validated['comments'] ?? null
                        );

                        $actionForRecord = 'active';
                        break;
                }

                // Create approval record with the correct action value
                $approval = LoanApproval::create([
                    'loan_id' => $loan->id,
                    'user_id' => $user->id,
                    'role_name' => $roleName,
                    'approval_level' => $nextLevel,
                    'action' => $actionForRecord,
                    'comments' => $validated['comments'] ?? null,
                    'approved_at' => now(),
                ]);

                \Log::notice('Approval record created', [
                    'approval_id' => $approval->id,
                    'loan_id' => $loan->id,
                    'action' => $actionForRecord,
                    'new_status' => $loan->status,
                ]);

                \Log::notice('Loan status updated', [
                    'old_status' => $oldStatus,
                    'new_status' => $loan->fresh()->status,
                    'action' => $nextAction
                ]);
            });

            $actionMessages = [
                'check' => 'checked',
                'approve' => 'approved',
                'authorize' => 'authorized',
                'disburse' => 'disbursed'
            ];

            $message = $actionMessages[$nextAction] ?? 'processed';

            // Redirect based on the new status
            $newStatus = $loan->fresh()->status;
            \Log::notice('Approval completed successfully', [
                'new_status' => $newStatus,
                'message' => $message
            ]);

            // Return JSON response for AJAX requests
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Loan application {$message} successfully.",
                    'status' => $newStatus
                ]);
            }

            switch ($newStatus) {
                case 'checked':
                    return redirect()->route('loans.by-status', 'checked')->with('success', "Loan application {$message} successfully.");
                case 'approved':
                    return redirect()->route('loans.by-status', 'approved')->with('success', "Loan application {$message} successfully.");
                case 'authorized':
                    return redirect()->route('loans.by-status', 'authorized')->with('success', "Loan application {$message} successfully.");
                case 'active':
                    return redirect()->route('loans.by-status', 'active')->with('success', "Loan application {$message} successfully.");
                default:
                    return redirect()->route('loans.by-status', 'applied')->with('success', "Loan application {$message} successfully.");
            }
        } catch (\Throwable $th) {
            \Log::error('Approval failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString()
            ]);

            // Return JSON response for AJAX requests
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process loan: ' . $th->getMessage()
                ], 422);
            }

            return redirect()->back()->withErrors(['Failed to process loan: ' . $th->getMessage()]);
        }
    }

    /**
     * Reject loan application
     */
    public function rejectLoan($encodedId, Request $request)
    {
        try {
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                return redirect()->route('loans.application.index')->withErrors(['Loan application not found.']);
            }

            $loan = Loan::findOrFail($decoded[0]);
            Log::info("=== LOAN EDIT METHOD ===", ["encoded_id" => $encodedId, "loan_id" => $loan->id, "loan_data" => ["amount" => $loan->amount, "interest" => $loan->interest, "period" => $loan->period, "interest_cycle" => $loan->interest_cycle, "customer_id" => $loan->customer_id, "group_id" => $loan->group_id, "product_id" => $loan->product_id, "bank_account_id" => $loan->bank_account_id, "loan_officer_id" => $loan->loan_officer_id, "sector" => $loan->sector]]);
            $user = auth()->user();

            // Validate loan can be rejected
            if (!$loan->canBeRejected()) {
                return redirect()->back()->withErrors(['This loan cannot be rejected at its current status.']);
            }

            // Validate user has permission to reject
            if (!$loan->canBeApprovedByUser($user)) {
                return redirect()->back()->withErrors(['You do not have permission to reject this loan.']);
            }

            // Check if user has already approved this loan
            // if ($loan->hasUserApproved($user)) {
            //     return redirect()->back()->withErrors(['You have already approved this loan.']);
            // }

            $validated = $request->validate([
                'comments' => 'required|string|max:1000',
            ]);

            $nextLevel = $loan->getNextApprovalLevel();
            $roleName = $loan->getApprovalLevelName($nextLevel);

            DB::transaction(function () use ($loan, $user, $validated, $nextLevel, $roleName) {
                // Create rejection record
                LoanApproval::create([
                    'loan_id' => $loan->id,
                    'user_id' => $user->id,
                    'role_name' => $roleName,
                    'approval_level' => $nextLevel,
                    'action' => 'rejected',
                    'comments' => $validated['comments'],
                    'approved_at' => now(),
                ]);

                // Update loan status
                $loan->update(['status' => Loan::STATUS_REJECTED]);
            });

            return redirect()->route('loans.by-status', 'rejected')->with('success', 'Loan application rejected successfully.');
        } catch (\Throwable $th) {
            return redirect()->back()->withErrors(['Failed to reject loan: ' . $th->getMessage()]);
        }
    }

    /**
     * Legacy methods for backward compatibility
     */
    public function checkLoan($encodedId, Request $request)
    {
        return $this->approveLoan($encodedId, $request);
    }

    public function authorizeLoan($encodedId, Request $request)
    {
        return $this->approveLoan($encodedId, $request);
    }

    public function disburseLoan($encodedId, Request $request)
    {
        return $this->approveLoan($encodedId, $request);
    }

    public function applicationApprove($encodedId)
    {
        return $this->approveLoan($encodedId, request());
    }

    public function applicationReject($encodedId)
    {
        return $this->rejectLoan($encodedId, request());
    }

    public function applicationDelete($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Loan application not found.']);
        }

        try {
            DB::beginTransaction();
            $loanApplication = Loan::findOrFail($decoded[0]);

            if (!$loanApplication->canBeDeleted()) {
                DB::rollBack();
                return redirect()->route('loans.by-status', 'applied')->withErrors([
                    'error' => 'This loan cannot be deleted. Disbursed or completed loans cannot be removed.',
                ]);
            }

            $loanApplication->delete();
            DB::commit();
            return redirect()->route('loans.by-status', 'applied')->with('success', 'Loan application deleted successfully.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Failed to delete loan application: ' . $th->getMessage()]);
        }
    }

    private function processLoanDisbursement($loan, $disbursementDate = null)
    {
        $disburseDate = $disbursementDate ?? $loan->date_applied;

        app(LoanDisbursementGlService::class)->postDisbursement(
            $loan,
            $disburseDate,
            auth()->id(),
            auth()->user()->branch_id
        );
    }

    /**
     * Mark loan as defaulted
     */
    public function defaultLoan($encodedId, Request $request)
    {
        try {
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                return redirect()->route('loans.list')->withErrors(['Loan not found.']);
            }

            $loan = Loan::findOrFail($decoded[0]);
            Log::info("=== LOAN EDIT METHOD ===", ["encoded_id" => $encodedId, "loan_id" => $loan->id, "loan_data" => ["amount" => $loan->amount, "interest" => $loan->interest, "period" => $loan->period, "interest_cycle" => $loan->interest_cycle, "customer_id" => $loan->customer_id, "group_id" => $loan->group_id, "product_id" => $loan->product_id, "bank_account_id" => $loan->bank_account_id, "loan_officer_id" => $loan->loan_officer_id, "sector" => $loan->sector]]);
            $user = auth()->user();

            // Validate loan can be defaulted
            if ($loan->status !== Loan::STATUS_ACTIVE) {
                return redirect()->route('loans.list')->withErrors(['Only active loans can be marked as defaulted.']);
            }

            $validated = $request->validate([
                'comments' => 'required|string|max:1000',
            ]);

            DB::transaction(function () use ($loan, $user, $validated) {
                // Create default record
                LoanApproval::create([
                    'loan_id' => $loan->id,
                    'user_id' => $user->id,
                    'role_name' => 'System',
                    'approval_level' => 0,
                    'action' => 'defaulted',
                    'comments' => $validated['comments'],
                    'approved_at' => now(),
                ]);

                $loan->update([
                    'status' => Loan::STATUS_DEFAULTED,
                ]);
            });

            return redirect()->route('loans.list')->with('success', 'Loan marked as defaulted successfully.');
        } catch (\Throwable $th) {
            return redirect()->route('loans.list')->withErrors(['Failed to mark loan as defaulted: ' . $th->getMessage()]);
        }
    }

    /**
     * Change loan status (AJAX)
     */
    public function changeStatus(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'status' => 'required|string'
        ]);

        try {
            $decoded = Hashids::decode($validated['id']);
            if (empty($decoded)) {
                return response()->json(['success' => false, 'message' => 'Invalid loan id.'], 422);
            }

            $loan = Loan::findOrFail($decoded[0]);

            // Permission: require edit loan permission
            if (!auth()->user()->can('edit loan')) {
                return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            $allowed = ['applied', 'checked', 'approved', 'authorized', 'active', 'defaulted', 'rejected', 'completed', 'written_off', 'closed'];
            $newStatus = $validated['status'];
            if (!in_array($newStatus, $allowed, true)) {
                return response()->json(['success' => false, 'message' => 'Invalid status provided.'], 422);
            }

            $old = $loan->status;
            $loan->status = $newStatus;
            $loan->save();

            Log::info('Loan status changed via controller', ['loan_id' => $loan->id, 'from' => $old, 'to' => $newStatus, 'user_id' => auth()->id()]);

            return response()->json(['success' => true, 'message' => 'Loan status updated.', 'status' => $loan->status]);
        } catch (\Exception $e) {
            Log::error('Failed to change loan status', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to change status: ' . $e->getMessage()], 500);
        }
    }

    public function downloadTemplate()
    {
        return Excel::download(new LoanImportTemplateExport(), 'loan_import_template.xlsx');
    }

    // Legacy CSV template method (kept for reference)
    private function downloadTemplateCSV()
    {
        $headers = [
            'customer_name',
            'customer_no',
            'amount',
            'period',
            'interest',
            'date_applied',
            'interest_cycle',
            'loan_officer_id',
            'group_id',
            'sector'
        ];

        // Fetch all borrower customer numbers (scoped to the user's branch if present) with their groups
        $branchId = auth()->user()->branch_id ?? null;
        $customersQuery = \App\Models\Customer::with(['groups:id'])
            ->where('category', 'Borrower');
        if ($branchId) {
            $customersQuery->where('branch_id', $branchId);
        }
        $customers = $customersQuery->get(['id', 'name', 'customerNo', 'branch_id']);

        $fileName = 'loan_import_template.csv';
        $handle = fopen('php://output', 'w');

        // Set headers for download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        // Write CSV header
        fputcsv($handle, $headers);

        // Add note as the first data row under customer_name column
        fputcsv($handle, [
            'N.B: delete first customer name before upload',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ]);

        // Write one row per customer number with detected group_id and placeholders for other fields
        foreach ($customers as $customer) {
            $groupId = optional($customer->groups->first())->id ?? '';
            fputcsv($handle, [
                $customer->name,
                $customer->customerNo, // customer_no
                '',                    // amount
                '',                    // period
                '',                    // interest
                '',                    // date_applied (YYYY-MM-DD)
                'monthly',             // interest_cycle (default suggestion)
                '',                    // loan_officer (user id)
                $groupId,              // group_id (first group if exists)
                ''                     // sector
            ]);
        }

        fclose($handle);
        exit;
    }

        /**
     * Show loan restructuring form
     */
    public function restructure($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $loan = Loan::with(['customer', 'schedule.repayments'])->find($decoded[0]);
        if (!$loan) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        // Calculate outstanding amounts
        $schedules = $loan->schedule ?? collect();

        // Outstanding Principal: Original loan amount - total paid principal
        // This avoids rounding errors from summing schedule principal amounts
        $paidPrincipal = $schedules->sum(function ($schedule) {
            return $schedule->repayments->sum('principal');
        });
        $outstandingPrincipal = max(0, $loan->amount - $paidPrincipal);

        // Outstanding Interest: Total interest from unpaid schedules - paid interest
        $unpaidSchedules = $schedules->filter(function ($schedule) {
            return !$schedule->is_fully_paid;
        });
        $totalInterest = $unpaidSchedules->sum('interest');
        $paidInterest = $unpaidSchedules->sum(function ($schedule) {
            return $schedule->repayments->sum('interest');
        });
        $outstandingInterest = max(0, $totalInterest - $paidInterest);

        // Outstanding Penalty: Total penalty from all schedules - paid penalty
        $totalPenalty = $schedules->sum('penalty_amount');
        $paidPenalty = $schedules->sum(function ($schedule) {
            return $schedule->repayments->sum('penalt_amount');
        });
        $outstandingPenalty = max(0, $totalPenalty - $paidPenalty);

        $outstanding = [
            'principal' => round($outstandingPrincipal, 2),
            'interest' => round($outstandingInterest, 2),
            'penalty' => round($outstandingPenalty, 2),
        ];

        // Set the encoded ID for the loan object
        $loan->encodedId = $encodedId;

        return view('loans.restructure', compact('loan', 'outstanding'));
    }

    /**
     * Process loan restructuring
     */
    public function processRestructure(Request $request, $encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $loan = Loan::with(['customer', 'schedule.repayments', 'product'])->find($decoded[0]);
        if (!$loan) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        // Store old values for logging
        $oldPeriod = $loan->period;
        $oldInterestRate = $loan->interest;

        $request->validate([
            'new_tenure' => 'required|integer|min:1',
            'new_interest_rate' => 'required|numeric|min:0|max:100',
            'new_start_date' => 'required|date',
            'penalty_waived' => 'nullable|boolean',
        ]);

        try {
            $restructuringService = new LoanRestructuringService();

            $params = [
                'new_tenure' => $request->new_tenure,
                'new_interest_rate' => $request->new_interest_rate,
                'new_start_date' => $request->new_start_date,
                'penalty_waived' => $request->has('penalty_waived') && $request->penalty_waived,
            ];

            $userId = auth()->id() ?? 1;

            // Use the service to restructure the loan
            $restructuredLoan = $restructuringService->restructure($loan, $params, $userId);

            Log::info('Loan restructured via service', [
                'loan_id' => $restructuredLoan->id,
                'old_period' => $oldPeriod,
                'new_period' => $request->new_tenure,
                'old_interest_rate' => $oldInterestRate,
                'new_interest_rate' => $request->new_interest_rate,
                'penalty_waived' => $params['penalty_waived'],
            ]);

            // SMS to customer & company (if enabled in SMS settings)
            try {
                app(\App\Services\LoanSmsNotificationService::class)->sendDisbursementNotification(
                    $restructuredLoan,
                    "Mkopo wako umefanyiwa muundo mpya. Umepewa mkopo wa Tsh " . number_format($restructuredLoan->amount, 0) . " tarehe "
                    . \Carbon\Carbon::parse($restructuredLoan->date_applied)->format('d/m/Y') . ". Asante."
                );
            } catch (\Exception $smsEx) {
                Log::error('Failed to send restructuring SMS: ' . $smsEx->getMessage(), [
                    'restructured_loan_id' => $restructuredLoan->id ?? null,
                ]);
            }

            return redirect()->route('loans.show', Hashids::encode($restructuredLoan->id))
                ->with('success', 'Loan restructured successfully. A new loan has been created with the restructured terms.');
        } catch (\Exception $e) {
            Log::error('Loan restructuring failed: ' . $e->getMessage(), [
                'loan_id' => $loan->id,
                'error' => $e->getTraceAsString(),
            ]);

            return redirect()->back()
                ->withErrors(['error' => 'Failed to restructure loan: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Write off a loan (show confirmation or perform action)
     */
    public function writeoff($hashid)
    {
        $loanId = Hashids::decode($hashid)[0] ?? null;
        if (!$loanId) {
            abort(404, 'Invalid loan ID');
        }

        $loan = Loan::with(['customer', 'product', 'branch'])->findOrFail($loanId);

        return view('loans.writeoff', compact('loan', 'hashid'));
    }

    /**
     * Confirm and process loan write-off (POST handler)
     */
    public function confirmWriteoff(Request $request, $hashid)
    {
        $loanId = Hashids::decode($hashid)[0] ?? null;
        if (!$loanId) {
            abort(404, 'Invalid loan ID');
        }

        $loan = Loan::with(['product', 'repayments'])->findOrFail($loanId);

        // Basic validation – outstanding is computed on server
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'writeoff_type' => 'required|in:direct,provision',
        ]);

        $userId = auth()->id();

        $breakdown = $loan->getOutstandingBalanceBreakdown();
        $amount = $breakdown['total_balance'];

        if ($amount <= 0) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'This loan has no outstanding balance to write off.']);
        }

        DB::beginTransaction();
        try {
            $writeoff = \App\Models\LoanWriteoff::create([
                'loan_id' => $loan->id,
                'customer_id' => $loan->customer_id,
                'outstanding' => $amount,
                'reason' => $validated['reason'],
                'writeoff_type' => $validated['writeoff_type'],
                'createdby' => $userId,
            ]);

            $product = $loan->product;
            $branchId = auth()->user()->branch_id;

            if ($validated['writeoff_type'] === 'direct') {
                $debitAccount = $product->direct_writeoff_account_id;
            } else {
                $debitAccount = $product->provision_writeoff_account_id;
            }

            $penaltyAccountId = null;
            $penalty = $product->penalty ?? null;
            if ($penalty && $penalty->penalty_receivables_account_id) {
                $penaltyAccountId = $penalty->penalty_receivables_account_id;
            }

            $feeAccountId = null;
            if ($product->fees_ids) {
                $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
                if (is_array($feeIds) && count($feeIds) > 0) {
                    $fee = \DB::table('fees')->where('id', $feeIds[0])->first();
                    $feeAccountId = $fee->chart_account_id ?? null;
                }
            }

            $interestCreditAccount = $product->interest_receivable_account_id
                ?? $product->interest_revenue_account_id;

            $creditLines = [
                ['account' => $product->principal_receivable_account_id, 'amount' => $breakdown['outstanding_principal']],
                ['account' => $interestCreditAccount, 'amount' => $breakdown['outstanding_interest']],
                ['account' => $penaltyAccountId, 'amount' => $breakdown['outstanding_penalty']],
                ['account' => $feeAccountId, 'amount' => $breakdown['outstanding_fees']],
            ];

            \App\Models\GlTransaction::create([
                'chart_account_id' => $debitAccount,
                'customer_id' => $loan->customer_id,
                'amount' => $amount,
                'nature' => 'debit',
                'transaction_id' => $writeoff->id,
                'transaction_type' => 'Loan Writeoff',
                'date' => now(),
                'description' => 'Loan write-off',
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]);

            $creditedTotal = 0.0;
            foreach ($creditLines as $line) {
                if (!$line['account'] || $line['amount'] <= 0) {
                    continue;
                }
                \App\Models\GlTransaction::create([
                    'chart_account_id' => $line['account'],
                    'customer_id' => $loan->customer_id,
                    'amount' => $line['amount'],
                    'nature' => 'credit',
                    'transaction_id' => $writeoff->id,
                    'transaction_type' => 'Loan Writeoff',
                    'date' => now(),
                    'description' => 'Loan write-off',
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);
                $creditedTotal += (float) $line['amount'];
            }

            $remainder = round($amount - $creditedTotal, 2);
            if ($remainder > 0 && $product->principal_receivable_account_id) {
                \App\Models\GlTransaction::create([
                    'chart_account_id' => $product->principal_receivable_account_id,
                    'customer_id' => $loan->customer_id,
                    'amount' => $remainder,
                    'nature' => 'credit',
                    'transaction_id' => $writeoff->id,
                    'transaction_type' => 'Loan Writeoff',
                    'date' => now(),
                    'description' => 'Loan write-off (balance)',
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);
            }

            $loan->schedule()->with('repayments')->get()->each(function ($schedule) {
                if (!$schedule->is_fully_paid && $schedule->status !== 'restructured') {
                    $schedule->update(['status' => 'written_off']);
                }
            });

            $loan->update(['status' => 'written_off']);

            DB::commit();

            return redirect()
                ->route('loans.show', $hashid)
                ->with('success', 'Loan written off successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Loan write-off failed', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->withErrors(['error' => 'Failed to write off loan: ' . $e->getMessage()]);
        }
    }

    /**
     * Download opening balance template (Excel with interest_cycle & sector dropdowns)
     */
    public function downloadOpeningBalanceTemplate(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:loan_products,id',
        ]);

        $productId = (int) $request->get('product_id');
        $filename = 'opening_balance_template_'.date('Y-m-d').'.xlsx';

        return Excel::download(new OpeningBalanceTemplateExport($productId), $filename);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function parseOpeningBalanceSpreadsheet(string $path, string $extension): array
    {
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $matrix = $sheet->toArray(null, true, true, false);
        } else {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false || count($lines) < 1) {
                throw new \InvalidArgumentException('File is empty or unreadable.');
            }
            $matrix = array_map('str_getcsv', $lines);
        }

        $headerRowIndex = null;
        foreach ($matrix as $i => $row) {
            $normalized = array_map(fn ($h) => strtolower(preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $h))), $row);
            if (in_array('customer_no', $normalized, true)) {
                $headerRowIndex = $i;
                break;
            }
        }

        if ($headerRowIndex === null) {
            throw new \InvalidArgumentException('Could not find header row (customer_no column).');
        }

        $originalHeaders = array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $h)), $matrix[$headerRowIndex]);
        $dataRows = array_slice($matrix, $headerRowIndex + 1);

        return [$originalHeaders, $dataRows];
    }

    /**
     * @param  array<int, array<int, mixed>>  $dataRows
     * @param  array<int, string>  $headers
     * @return array<int, array<int, mixed>>
     */
    private function filterEligibleOpeningBalanceRows(array $dataRows, array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $key = strtolower(trim((string) $h));
            if ($key !== '') {
                $map[$key] = $i;
            }
        }

        $eligible = [];
        foreach ($dataRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $customerNo = trim((string) ($row[$map['customer_no'] ?? 0] ?? ''));
            $amount = floatval($row[$map['amount'] ?? 4] ?? 0);
            $interest = floatval($row[$map['interest'] ?? 5] ?? 0);
            $period = intval($row[$map['period'] ?? 6] ?? 0);
            if ($customerNo !== '' && $amount > 0 && $interest > 0 && $period > 0) {
                $eligible[] = $row;
            }
        }

        return $eligible;
    }

    /**
     * Store opening balance loans (chunked queue jobs with progress tracking).
     */
    public function storeOpeningBalance(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'branch_id' => 'required|exists:branches,id',
            'chart_account_id' => 'required|exists:chart_accounts,id',
            'deduct_fees_on_release' => 'nullable|boolean',
            'csv_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:20480',
        ]);

        $validated['deduct_fees_on_release'] = $request->boolean('deduct_fees_on_release');

        try {
            $file = $request->file('csv_file');
            $extension = strtolower($file->getClientOriginalExtension());

            [$originalHeaders, $csvData] = $this->parseOpeningBalanceSpreadsheet($file->getPathname(), $extension);

            $headers = array_map(fn ($h) => strtolower((string) $h), $originalHeaders);

            $requiredHeaders = [
                'customer_no',
                'customer_name',
                'group_id',
                'group_name',
                'amount',
                'interest',
                'period',
                'date_applied',
                'first_repayment_date',
                'interest_cycle',
                'sector',
                'amount_paid',
            ];
            foreach ($requiredHeaders as $required) {
                if (! in_array($required, $headers, true)) {
                    $message = 'Invalid file format. Missing column: '.$required.'. Please download the latest Excel template.';
                    if ($request->ajax()) {
                        return response()->json(['success' => false, 'message' => $message], 422);
                    }

                    return redirect()->back()->withErrors(['csv_file' => $message]);
                }
            }

            $eligibleRows = $this->filterEligibleOpeningBalanceRows($csvData, $originalHeaders);

            if (count($eligibleRows) < 1) {
                $message = 'No eligible loan rows found. Fill amount, interest, and period for each customer you want to import.';
                if ($request->ajax()) {
                    return response()->json(['success' => false, 'message' => $message], 422);
                }

                return redirect()->back()->withErrors(['csv_file' => $message]);
            }

            unset($validated['csv_file']);

            $importId = 'ob_'.Str::uuid();
            $totalRows = count($eligibleRows);
            $userId = (int) auth()->id();

            Cache::put($importId, [
                'status' => 'processing',
                'current' => 0,
                'total' => $totalRows,
                'success' => 0,
                'failed' => 0,
                'percentage' => 0,
                'errors' => [],
            ], 7200);

            $chunkSize = 50;
            $chunks = array_chunk($eligibleRows, $chunkSize);
            $totalChunks = count($chunks);
            $useSyncQueue = config('queue.default') === 'sync';

            // Start worker before dispatching (same pattern as customer bulk upload).
            $this->ensureQueueWorkerRunning();

            Log::info('Opening balance upload queued', [
                'import_id' => $importId,
                'total_rows' => $totalRows,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'queue' => config('queue.default'),
                'deduct_fees_on_release' => (bool) ($validated['deduct_fees_on_release'] ?? false),
            ]);

            foreach ($chunks as $chunkIndex => $chunk) {
                $job = new BulkLoanCreationJob(
                    $chunk,
                    $validated,
                    $userId,
                    $originalHeaders,
                    $chunkIndex,
                    $totalChunks,
                    $importId
                );

                if ($useSyncQueue) {
                    $job->handle();
                } else {
                    BulkLoanCreationJob::dispatch(
                        $chunk,
                        $validated,
                        $userId,
                        $originalHeaders,
                        $chunkIndex,
                        $totalChunks,
                        $importId
                    );
                }
            }

            $progress = Cache::get($importId, []);
            $message = $useSyncQueue
                ? 'Opening balance processing completed.'
                : 'Opening balance processing started. Jobs are running in the background.';

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'import_id' => $importId,
                    'total' => $totalRows,
                    'status' => $progress['status'] ?? ($useSyncQueue ? 'completed' : 'processing'),
                    'success_count' => $progress['success'] ?? 0,
                    'failed_count' => $progress['failed'] ?? 0,
                ]);
            }

            return redirect()->back()
                ->with('success', $message)
                ->with('import_id', $importId);
        } catch (\Exception $e) {
            Log::error('Opening balance processing failed: '.$e->getMessage());

            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process opening balance: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()->withErrors(['error' => 'Failed to process opening balance: '.$e->getMessage()]);
        }
    }

    private function ensureQueueWorkerRunning(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        $command = "ps aux | grep '[a]rtisan queue:work' | grep -v grep";
        exec($command, $output, $returnCode);
        if (! empty($output) && $returnCode === 0) {
            Log::info('Queue worker already running for opening balance');

            return;
        }

        $artisanPath = base_path('artisan');
        $logPath = storage_path('logs/queue-worker.log');
        $pidFile = storage_path('logs/queue-worker.pid');
        $cmd = sprintf(
            'nohup php %s queue:work --sleep=1 --tries=3 --timeout=3600 >> %s 2>&1 & echo $! > %s',
            escapeshellarg($artisanPath),
            escapeshellarg($logPath),
            escapeshellarg($pidFile)
        );
        exec($cmd);
        usleep(500000);
        Log::info('Started queue worker for opening balance upload', ['user_id' => auth()->id()]);
    }

    /**
     * Process settle repayment for a loan
     */
    public function settleRepayment(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payment_date' => 'required|date',
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            $loan = Loan::with(['product', 'customer', 'schedule'])->findOrFail($id);

            // Check if loan is active
            if ($loan->status !== Loan::STATUS_ACTIVE) {
                return redirect()->back()->withErrors(['error' => 'Only active loans can be settled.']);
            }

            // Get bank account for chart account ID
            $bankAccount = \App\Models\BankAccount::findOrFail($request->bank_account_id);

            $paymentData = [
                'bank_chart_account_id' => $bankAccount->chart_account_id,
                'bank_account_id' => $request->bank_account_id,
                'payment_date' => $request->payment_date,
                'notes' => $request->notes
            ];

            // Use LoanRepaymentService to process the settle repayment
            $repaymentService = new \App\Services\LoanRepaymentService();
            $result = $repaymentService->processSettleRepayment($loan->id, $request->amount, $paymentData);

            if ($result['success']) {
                $message = "Loan settled successfully. ";
                $message .= "Interest paid: TZS " . number_format($result['current_interest_paid'], 2) . ". ";
                $message .= "Principal paid: TZS " . number_format($result['total_principal_paid'], 2) . ".";

                if ($result['loan_closed']) {
                    $message .= " Loan has been closed.";
                }

                return redirect()->back()->with('success', $message);
            } else {
                return redirect()->back()->withErrors(['error' => 'Failed to process settle repayment.']);
            }
        } catch (\Exception $e) {
            Log::error('Settle repayment failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to process settle repayment: ' . $e->getMessage()]);
        }
    }

    /**
     * Export comprehensive loan details as PDF
     */
    public function exportLoanDetails($encodedId)
    {
        try {
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                return redirect()->route('loans.index')->withErrors(['Loan not found.']);
            }

            $loan = Loan::with([
                'customer.region',
                'customer.district',
                'customer.branch',
                'customer.company',
                'customer.user',
                'product',
                'bankAccount',
                'group',
                'loanFiles',
                'schedule' => function ($query) {
                    $query->orderBy('due_date', 'asc');
                },
                'schedule.repayments',
                'repayments' => function ($query) {
                    $query->orderBy('created_at', 'asc');
                },
                'repayments.chartAccount',
                'approvals.user',
                'approvals' => function ($query) {
                    $query->orderBy('approval_level', 'asc');
                },
                'guarantors',
                'collaterals',
                'branch',
                'loanOfficer'
            ])->findOrFail($decoded[0]);

            // Allow exporting both active and completed loans.
            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_COMPLETE], true)) {
                return redirect()->back()->withErrors(['error' => 'Only active or completed loans can be exported.']);
            }

            // Get loan fees if they exist
            $loanFees = [];
            if ($loan->product && $loan->product->fees_ids) {
                $feeIds = is_array($loan->product->fees_ids) ? $loan->product->fees_ids : json_decode($loan->product->fees_ids, true);
                if ($feeIds) {
                    $loanFees = Fee::whereIn('id', $feeIds)->get();
                }
            }

            // Get loan penalties if they exist
            $loanPenalties = [];
            if ($loan->product && $loan->product->penalty_ids) {
                $penaltyIds = is_array($loan->product->penalty_ids) ? $loan->product->penalty_ids : json_decode($loan->product->penalty_ids, true);
                if ($penaltyIds) {
                    $loanPenalties = Penalty::whereIn('id', $penaltyIds)->get();
                }
            }

            // Calculate loan statistics from repayments
            $totalPaid = $loan->repayments->sum(function ($repayment) {
                return $repayment->principal + $repayment->interest + $repayment->fee_amount + $repayment->penalt_amount;
            });

            $totalPrincipalPaid = $loan->repayments->sum('principal');
            $totalInterestPaid = $loan->repayments->sum('interest');
            $totalFeesPaid = $loan->repayments->sum('fee_amount');
            $totalPenaltiesPaid = $loan->repayments->sum('penalt_amount');

            $totalPenaltyCharged = $loan->schedule->sum('penalty_amount');
            $totalOutstandingPenalty = 0;
            foreach ($loan->schedule as $schedule) {
                $penaltyPaidOnSchedule = $schedule->relationLoaded('repayments') && $schedule->repayments
                    ? $schedule->repayments->sum('penalt_amount')
                    : 0;
                $totalOutstandingPenalty += max(0, (float) $schedule->penalty_amount - (float) $penaltyPaidOnSchedule);
            }

            // Calculate fees received through receipts
            $feesReceivedThroughReceipts = 0;
            $receipts = $loan->receipts()->with('receiptItems')->get();
            foreach ($receipts as $receipt) {
                foreach ($receipt->receiptItems as $item) {
                    // Check if this is a fee-related account
                    $chartAccount = \App\Models\ChartAccount::find($item->chart_account_id);
                    if ($chartAccount && (
                        stripos($chartAccount->account_name, 'fee') !== false ||
                        stripos($chartAccount->account_name, 'income') !== false ||
                        stripos($chartAccount->account_name, 'service') !== false
                    )) {
                        $feesReceivedThroughReceipts += $item->amount;
                    }
                }
            }

            // Add fees received through receipts to total fees paid
            $totalFeesPaid += $feesReceivedThroughReceipts;
            $totalPaid += $feesReceivedThroughReceipts;

            $remainingBalance = $loan->amount_total - $totalPaid;
            $remainingPrincipal = $loan->amount - $totalPrincipalPaid;

            $data = [
                'loan' => $loan,
                'loanFees' => $loanFees,
                'loanPenalties' => $loanPenalties,
                'receipts' => $receipts,
                'feesReceivedThroughReceipts' => $feesReceivedThroughReceipts,
                'totalPaid' => $totalPaid,
                'totalPrincipalPaid' => $totalPrincipalPaid,
                'totalInterestPaid' => $totalInterestPaid,
                'totalFeesPaid' => $totalFeesPaid,
                'totalPenaltiesPaid' => $totalPenaltiesPaid,
                'totalPenaltyCharged' => $totalPenaltyCharged,
                'totalOutstandingPenalty' => $totalOutstandingPenalty,
                'remainingBalance' => $remainingBalance,
                'remainingPrincipal' => $remainingPrincipal,
                'exportDate' => now()->format('Y-m-d H:i:s'),
                'company' => auth()->user()->company
            ];

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('loans.export-details', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = 'Loan_Statement_'.$loan->loanNo.'_'.now()->format('Y-m-d').'.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('Export loan details failed: '.$e->getMessage(), [
                'loan_id' => $decoded[0] ?? null,
                'gd_loaded' => extension_loaded('gd'),
            ]);
            $message = str_contains($e->getMessage(), 'GD extension')
                ? 'PDF export failed: PHP GD extension is not installed. Ask your server admin to install php-gd, then restart PHP/web server.'
                : 'Failed to export loan details: '.$e->getMessage();

            return redirect()->back()->withErrors(['error' => $message]);
        }
    }

    /**
     * Export loan repayment schedule as PDF (Loan Repayment Schedule document).
     */
    public function exportSchedulePdf($encodedId)
    {
        try {
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                return redirect()->route('loans.index')->withErrors(['Loan not found.']);
            }

            $loan = Loan::with([
                'customer',
                'product',
                'branch.company',
                'schedule' => function ($query) {
                    $query->orderBy('due_date', 'asc');
                },
                'loanOfficer'
            ])->findOrFail($decoded[0]);

            if (!$loan->schedule || $loan->schedule->isEmpty()) {
                return redirect()->back()->withErrors(['error' => 'This loan has no schedule to export.']);
            }

            $branch = $loan->branch;
            $company = ($branch && $branch->relationLoaded('company') && $branch->company)
                ? $branch->company
                : (auth()->check() ? auth()->user()->company : null);

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('loans.schedule-pdf', compact('loan', 'company', 'branch'));
            $pdf->setPaper('A4', 'portrait');

            $filename = 'Loan_Repayment_Schedule_' . ($loan->loanNo ?? $loan->id) . '_' . now()->format('Y-m-d') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('Export schedule PDF failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to export schedule: ' . $e->getMessage()]);
        }
    }

    private function buildProductFeesMetaMap(iterable $products): array
    {
        $meta = [];
        foreach ($products as $p) {
            $meta[$p->id] = $p->getFeesAttribute()->map(function ($fee) {
                return [
                    'id' => $fee->id,
                    'name' => $fee->name,
                    'fee_type' => $fee->fee_type,
                ];
            })->values()->all();
        }

        return $meta;
    }

    private function validateCustomFeesForProduct(Request $request, LoanProduct $product): ?\Illuminate\Http\RedirectResponse
    {
        $feeIds = is_array($product->fees_ids) ? $product->fees_ids : (json_decode($product->fees_ids ?? '[]', true) ?: []);
        $customFees = Fee::whereIn('id', $feeIds)->where('fee_type', 'custom')->where('status', 'active')->get();
        if ($customFees->isEmpty()) {
            return null;
        }
        $input = $request->input('custom_fee_amounts', []);
        foreach ($customFees as $fee) {
            if (!array_key_exists($fee->id, $input) && !array_key_exists((string) $fee->id, $input)) {
                return redirect()->back()->withErrors([
                    'custom_fee_amounts.' . $fee->id => 'Amount is required for custom fee: ' . $fee->name,
                ])->withInput();
            }
            $val = $input[$fee->id] ?? $input[(string) $fee->id];
            if (!is_numeric($val) || (float) $val < 0) {
                return redirect()->back()->withErrors([
                    'custom_fee_amounts.' . $fee->id => 'Enter a valid non-negative amount for: ' . $fee->name,
                ])->withInput();
            }
        }

        return null;
    }

    /**
     * @return array<int, float>
     */
    private function normalizedCustomFeeAmountsForProduct(Request $request, LoanProduct $product): array
    {
        $feeIds = is_array($product->fees_ids) ? $product->fees_ids : (json_decode($product->fees_ids ?? '[]', true) ?: []);
        $customFeeIds = Fee::whereIn('id', $feeIds)->where('fee_type', 'custom')->pluck('id');
        $input = Fee::normalizeCustomFeeAmountsMap($request->input('custom_fee_amounts', []));
        $out = [];
        foreach ($customFeeIds as $fid) {
            $out[(int) $fid] = (float) ($input[(int) $fid] ?? $input[(string) $fid] ?? 0);
        }

        return $out;
    }
    private function directPenaltyReceiptRowsForLoan(Loan $loan)
    {
        $penaltyIds = data_get($loan, 'product.penalty_ids', []);
        if (is_string($penaltyIds)) {
            $decoded = json_decode($penaltyIds, true);
            $penaltyIds = is_array($decoded) ? $decoded : [];
        }

        $penaltyIds = collect($penaltyIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
        if ($penaltyIds->isEmpty()) {
            return collect();
        }

        $penaltyChartAccountIds = Penalty::query()
            ->whereIn('id', $penaltyIds)
            ->get(['penalty_receivables_account_id', 'penalty_income_account_id'])
            ->flatMap(function ($penalty) {
                return [
                    data_get($penalty, 'penalty_receivables_account_id'),
                    data_get($penalty, 'penalty_income_account_id'),
                ];
            })
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($penaltyChartAccountIds->isEmpty()) {
            return collect();
        }

        $customerLoanCount = Loan::query()
            ->where('customer_id', $loan->customer_id)
            ->count();
        $loanTokens = array_filter([
            strtolower((string) $loan->loanNo),
            'loan-' . (int) $loan->id,
            (string) $loan->id,
        ]);

        $receipts = Receipt::with(['receiptItems', 'bankAccount'])
            ->where('reference_type', 'manual')
            ->where('payee_type', 'customer')
            ->where('customer_id', $loan->customer_id)
            ->whereDoesntHave('repayments')
            ->whereHas('receiptItems', function ($query) use ($penaltyChartAccountIds) {
                $query->whereIn('chart_account_id', $penaltyChartAccountIds);
            })
            ->orderByDesc('date')
            ->get();

        return $receipts->map(function ($receipt) use ($penaltyChartAccountIds, $customerLoanCount, $loanTokens) {
            $receiptText = strtolower(implode(' ', array_filter([
                (string) ($receipt->reference ?? ''),
                (string) ($receipt->reference_number ?? ''),
                (string) ($receipt->description ?? ''),
            ])));

            $matchesLoan = $customerLoanCount <= 1;
            if (!$matchesLoan) {
                foreach ($loanTokens as $token) {
                    if ($token !== '' && str_contains($receiptText, $token)) {
                        $matchesLoan = true;
                        break;
                    }
                }
            }

            if (!$matchesLoan) {
                return null;
            }

            $penaltyPaid = (float) collect($receipt->receiptItems ?? [])->sum(function ($item) use ($penaltyChartAccountIds) {
                $accountId = (int) ($item->chart_account_id ?? 0);

                return in_array($accountId, $penaltyChartAccountIds->all(), true)
                    ? (float) ($item->amount ?? 0)
                    : 0.0;
            });
            if ($penaltyPaid <= 0) {
                return null;
            }

            return (object) [
                'receipt_id' => (int) $receipt->id,
                'encoded_receipt_id' => \Vinkla\Hashids\Facades\Hashids::encode((int) $receipt->id),
                'payment_date' => $receipt->date,
                'principal' => 0.0,
                'interest' => 0.0,
                'penalty' => round($penaltyPaid, 2),
                'fee' => 0.0,
                'total_paid' => round($penaltyPaid, 2),
                'bank_account' => data_get($receipt, 'bankAccount.name', 'N/A'),
                'reference' => $receipt->reference_number ?: ('RV-' . $receipt->id),
            ];
        })->filter()->values();
    }

    private function resolveLoanDeleteReturnStatus(?Loan $loan, ?string $requestedStatus): string
    {
        $validStatuses = ['applied', 'checked', 'approved', 'authorized', 'active', 'defaulted', 'rejected', 'completed', 'restructured'];

        if ($requestedStatus && in_array($requestedStatus, $validStatuses, true)) {
            return $requestedStatus;
        }

        if ($loan && in_array($loan->status, $validStatuses, true)) {
            return $loan->status;
        }

        return 'active';
    }
}
