<?php

namespace App\Http\Controllers;

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
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\LoanRestructuringService;
use App\Jobs\BulkLoanImportJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\FailedLoanImportExport;
use App\Exports\LoanImportTemplateExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class LoanController extends Controller
{

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

        // Get release-date fees for this loan product
        $fees = [];
        $totalFees = 0;
        if ($loan->product && $loan->product->fees_ids) {
            $feeIds = is_array($loan->product->fees_ids) ? $loan->product->fees_ids : json_decode($loan->product->fees_ids, true);
            if (is_array($feeIds)) {
                $releaseFees = \DB::table('fees')
                    ->whereIn('id', $feeIds)
                    ->where('deduction_criteria', 'charge_fee_on_release_date')
                    ->where('status', 'active')
                    ->get();
                foreach ($releaseFees as $fee) {
                    $amount = (float) $fee->amount;
                    $calculated = $fee->fee_type === 'percentage'
                        ? ($loan->amount * $amount / 100)
                        : $amount;
                    $fees[] = (object) [
                        'name' => $fee->name,
                        'fee_type' => $fee->fee_type,
                        'calculated_amount' => $calculated
                    ];
                    $totalFees += $calculated;
                }
            }
        }

        // Fetch required data for the receipt form
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $customers = Customer::all();
        // Get fees with deduction_criteria = 'do_not_include_in_loan_schedule'
        $excludedFees = \DB::table('fees')
            ->where('deduction_criteria', 'do_not_include_in_loan_schedule')
            ->where('status', 'active')
            ->get();

        // Get unique chart account IDs from excluded fees
        $uniqueChartAccountIds = $excludedFees->pluck('chart_account_id')->unique()->filter();

        // Also get common income accounts for loan-related transactions
        $incomeAccountIds = \DB::table('chart_accounts')
            ->whereIn('account_name', ['Interest income', 'FEE INCOME', 'Penalty Income', 'Service income', 'Other income'])
            ->pluck('id');

        // Combine and get unique chart accounts
        $allChartAccountIds = $uniqueChartAccountIds->merge($incomeAccountIds)->unique();

        // Prepare chart accounts
        $chartAccounts = collect();
        $chartAccountData = ChartAccount::whereIn('id', $allChartAccountIds)->get();

        foreach ($chartAccountData as $account) {
            $chartAccounts->push((object) [
                'id' => $account->id,
                'account_name' => $account->account_name,
                'account_code' => $account->account_code,
                'fee_name' => null, // Not specific to one fee
                'fee_type' => null,
                'fee_amount' => 0
            ]);
        }

        return view('loans.fees_receipt', compact('loan', 'fees', 'totalFees', 'bankAccounts', 'customers', 'chartAccounts'));
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
            'line_items.*.chart_account_id' => 'required|exists:chart_accounts,id',
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
                $receiptItem = new \App\Models\ReceiptItem();
                $receiptItem->receipt_id = $receipt->id;
                $receiptItem->chart_account_id = $item['chart_account_id'];
                $receiptItem->amount = $item['amount'];
                $receiptItem->description = $item['description'] ?? null;
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
                GlTransaction::create([
                    'chart_account_id' => $item['chart_account_id'],
                    'customer_id' => $customerId,
                    'amount' => $item['amount'],
                    'nature' => 'credit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $validated['date'],
                    'description' => $item['description'] ?? ('Loan Fee for Loan #' . ($loan->loanNo ?? $loan->id)),
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

        // Get data for import modal
        $branches = Branch::all();
        $loanProducts = LoanProduct::all();
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();

        return view('loans.list', compact('loans', 'branches', 'loanProducts', 'bankAccounts'));
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

                    // Edit action (disallow for authorized and approved)
                    if (auth()->user()->can('edit loan')) {
                        if (!in_array($loan->status, ['authorized', 'approved'])) {
                            $editUrl = in_array($loan->status, ['applied', 'rejected'])
                                ? route('loans.application.edit', $encodedId)
                                : route('loans.edit', $encodedId);
                            $actions .= '<a href="' . $editUrl . '" class="btn btn-sm btn-outline-primary me-1" title="Edit"><i class="bx bx-edit"></i></a>';
                        }

                        // Fix & Re-apply for rejected applications
                        if ($loan->status === 'rejected') {
                            $fixUrl = route('loans.application.edit', $encodedId);
                            $actions .= '<a href="' . $fixUrl . '" class="btn btn-sm btn-outline-success me-1" title="Fix & Re-apply"><i class="bx bx-refresh"></i></a>';
                        }
                    }

                    // Receipt action for applied loans
                    if ($loan->status === 'applied' && auth()->user()->can('create receipt voucher')) {
                        $actions .= '<a href="' . route('accounting.loans.create-receipt', $encodedId) . '" class="btn btn-sm btn-outline-success me-1" title="Create Receipt"><i class="bx bx-receipt"></i></a>';
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

                    // Delete action (disallow for authorized and approved)
                    if (auth()->user()->can('delete loan')) {
                        if (!in_array($loan->status, ['authorized', 'approved'])) {
                            $actions .= '<button class="btn btn-sm btn-outline-danger delete-btn" data-id="' . $encodedId . '" data-name="' . e(optional($loan->customer)->name ?? 'Unknown') . '" title="Delete"><i class="bx bx-trash"></i></button>';
                        }
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
        // Create Loan
        $loan = Loan::create([
            'product_id' => $product->id,
            'period' => $validated['period'],
            'interest' => $validated['interest'],
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
        ]);

        // Calculate interest and repayment dates
        $interestAmount = $loan->calculateInterestAmount($validated['interest']);
        $repaymentDates = $loan->getRepaymentDates();

        // Update Loan with totals and schedule
        $loan->update([
            'interest_amount' => $interestAmount,
            'amount_total' => $loan->amount + $interestAmount,
            'first_repayment_date' => $repaymentDates['first_repayment_date'],
            'last_repayment_date' => $repaymentDates['last_repayment_date'],
        ]);

        // Generate repayment schedule
        $loan->generateRepaymentSchedule($validated['interest']);

        // Post matured interest for past loans
        $loan->postMaturedInterestForPastLoan();

        // Record Payment
        $bankAccount = BankAccount::findOrFail($accountId);
        $notes = "Being disbursement for loan of {$product->name}, paid to {$loan->customer->name}, TSHS.{$validated['amount']}";
        $principalReceivable = optional($product->principalReceivableAccount)->id;

        if (!$principalReceivable) {
            throw new \Exception('Principal receivable account not set for this loan product.');
        }

        $payment = Payment::create([
            'reference' => $loan->id,
            'reference_type' => 'Loan Payment',
            'reference_number' => null,
            'date' => $validated['date_applied'],
            'amount' => $validated['amount'],
            'description' => $notes,
            'user_id' => $userId,
            'payee_type' => 'customer',
            'customer_id' => $validated['customer_id'],
            'bank_account_id' => $accountId,
            'branch_id' => $branchId,
            'approved' => true,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        PaymentItem::create([
            'payment_id' => $payment->id,
            'chart_account_id' => $principalReceivable,
            'amount' => $validated['amount'],
            'description' => $notes,
        ]);

        // GL Transactions
        // Calculate sum of release-date fees
        $releaseFeeTotal = 0;
        if ($product && $product->fees_ids) {
            info('fees_ids: ' . json_encode($product->fees_ids));
            $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
            if (is_array($feeIds)) {
                $releaseFees = \DB::table('fees')
                    ->whereIn('id', $feeIds)
                    ->where('deduction_criteria', 'charge_fee_on_release_date')
                    ->where('status', 'active')
                    ->get();
                foreach ($releaseFees as $fee) {
                    $feeAmount = (float) $fee->amount;
                    $feeType = $fee->fee_type;
                    $releaseFeeTotal += $feeType === 'percentage'
                        ? ((float) $validated['amount'] * (float) $feeAmount / 100)
                        : (float) $feeAmount;
                    \Log::info("Fee: {$fee->name}, Type: $feeType, Amount: $feeAmount, Calculated: " . ($feeType === 'percentage' ? ((float) $validated['amount'] * (float) $feeAmount / 100) : (float) $feeAmount));
                }
            }
        }

        \Log::info("Total release fees: $releaseFeeTotal");

        $disbursementAmount = $validated['amount'] - $releaseFeeTotal;

        GlTransaction::insert([
            [
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $disbursementAmount,
                'nature' => 'credit',
                'transaction_id' => $loan->id,
                'transaction_type' => 'Loan Disbursement',
                'date' => $validated['date_applied'],
                'description' => $notes,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ],
            [
                'chart_account_id' => $principalReceivable,
                'customer_id' => $loan->customer_id,
                'amount' => $validated['amount'],
                'nature' => 'debit',
                'transaction_id' => $loan->id,
                'transaction_type' => 'Loan Disbursement',
                'date' => $validated['date_applied'],
                'description' => $notes,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]
        ]);

        // Post Penalty Amount to GL (if exists)
        $penalty = $product->penalty;
        $penaltyAmount = LoanSchedule::where('loan_id', $loan->id)->sum('penalty_amount');

        if ($penaltyAmount > 0) {
            $receivableId = $penalty->penalty_receivables_account_id;
            $incomeId = $penalty->penalty_income_account_id;

            if (!$receivableId || !$incomeId) {
                throw new \Exception('Penalty chart accounts not configured.');
            }

            GlTransaction::insert([
                [
                    'chart_account_id' => $receivableId,
                    'customer_id' => $loan->customer_id,
                    'amount' => $penaltyAmount,
                    'nature' => 'debit',
                    'transaction_id' => $loan->id,
                    'transaction_type' => 'Loan Penalty',
                    'date' => $validated['date_applied'],
                    'description' => $notes,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ],
                [
                    'chart_account_id' => $incomeId,
                    'customer_id' => $loan->customer_id,
                    'amount' => $penaltyAmount,
                    'nature' => 'credit',
                    'transaction_id' => $loan->id,
                    'transaction_type' => 'Loan Penalty',
                    'date' => $validated['date_applied'],
                    'description' => $notes,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]
            ]);
        }
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

        // Get data for import modal
        $branches = \App\Models\Branch::all();
        $loanProducts = \App\Models\LoanProduct::all();
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();

        return view('loans.list', compact('loans', 'pageTitle', 'status', 'branches', 'loanProducts', 'bankAccounts'));
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
        return view('loans.create', compact('customers', 'products', 'sectors', 'bankAccounts', 'loanOfficers', 'interestCycles'));
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
            ]);

            $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
            $principal = (float) $validated['amount'];
            
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
                        $feeAmount = (float) $fee->amount;
                        $feeType = $fee->fee_type;
                        $calculatedFee = 0;
                        
                        if ($feeType === 'percentage') {
                            $calculatedFee = ($principal * $feeAmount / 100);
                        } elseif ($feeType === 'range') {
                            $feeModel = \App\Models\Fee::find($fee->id);
                            if ($feeModel) {
                                $calculatedFee = (float) $feeModel->calculateRangeFee($principal);
                            }
                        } else {
                            $calculatedFee = (float) $feeAmount;
                        }
                        
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
                        $feeAmount = (float) $fee->amount;
                        $feeType = $fee->fee_type;
                        $calculatedFee = 0;
                        
                        if ($feeType === 'percentage') {
                            $calculatedFee = ($principal * $feeAmount / 100);
                        } elseif ($feeType === 'range') {
                            $feeModel = \App\Models\Fee::find($fee->id);
                            if ($feeModel) {
                                $calculatedFee = (float) $feeModel->calculateRangeFee($principal);
                            }
                        } else {
                            $calculatedFee = (float) $feeAmount;
                        }
                        
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
                            $feeAmount = (float) $fee->amount;
                            $feeType = $fee->fee_type;
                            $calculatedFee = 0;
                            
                            if ($feeType === 'percentage') {
                                $calculatedFee = ($principal * $feeAmount / 100);
                            } elseif ($feeType === 'range') {
                                $feeModel = \App\Models\Fee::find($fee->id);
                                if ($feeModel) {
                                    $calculatedFee = (float) $feeModel->calculateRangeFee($principal);
                                }
                            } else {
                                $calculatedFee = (float) $feeAmount;
                            }
                            
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
                        'account_name' => $feeChartAccount->name ?? $fee['name'],
                        'account_code' => $feeChartAccount->code ?? '',
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

        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'period' => 'required|integer|min:1',
            'interest' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'date_applied' => 'required|date|before_or_equal:today',
            'customer_id' => 'required|exists:customers,id',
            'interest_cycle' => 'required|string|max:50',
            'loan_officer' => 'required|exists:users,id',
            'group_id' => 'required|exists:groups,id',
            'account_id' => 'required|exists:bank_accounts,id',
            'sector' => 'required|string',
        ]);

        // Debug: Log the validated data to check customer_id
        \Log::info('Store method validated data:', $validated);



        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        // Restrict application if product has no approval levels
        if ($product->has_approval_levels && (empty($product->approval_levels) || count($product->approval_levels) === 0)) {
            return back()->withErrors(['error' => 'Loan application must have levels of approval configured.'])->withInput();
        }
        $this->validateProductLimits($validated, $product);

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
            DB::transaction(function () use ($validated, $product, $userId, $branchId, &$loan) {
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
                ]);
                info('loaan-->' . $loan);

                // Step 2: Calculate interest and repayment dates (use converted interest)
                $interestAmount = $loan->calculateInterestAmount($convertedInterest);
                $repaymentDates = $loan->getRepaymentDates();

                // Step 3: Update Loan with totals and schedule
                $loan->update([
                    'interest_amount' => $interestAmount,
                    'amount_total' => $loan->amount + $interestAmount,
                    'first_repayment_date' => $repaymentDates['first_repayment_date'],
                    'last_repayment_date' => $repaymentDates['last_repayment_date'],
                ]);

                // Step 4: Generate repayment schedule (use converted interest)
                $loan->generateRepaymentSchedule($convertedInterest);

                // Step 4.5: Post matured interest for past loans
                $loan->postMaturedInterestForPastLoan();

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
                
                $notes = "Being disbursement for loan of {$product->name}, paid to {$loan->customer->name}, TSHS.{$validated['amount']}";
                $principalReceivable = optional($product->principalReceivableAccount)->id;
                if (!$principalReceivable) {
                    throw new \Exception('Principal receivable account not set for this loan product.');
                }

                $releaseFeeTotal = 0;
                if ($product && $product->fees_ids) {
                    \Log::info('fees_ids: ' . json_encode($product->fees_ids));
                    $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
                    \Log::info('Decoded feeIds:', ['feeIds' => $feeIds]);
                    if (is_array($feeIds)) {
                        $releaseFees = \DB::table('fees')
                            ->whereIn('id', $feeIds)
                            ->where('deduction_criteria', 'charge_fee_on_release_date')
                            ->where('status', 'active')
                            ->get();
                        \Log::info('Release fees found:', ['count' => count($releaseFees), 'fees' => json_encode($releaseFees)]);
                        foreach ($releaseFees as $fee) {
                            $feeAmount = (float) $fee->amount;
                            $feeType = $fee->fee_type;
                            $calculatedFee = 0;
                            
                            if ($feeType === 'percentage') {
                                $calculatedFee = ((float) $validated['amount'] * (float) $feeAmount / 100);
                            } elseif ($feeType === 'range') {
                                $feeModel = \App\Models\Fee::find($fee->id);
                                if ($feeModel) {
                                    $calculatedFee = (float) $feeModel->calculateRangeFee($validated['amount']);
                                }
                            } else {
                                $calculatedFee = (float) $feeAmount;
                            }
                            
                            $releaseFeeTotal += $calculatedFee;
                            \Log::info("Fee: {$fee->name}, Type: $feeType, Amount: $feeAmount, Calculated: $calculatedFee");
                        }
                    }
                }

                \Log::info("Total release fees: $releaseFeeTotal");

                $disbursementAmount = $validated['amount'] - $releaseFeeTotal;

                // Debug: Log customer_id before Payment creation
                \Log::info('Creating Payment with customer_id:', [
                    'customer_id' => $validated['customer_id'],
                    'loan_id' => $loan->id,
                    'disbursement_amount' => $disbursementAmount
                ]);

                $payment = Payment::create([
                    'reference' => $loan->id,
                    'reference_type' => 'Loan Payment',
                    'reference_number' => null,
                    'date' => $validated['date_applied'],
                    'amount' => $disbursementAmount,
                    'description' => $notes,
                    'user_id' => $userId,
                    'payee_type' => 'customer',
                    'customer_id' => $validated['customer_id'],
                    'bank_account_id' => $validated['account_id'],
                    'branch_id' => $branchId,
                    'approved' => true,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ]);

                // Debug: Log created payment
                \Log::info('Payment created:', [
                    'payment_id' => $payment->id,
                    'customer_id' => $payment->customer_id,
                    'reference' => $payment->reference
                ]);

                PaymentItem::create([
                    'payment_id' => $payment->id,
                    'chart_account_id' => $principalReceivable,
                    'amount' => $validated['amount'],
                    'description' => $notes,
                ]);

                // Step 6: GL Transactions
                GlTransaction::insert([
                    [
                        'chart_account_id' => $bankAccount->chart_account_id,
                        'customer_id' => $loan->customer_id,
                        'amount' => $disbursementAmount,
                        'nature' => 'credit',
                        'transaction_id' => $loan->id,
                        'transaction_type' => 'Loan Disbursement',
                        'date' => $validated['date_applied'],
                        'description' => $notes,
                        'branch_id' => $branchId,
                        'user_id' => $userId,
                    ],
                    [
                        'chart_account_id' => $principalReceivable,
                        'customer_id' => $loan->customer_id,
                        'amount' => $validated['amount'],
                        'nature' => 'debit',
                        'transaction_id' => $loan->id,
                        'transaction_type' => 'Loan Disbursement',
                        'date' => $validated['date_applied'],
                        'description' => $notes,
                        'branch_id' => $branchId,
                        'user_id' => $userId,
                    ]
                ]);
                // Step 7: Post Penalty Amount to GL (if exists)
                $penalty = $product->penalty;

                $penaltyAmount = LoanSchedule::where('loan_id', $loan->id)->sum('penalty_amount');

                if ($penaltyAmount > 0) {
                    $receivableId = $penalty->penalty_receivables_account_id;  // from penalties table
                    $incomeId = $penalty->penalty_income_account_id;          // from penalties table

                    if (!$receivableId || !$incomeId) {
                        throw new \Exception('Penalty chart accounts not configured.');
                    }

                    GlTransaction::insert([
                        [
                            'chart_account_id' => $receivableId,
                            'customer_id' => $loan->customer_id,
                            'amount' => $penaltyAmount,
                            'nature' => 'debit',
                            'transaction_id' => $loan->id,
                            'transaction_type' => 'Loan Penalty',
                            'date' => $validated['date_applied'],
                            'description' => $notes,
                            'branch_id' => $branchId,
                            'user_id' => $userId,
                        ],
                        [
                            'chart_account_id' => $incomeId,
                            'customer_id' => $loan->customer_id,
                            'amount' => $penaltyAmount,
                            'nature' => 'credit',
                            'transaction_id' => $loan->id,
                            'transaction_type' => 'Loan Penalty',
                            'date' => $validated['date_applied'],
                            'description' => $notes,
                            'branch_id' => $branchId,
                            'user_id' => $userId,
                        ]
                    ]);
                }
            });

            // Send SMS notification to customer after loan creation
            try {
                $loan->refresh();
                $loan->load(['customer', 'schedule', 'product']);
                
                $customer = $loan->customer;
                if ($customer && !empty($customer->phone1)) {
                    // Get first repayment schedule
                    $firstSchedule = $loan->schedule()->orderBy('due_date')->first();
                    
                    if ($firstSchedule) {
                        // Calculate payment amount per cycle
                        $paymentAmount = $firstSchedule->principal + $firstSchedule->interest + 
                                        ($firstSchedule->fee_amount ?? 0) + ($firstSchedule->penalty_amount ?? 0);
                        
                        // Get first repayment date
                        $firstRepaymentDate = \Carbon\Carbon::parse($firstSchedule->due_date);
                        
                        // Format interest cycle in Swahili
                        $cycleSwahili = '';
                        switch (strtolower($loan->interest_cycle)) {
                            case 'daily':
                                $cycleSwahili = 'kila siku';
                                break;
                            case 'weekly':
                                $cycleSwahili = 'kila wiki';
                                break;
                            case 'monthly':
                                $cycleSwahili = 'kila mwezi';
                                break;
                            case 'quarterly':
                                $cycleSwahili = 'kila robo mwaka';
                                break;
                            case 'semi_annually':
                                $cycleSwahili = 'kila nusu mwaka';
                                break;
                            case 'annually':
                                $cycleSwahili = 'kila mwaka';
                                break;
                            default:
                                $cycleSwahili = 'kila mwezi';
                        }
                        
                        // Get company information
                        $company = null;
                        if ($loan->branch_id) {
                            $branch = \App\Models\Branch::with('company')->find($loan->branch_id);
                            if ($branch && $branch->company) {
                                $company = $branch->company;
                            }
                        }
                        
                        if (!$company && $customer->company_id) {
                            $company = \App\Models\Company::find($customer->company_id);
                        }
                        
                        if (!$company) {
                            $company = auth()->user()->company;
                        }
                        
                        $companyName = $company ? $company->name : 'SMARTFINANCE';
                        $companyPhone = $company ? ($company->phone ?? '') : '';
                        
                        // Format dates in Swahili format (DD/MM/YYYY)
                        $loanDate = \Carbon\Carbon::parse($loan->date_applied)->format('d/m/Y');
                        $repaymentStartDate = $firstRepaymentDate->format('d/m/Y');
                        
                        // Format amount with commas
                        $formattedAmount = number_format($loan->amount, 0);
                        $formattedPaymentAmount = number_format($paymentAmount, 0);
                        
                        // Build SMS message — use custom template if set, otherwise use default
                        $templateVars = [
                            'customer_name'        => $customer->name,
                            'amount'               => $formattedAmount,
                            'loan_date'            => $loanDate,
                            'repayment_start_date' => $repaymentStartDate,
                            'payment_amount'       => $formattedPaymentAmount,
                            'cycle'                => $cycleSwahili,
                            'company_name'         => $companyName,
                            'company_phone'        => $companyPhone,
                        ];
                        $smsMessage = \App\Helpers\SmsHelper::resolveTemplate('loan_disbursement', $templateVars);
                        if ($smsMessage === null) {
                            $smsMessage = "Umepokea mkopo wa Tsh {$formattedAmount} tarehe {$loanDate}, Marejesho yako yataanza {$repaymentStartDate} na utakuwa unalipa Tsh {$formattedPaymentAmount} {$cycleSwahili}. Asante. Ujumbe umetoka {$companyName}";
                            if (!empty($companyPhone)) {
                                $smsMessage .= " kwa mawasiliano piga {$companyPhone}";
                            }
                        }

                        // Send SMS
                        \App\Helpers\SmsHelper::send($customer->phone1, $smsMessage, 'loan_disbursement');
                        
                        \Log::info('Loan creation SMS sent', [
                            'loan_id' => $loan->id,
                            'customer_id' => $customer->id,
                            'phone' => $customer->phone1,
                            'message' => $smsMessage
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Log error but don't fail the loan creation
                \Log::error('Failed to send loan creation SMS', [
                    'loan_id' => $loan->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
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
        // Log::info("=== LOAN EDIT METHOD ===", ["encoded_id" => $encodedId, "loan_id" => $loan->id, "loan_data" => ["amount" => $loan->amount, "interest" => $loan->interest, "period" => $loan->period, "interest_cycle" => $loan->interest_cycle, "customer_id" => $loan->customer_id, "group_id" => $loan->group_id, "product_id" => $loan->product_id, "bank_account_id" => $loan->bank_account_id, "loan_officer_id" => $loan->loan_officer_id, "sector" => $loan->sector]]);
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

        \Log::info('Updating loan application', [
            'loan_id' => $loan->id,
            'user_id' => auth()->id(),
            'data' => $request->all()
        ]);

        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'period' => 'required|integer|min:1',
            'interest' => 'required|numeric|min:0',
            'amount' => 'required|numeric|min:0',
            'date_applied' => 'required|date|before_or_equal:today',
            'customer_id' => 'required|exists:customers,id',
            'interest_cycle' => 'required|string|max:50',
            'loan_officer' => 'required|exists:users,id',
            'group_id' => 'required|exists:groups,id',
            'account_id' => 'required|exists:bank_accounts,id',
            'sector' => 'required|string',
        ]);
        Log::info('Update validated data:', $validated);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        $this->validateProductLimits($validated, $product);

        // ... rest of the method remains the same until notes creation ...

        $userId = auth()->id();
        $branchId = auth()->user()->branch_id;

        try {
            DB::transaction(function () use ($loan, $validated, $product, $userId, $branchId) {
                $loanId = $loan->id;
                // Check for repayments
                $repaymentCount = \DB::table('repayments')->where('loan_id', $loanId)->count();
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

                // Now update loan and proceed with transactions (like store)
                $loan->fill([
                    'product_id' => $validated['product_id'],
                    'period' => $validated['period'],
                    'interest' => $validated['interest'],
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
                ]);

                // Calculate interest and repayment dates
                $interestAmount = $loan->calculateInterestAmount($validated['interest']);
                $repaymentDates = $loan->getRepaymentDates();
                $loan->fill([
                    'interest_amount' => $interestAmount,
                    'amount_total' => $loan->amount + $interestAmount,
                    'first_repayment_date' => $repaymentDates['first_repayment_date'],
                    'last_repayment_date' => $repaymentDates['last_repayment_date'],
                ]);
                $loan->save();
                $loan->generateRepaymentSchedule($validated['interest']);

                // Post matured interest for past loans
                $loan->postMaturedInterestForPastLoan();

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
                
                $notes = "Being disbursement for loan of {$product->name}, paid to {$loan->customer->name}, TSHS.{$validated['amount']}";
                $principalReceivable = optional($product->principalReceivableAccount)->id;
                if (!$principalReceivable) {
                    throw new \Exception('Principal receivable account not set for this loan product.');
                }

                $releaseFeeTotal = 0;
                if ($product && $product->fees_ids) {
                    $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
                    if (is_array($feeIds)) {
                        $releaseFees = \DB::table('fees')
                            ->whereIn('id', $feeIds)
                            ->where('deduction_criteria', 'charge_fee_on_release_date')
                            ->where('status', 'active')
                            ->get();
                        foreach ($releaseFees as $fee) {
                            $feeAmount = (float) $fee->amount;
                            $feeType = $fee->fee_type;
                            $calculatedFee = $feeType === 'percentage'
                                ? ((float) $validated['amount'] * (float) $feeAmount / 100)
                                : (float) $feeAmount;
                            $releaseFeeTotal += $calculatedFee;
                        }
                    }
                }
                $disbursementAmount = $validated['amount'] - $releaseFeeTotal;

                $payment = Payment::create([
                    'reference' => $loan->id,
                    'reference_type' => 'Loan Payment',
                    'reference_number' => null,
                    'date' => $validated['date_applied'],
                    'amount' => $disbursementAmount,
                    'description' => $notes,
                    'user_id' => $userId,
                    'payee_type' => 'customer',
                    'customer_id' => $validated['customer_id'],
                    'bank_account_id' => $validated['account_id'],
                    'branch_id' => $branchId,
                    'approved' => true,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ]);

                PaymentItem::create([
                    'payment_id' => $payment->id,
                    'chart_account_id' => $principalReceivable,
                    'amount' => $validated['amount'],
                    'description' => $notes,
                ]);

                // GL Transactions
                GlTransaction::create([
                    'chart_account_id' => $bankAccount->chart_account_id,
                    'customer_id' => $loan->customer_id,
                    'amount' => $disbursementAmount,
                    'nature' => 'credit',
                    'transaction_id' => $loan->id,
                    'transaction_type' => 'Loan Disbursement',
                    'date' => $validated['date_applied'],
                    'description' => $notes,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);
                GlTransaction::create([
                    'chart_account_id' => $principalReceivable,
                    'customer_id' => $loan->customer_id,
                    'amount' => $validated['amount'],
                    'nature' => 'debit',
                    'transaction_id' => $loan->id,
                    'transaction_type' => 'Loan Disbursement',
                    'date' => $validated['date_applied'],
                    'description' => $notes,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);
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
        switch (strtolower($selectedCycle)) {
            case 'daily':
                return $monthlyRate / 30;
            case 'weekly':
                return $monthlyRate / 4;
            case 'bimonthly':
                return $monthlyRate / 2;
            case 'monthly':
                return $monthlyRate; // Base rate
            case 'quarterly':
                return $monthlyRate * 4;
            case 'semi_annually':
                return $monthlyRate * 6;
            case 'annually':
                return $monthlyRate * 12;
            default:
                return $monthlyRate; // Default to monthly if unknown
        }
    }

    //////PRODUCT LIMITS ////////////////////////////////
    protected function validateProductLimits(array $data, LoanProduct $product)
    {
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


    public function destroy($encodedId)
    {
        try {
            // Decode the encoded ID
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                return redirect()->route('loans.list')->withErrors(['Loan not found.']);
            }

            // Fetch the loan
            $loan = Loan::findOrFail($decoded[0]);
            Log::info("=== LOAN EDIT METHOD ===", ["encoded_id" => $encodedId, "loan_id" => $loan->id, "loan_data" => ["amount" => $loan->amount, "interest" => $loan->interest, "period" => $loan->period, "interest_cycle" => $loan->interest_cycle, "customer_id" => $loan->customer_id, "group_id" => $loan->group_id, "product_id" => $loan->product_id, "bank_account_id" => $loan->bank_account_id, "loan_officer_id" => $loan->loan_officer_id, "sector" => $loan->sector]]);
            $loanId = $loan->id;

            // If loan is active, perform full cleanup (receipts/journals/etc). Otherwise, delete loan directly
            if ($loan->status === Loan::STATUS_ACTIVE) {
                // Check for repayments
                $repaymentCount = \DB::table('repayments')->where('loan_id', $loanId)->count();
                if ($repaymentCount > 0) {
                    return redirect()->route('loans.list')->withErrors(['error' => 'This loan has repayments. Please delete repayments first before deleting the loan.']);
                }

                \DB::transaction(function () use ($loan, $loanId) {
                    // Delete Receipts and Receipt Items related to this loan disbursement
                    $receiptIds = \DB::table('receipts')
                        ->where('reference_type', 'Loan Disbursement')
                        ->where('reference_number', $loanId)
                        ->pluck('id')
                        ->toArray();
                    if (!empty($receiptIds)) {
                        \DB::table('receipt_items')->whereIn('receipt_id', $receiptIds)->delete();
                        \DB::table('receipts')->whereIn('id', $receiptIds)->delete();
                    }

                    // get all the loan schedule ids
                    $scheduleIds = \DB::table('loan_schedules')->where('loan_id', $loanId)->pluck('id')->toArray();

                    // Delete GL Transactions for this loan
                    \DB::table('gl_transactions')
                        ->where('transaction_id', $loanId)
                        ->where('transaction_type', 'Loan Disbursement')
                        ->delete();

                    // delete penalty gl transactions
                    if (!empty($scheduleIds)) {
                        \DB::table('gl_transactions')
                            ->whereIn('transaction_id', $scheduleIds)
                            ->where('transaction_type', 'Penalty')
                            ->delete();

                        // delete interest gl transactions
                        \DB::table('gl_transactions')
                            ->whereIn('transaction_id', $scheduleIds)
                            ->where('transaction_type', 'Mature Interest')
                            ->delete();
                    }

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
                        $journalsQuery = \DB::table('journals')
                            ->where('reference_type', 'Loan Disbursement')
                            ->where(function ($query) use ($loanId) {
                                // force string comparison to avoid numeric coercion errors
                                $query->where('reference', (string) $loanId);
                                if (\Schema::hasColumn('journals', 'reference_number')) {
                                    $query->orWhere('reference_number', (string) $loanId);
                                }
                            });

                        $journalIds = $journalsQuery->pluck('id')->toArray();

                        if (!empty($journalIds) && \Schema::hasTable('journal_items')) {
                            \DB::table('journal_items')->whereIn('journal_id', $journalIds)->delete();
                        }

                        if (!empty($journalIds)) {
                            \DB::table('journals')->whereIn('id', $journalIds)->delete();
                        }
                    }

                    // Finally delete the loan
                    $loan->delete();
                });
            } else {
                // Non-active loans: just delete the loan and its schedules, leave receipts/journals intact
                \DB::transaction(function () use ($loan, $loanId) {
                    \DB::table('loan_schedules')->where('loan_id', $loanId)->delete();
                    $loan->delete();
                });
            }

            return redirect()->route('loans.by-status', 'applied')->with('success', 'Loan and related records deleted successfully.');
        } catch (\Throwable $e) {
            return redirect()->route('loans.list')->withErrors(['error' => 'Failed to delete loan: ' . $e->getMessage()]);
        }
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
            'schedule',
            'repayments',
            'approvals.user',
            'approvals' => function ($query) {
                $query->orderBy('approval_level', 'asc');
            },
            'guarantors' // add this if not eager loaded already
        ])->findOrFail($decoded[0]);

        // Load active receipts (loan repayment receipts only)
        $activeReceipts = Receipt::where('reference', $loan->id)
            ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Load reversed receipts (soft-deleted)
        $reversedReceipts = Receipt::onlyTrashed()
            ->where('reference', $loan->id)
            ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Get IDs of guarantors already attached to this loan
        $guarantorIdsAlreadyAdded = $loan->guarantors->pluck('id')->toArray();

        // Fetch guarantors excluding already assigned ones
        $guarantorCustomers = Customer::where('category', 'guarantor')
            ->whereNotIn('id', $guarantorIdsAlreadyAdded)
            ->get();

        $filetypes = Filetype::all();

        // Get bank accounts for repayment modal (branch-scoped)
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();

        // Load active receipts (loan repayment receipts only)
        $activeReceipts = Receipt::where('reference', $loan->id)
            ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Load reversed receipts (soft-deleted)
        $reversedReceipts = Receipt::onlyTrashed()
            ->where('reference', $loan->id)
            ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
            ->with(['repayments', 'bankAccount', 'user'])
            ->get();

        // Set the encoded ID for the loan object
        $loan->encodedId = $encodedId;

        return view('loans.show', compact('loan', 'guarantorCustomers', 'filetypes', 'bankAccounts', 'activeReceipts', 'reversedReceipts'));
    }


    ////////////////////UPLOAD LOAN DOCUMENT/////////////////////

    public function loanDocument(Request $request)
    {
        $maxFileSize = (int) config('upload.max_file_size', 102400); // in KB
        $allowedMimes = (array) config('upload.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt']);

        // Early check for file presence and upload validity to produce clearer errors
        if (!$request->hasFile('files')) {
            return back()->withErrors(['files' => 'No files were received by the server. Please try again.']);
        }

        $request->validate([
            'loan_id' => 'required|exists:loans,id',
            'filetypes' => 'required|array|min:1',
            'filetypes.*' => 'required|exists:filetypes,id',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:' . $maxFileSize . '|mimes:' . implode(',', $allowedMimes),
        ]);

        // Validate each uploaded file is valid at PHP level and provide helpful messages
        foreach ((array) $request->file('files') as $idx => $uploaded) {
            if (!$uploaded) {
                return back()->withErrors(["files.$idx" => 'File not received by PHP (empty upload).']);
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
                return back()->withErrors(["files.$idx" => $errorMessage]);
            }
        }

        $loanId = $request->loan_id;
        $filetypes = $request->filetypes;
        $files = $request->file('files');

        $uploadedCount = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            foreach ($files as $index => $file) {
                if (isset($filetypes[$index])) {
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
            }

            DB::commit();

            if ($uploadedCount > 0) {
                $message = $uploadedCount === 1
                    ? 'Document uploaded successfully.'
                    : "{$uploadedCount} documents uploaded successfully.";
                return back()->with('success', $message);
            } else {
                return back()->withErrors(['error' => 'No files were uploaded.']);
            }
        } catch (\Exception $e) {
            DB::rollback();
            \Log::error('Document upload error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Failed to upload documents: ' . $e->getMessage()]);
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
            'guarantor_id' => 'required|exists:customers,id',
            'relation' => 'nullable|string|max:100',
        ]);

        $loan->guarantors()->attach($validated['guarantor_id'], ['relation' => $validated['relation']]);

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
        $bankAccounts = BankAccount::all();
        $sectors = ['Agriculture', 'Business', 'Education', 'Health', 'Other'];

        return view('loans.application.create', compact('customers', 'groups', 'products', 'sectors', 'bankAccounts'));
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
        ]);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        $this->validateProductLimits($validated, $product);

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

        //check if member has enough collateral balance
        //1. check if this loan product require cash collateral
        if ($product->has_cash_collateral) {
            $customer = Customer::findOrFail($validated['customer_id']);
            $requiredCollateral = $product->cash_collateral_value_type === 'percentage'
                ? $customer->cash_collateral_balance * ($product->cash_collateral_value / 100)
                : $product->cash_collateral_value;

            if ($requiredCollateral < $validated['amount']) {
                return back()->withErrors(['error' => 'Member does not have enough collateral balance.']);
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
                'top_up_id' => null
            ]);

            // Calculate interest amount after loan is created
            $interestAmount = $loan->calculateInterestAmount($validated['interest']);
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
        $guarantorCustomers = Customer::where('category', 'guarantor')
            ->whereNotIn('id', $guarantorIdsAlreadyAdded)
            ->get();

        $filetypes = Filetype::all();

        // Branch-scoped bank accounts for repayment modal
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();

        // Set the encoded ID for the loan object
        $loan->encodedId = $encodedId;

        return view('loans.show', compact('loan', 'guarantorCustomers', 'filetypes', 'bankAccounts'));
    }

    public function applicationEdit($encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Loan application not found.']);
        }

        $loanApplication = Loan::findOrFail($decoded[0]);

        // Check if application can be edited
        if (!in_array($loanApplication->status, ['applied', 'rejected'])) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Only applied or rejected applications can be edited.']);
        }

        $branchId = auth()->user()->branch_id;
        $customers = Customer::where('category', 'borrower')
            ->where('branch_id', $branchId)
            ->with('groups')
            ->get();
        $groups = Group::where('branch_id', $branchId)->get();
        $products = LoanProduct::all();
        $bankAccounts = BankAccount::forUserBranches()->orderBy('name')->get();
        $sectors = ['Agriculture', 'Business', 'Education', 'Health', 'Other'];

        return view('loans.application.edit', compact('loanApplication', 'customers', 'groups', 'products', 'sectors', 'bankAccounts'));
    }

    public function applicationUpdate(Request $request, $encodedId)
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Loan application not found.']);
        }

        $loanApplication = Loan::findOrFail($decoded[0]);

        // Check if application can be edited
        if (!in_array($loanApplication->status, ['applied', 'rejected'])) {
            return redirect()->route('loans.by-status', 'applied')->withErrors(['Only applied or rejected applications can be edited.']);
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
        ]);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($validated['product_id']);
        $this->validateProductLimits(                                                           $validated, $product);

        try {
            $updateData = [
                'product_id' => $validated['product_id'],
                'period' => $validated['period'],
                'interest' => $validated['interest'],
                'amount' => $validated['amount'],
                'interest_amount' => $loanApplication->calculateInterestAmount($validated['interest']),
                'customer_id' => $validated['customer_id'],
                'group_id' => $validated['group_id'],
                'amount_total' => $validated['amount'] + $loanApplication->calculateInterestAmount($validated['interest']),
                'interest_cycle' => $validated['interest_cycle'], // Use from form
                'date_applied' => $validated['date_applied'],
                'sector' => $validated['sector'],
            ];

            info($updateData);
            // If loan was rejected, change status back to applied and reset approvals
            if ($loanApplication->status === 'rejected') {
                $updateData['status'] = 'applied';
                // Remove any prior approvals so the workflow restarts cleanly
                LoanApproval::where('loan_id', $loanApplication->id)->delete();
            }

            $loanApplication->update($updateData);

            return redirect()->route('loans.by-status', 'applied')->with('success', 'Loan application updated successfully.');
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

            // If disbursing, require and set bank account and disbursement date before proceeding
            if ($nextAction === 'disburse') {
                $request->validate([
                    'bank_account_id' => 'required|exists:bank_accounts,id',
                    'disbursement_date' => 'required|date|before_or_equal:today',
                ]);
                if (!$loan->bank_account_id || (int) $loan->bank_account_id !== (int) $request->input('bank_account_id')) {
                    $loan->update(['bank_account_id' => (int) $request->input('bank_account_id')]);
                    \Log::notice('Bank account set for disbursement', [
                        'loan_id' => $loan->id,
                        'bank_account_id' => (int) $request->input('bank_account_id')
                    ]);
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

            DB::transaction(function () use ($loan, $user, $validated, $nextAction, $nextLevel, $roleName, $disbursementDate, $request) {
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
                        // Check if bank account is set for disbursement
                        if (!$loan->bank_account_id) {
                            throw new \Exception('Bank account must be selected before disbursement. Please update the loan with a bank account first.');
                        }

                        // Use provided disbursement date or current date
                        $disburseDate = $disbursementDate ?? now();

                        // Process disbursement
                        $loan->update([
                            'status' => Loan::STATUS_ACTIVE,
                            'disbursed_on' => $disburseDate,
                        ]);

                        // Calculate interest and repayment dates
                        $interestAmount = $loan->calculateInterestAmount($loan->interest);
                        $repaymentDates = $loan->getRepaymentDates();

                        // Update loan with totals and schedule
                        $loan->update([
                            'interest_amount' => $interestAmount,
                            'amount_total' => $loan->amount + $interestAmount,
                            'first_repayment_date' => $repaymentDates['first_repayment_date'],
                            'last_repayment_date' => $repaymentDates['last_repayment_date'],
                        ]);

                        // Generate repayment schedule
                        $loan->generateRepaymentSchedule($loan->interest);

                        // Process disbursement with the selected date
                        $this->processLoanDisbursement($loan, $disburseDate);
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

            // Check if loan application can be deleted - prevent deletion of active or authorized loans
            if (in_array($loanApplication->status, ['active', 'authorized'])) {
                DB::rollBack();
                return redirect()->route('loans.by-status', 'applied')->withErrors(['You cannot delete an active or authorized loan. Only pending, rejected, or other non-active loans can be deleted.']);
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
        $userId = auth()->id();
        $branchId = auth()->user()->branch_id;
        $product = $loan->product;

        // Check if bank account is set
        if (!$loan->bank_account_id) {
            throw new \Exception('Bank account must be selected before disbursement.');
        }

        // Use provided disbursement date or loan's date_applied
        $disburseDate = $disbursementDate ?? $loan->date_applied;

        $bankAccount = $loan->bankAccount;

        $notes = "Being disbursement for loan of {$product->name}, paid to {$loan->customer->name}, TSHS.{$loan->amount}";
        $principalReceivable = optional($product->principalReceivableAccount)->id;

        if (!$principalReceivable) {
            throw new \Exception('Principal receivable account not set for this loan product.');
        }

        // Create Payment record
        $payment = Payment::create([
            'reference' => $loan->id,
            'reference_type' => 'Loan Payment',
            'reference_number' => null,
            'date' => $disburseDate,
            'amount' => $loan->amount,
            'description' => $notes,
            'user_id' => $userId,
            'payee_type' => 'customer',
            'customer_id' => $loan->customer_id,
            'bank_account_id' => $loan->bank_account_id,
            'branch_id' => $branchId,
            'approved' => true,
            'approved_by' => $userId,
            'approved_at' => $disburseDate,
        ]);

        PaymentItem::create([
            'payment_id' => $payment->id,
            'chart_account_id' => $principalReceivable,
            'amount' => $loan->amount,
            'description' => $notes,
        ]);

        $releaseFeeTotal = 0;
        if ($product && $product->fees_ids) {
            $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
            if (is_array($feeIds)) {
                $releaseFees = \DB::table('fees')
                    ->whereIn('id', $feeIds)
                    ->where('deduction_criteria', 'charge_fee_on_release_date')
                    ->where('status', 'active')
                    ->get();
                foreach ($releaseFees as $fee) {
                    $feeAmount = (float) $fee->amount;
                    $feeType = $fee->fee_type;
                    $calculatedFee = $feeType === 'percentage'
                        ? ((float) $loan->amount * (float) $feeAmount / 100)
                        : (float) $feeAmount;
                    $releaseFeeTotal += $calculatedFee;
                }
            }
        }
        $disbursementAmount = $loan->amount - $releaseFeeTotal;

        // Create GL Transactions
        GlTransaction::insert([
            [
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $disbursementAmount,
                'nature' => 'credit',
                'transaction_id' => $loan->id,
                'transaction_type' => 'Loan Disbursement',
                'date' => $disburseDate,
                'description' => $notes,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ],
            [
                'chart_account_id' => $principalReceivable,
                'customer_id' => $loan->customer_id,
                'amount' => $loan->amount,
                'nature' => 'debit',
                'transaction_id' => $loan->id,
                'transaction_type' => 'Loan Disbursement',
                'date' => $disburseDate,
                'description' => $notes,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]
        ]);
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

            // Send SMS notification — same pattern as loan disbursement
            try {
                $restructuredLoan->loadMissing(['customer', 'schedule', 'product']);
                $smsCustomer = $restructuredLoan->customer;

                if ($smsCustomer && !empty($smsCustomer->phone1)) {
                    $sortedSchedule = $restructuredLoan->schedule->sortBy('due_date');
                    $firstSchedule  = $sortedSchedule->first();

                    if ($firstSchedule) {
                        $firstRepaymentDate = \Carbon\Carbon::parse($firstSchedule->due_date);
                        $paymentAmount      = ($firstSchedule->principal ?? 0)
                                           + ($firstSchedule->interest  ?? 0)
                                           + ($firstSchedule->fee_amount ?? 0);

                        $cycle = $restructuredLoan->product->repayment_cycle
                              ?? $restructuredLoan->repayment_cycle
                              ?? 'monthly';

                        switch ($cycle) {
                            case 'weekly':       $cycleSwahili = 'kila wiki';        break;
                            case 'bi_weekly':    $cycleSwahili = 'kila wiki mbili';  break;
                            case 'quarterly':    $cycleSwahili = 'kila robo mwaka';  break;
                            case 'semi_annually':$cycleSwahili = 'kila nusu mwaka'; break;
                            case 'annually':     $cycleSwahili = 'kila mwaka';       break;
                            default:             $cycleSwahili = 'kila mwezi';
                        }

                        // Resolve company
                        $smsCompany = null;
                        if ($restructuredLoan->branch_id) {
                            $smsBranch = \App\Models\Branch::with('company')->find($restructuredLoan->branch_id);
                            if ($smsBranch && $smsBranch->company) {
                                $smsCompany = $smsBranch->company;
                            }
                        }
                        if (!$smsCompany && $smsCustomer->company_id) {
                            $smsCompany = \App\Models\Company::find($smsCustomer->company_id);
                        }
                        if (!$smsCompany) {
                            $smsCompany = auth()->user()->company;
                        }

                        $companyName  = $smsCompany ? $smsCompany->name         : 'SMARTFINANCE';
                        $companyPhone = $smsCompany ? ($smsCompany->phone ?? '') : '';

                        $loanDate             = \Carbon\Carbon::parse($restructuredLoan->date_applied)->format('d/m/Y');
                        $repaymentStartDate   = $firstRepaymentDate->format('d/m/Y');
                        $formattedAmount      = number_format($restructuredLoan->amount, 0);
                        $formattedPaymentAmount = number_format($paymentAmount, 0);

                        $templateVars = [
                            'customer_name'        => $smsCustomer->name,
                            'amount'               => $formattedAmount,
                            'loan_date'            => $loanDate,
                            'repayment_start_date' => $repaymentStartDate,
                            'payment_amount'       => $formattedPaymentAmount,
                            'cycle'                => $cycleSwahili,
                            'company_name'         => $companyName,
                            'company_phone'        => $companyPhone,
                        ];

                        $smsMessage = \App\Helpers\SmsHelper::resolveTemplate('loan_disbursement', $templateVars);
                        if ($smsMessage === null) {
                            $smsMessage = "Mkopo wako umefanyiwa muundo mpya. Umepewa mkopo wa Tsh {$formattedAmount} tarehe {$loanDate}, Marejesho yako yataanza {$repaymentStartDate} na utakuwa unalipa Tsh {$formattedPaymentAmount} {$cycleSwahili}. Asante. Ujumbe umetoka {$companyName}";
                            if (!empty($companyPhone)) {
                                $smsMessage .= " kwa mawasiliano piga {$companyPhone}";
                            }
                        }

                        \App\Helpers\SmsHelper::send($smsCustomer->phone1, $smsMessage, 'loan_disbursement');

                        Log::info('Loan restructuring SMS sent', [
                            'restructured_loan_id' => $restructuredLoan->id,
                            'customer_id'          => $smsCustomer->id,
                            'phone'                => $smsCustomer->phone1,
                        ]);
                    }
                }
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

        // Compute outstanding balance to write off: total repayable - total paid
        $totalToPay = $loan->getTotalAmountToPay();
        $totalPaid = $loan->getTotalPaidAmount();
        $amount = max(0, round($totalToPay - $totalPaid, 2));

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

            // Get loan product accounts
            $product = $loan->product;
            $branchId = auth()->user()->branch_id;

            if ($validated['writeoff_type'] === 'direct') {
                $debitAccount = $product->direct_writeoff_account_id;
            } else {
                $debitAccount = $product->provision_writeoff_account_id;
            }
            $creditAccount = $product->principal_receivable_account_id;

            // Create GL transactions using writeoff_id
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

            \App\Models\GlTransaction::create([
                'chart_account_id' => $creditAccount,
                'customer_id' => $loan->customer_id,
                'amount' => $amount,
                'nature' => 'credit',
                'transaction_id' => $writeoff->id,
                'transaction_type' => 'Loan Writeoff',
                'date' => now(),
                'description' => 'Loan write-off',
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]);

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
     * Download opening balance template
     */
    public function downloadOpeningBalanceTemplate(Request $request)
    {
        // Get product_id from request to determine interest cycle
        $productId = $request->get('product_id');
        $interestCycle = 'Monthly'; // Default value

        if ($productId) {
            $product = LoanProduct::find($productId);
            if ($product && $product->interest_cycle) {
                $interestCycle = ucfirst($product->interest_cycle);
            }
        }

        $customers = Customer::with('groups')->get();

        $headers = [
            'customer_no',
            'customer_name',
            'group_id',
            'group_name',
            'amount',
            'interest',
            'period',
            'date_applied',
            'sector',
            'amount_paid'
        ];

        $filename = 'opening_balance_template_' . date('Y-m-d') . '.csv';

        $callback = function () use ($customers, $headers, $interestCycle) {
            $file = fopen('php://output', 'w');

            // Write headers
            fputcsv($file, $headers);

            // Write data for all customers
            foreach ($customers as $customer) {
                $group = $customer->groups->first();
                fputcsv($file, [
                    $customer->customerNo,
                    $customer->name,
                    $group ? $group->id : '',
                    $group ? $group->name : '',
                    '', // amount - to be filled
                    '', // interest - to be filled
                    '', // period - to be filled
                    date('Y-m-d'), // date_applied
                    'Business', // sector
                    '' // amount_paid - to be filled
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Store opening balance loans
     */
    public function storeOpeningBalance(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:loan_products,id',
            'branch_id' => 'required|exists:branches,id',
            'chart_account_id' => 'required|exists:chart_accounts,id',
            'csv_file' => 'required|file|mimes:csv,txt|max:10240'
        ]);

        try {
            $file = $request->file('csv_file');
            $csvData = array_map('str_getcsv', file($file->getPathname()));
            $headers = array_shift($csvData);

            // Validate CSV structure
            $expectedHeaders = ['customer_no', 'customer_name', 'group_id', 'group_name', 'amount', 'interest', 'period', 'date_applied', 'sector', 'amount_paid'];
            if (array_diff($expectedHeaders, $headers)) {
                return redirect()->back()->withErrors(['csv_file' => 'Invalid CSV format. Please download the template and use it.']);
            }

            // Remove the uploaded file from validated data to avoid serialization issues
            unset($validated['csv_file']);

            // Dispatch job for bulk loan creation
            \App\Jobs\BulkLoanCreationJob::dispatch($csvData, $validated, auth()->id());

            return redirect()->back()->with('success', 'Opening balance processing started. You will be notified when complete.');
        } catch (\Exception $e) {
            Log::error('Opening balance processing failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to process opening balance: ' . $e->getMessage()]);
        }
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
                'repayments' => function ($query) {
                    $query->orderBy('created_at', 'asc');
                },
                'approvals.user',
                'approvals' => function ($query) {
                    $query->orderBy('approval_level', 'asc');
                },
                'guarantors',
                'collaterals',
                'branch',
                'loanOfficer'
            ])->findOrFail($decoded[0]);

            // Check if loan is active
            if ($loan->status !== Loan::STATUS_ACTIVE) {
                return redirect()->back()->withErrors(['error' => 'Only active loans can be exported.']);
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
                'remainingBalance' => $remainingBalance,
                'remainingPrincipal' => $remainingPrincipal,
                'exportDate' => now()->format('Y-m-d H:i:s'),
                'company' => auth()->user()->company
            ];

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('loans.export-details', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = 'Loan_Details_' . $loan->loanNo . '_' . now()->format('Y-m-d') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('Export loan details failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to export loan details: ' . $e->getMessage()]);
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
}
