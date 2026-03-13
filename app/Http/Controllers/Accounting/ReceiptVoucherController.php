<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\ChartAccount;
use App\Models\Loan;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\GlTransaction;
use App\Services\LoanRepaymentService;
use App\Traits\TransactionHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class ReceiptVoucherController extends Controller
{
    use TransactionHelper;

    /**
     * Debug method to test controller accessibility
     */
    public function debug()
    {
        return response()->json([
            'message' => 'ReceiptVoucherController is accessible',
            'user' => Auth::user()->name ?? 'No user',
            'timestamp' => now()
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = Auth::user();

        // Calculate stats only
        $receipts = Receipt::with(['bankAccount.chartAccount.accountClassGroup'])
            ->whereHas('bankAccount.chartAccount.accountClassGroup', function ($query) use ($user) {
                $query->where('company_id', $user->company_id);
            })
            ->when($user->branch_id, function ($query) use ($user) {
                return $query->where('branch_id', $user->branch_id);
            });

        $stats = [
            'total' => $receipts->count(),
            'this_month' => $receipts->where('date', '>=', now()->startOfMonth())->count(),
            'total_amount' => $receipts->sum('amount'),
            'this_month_amount' => $receipts->where('date', '>=', now()->startOfMonth())->sum('amount'),
        ];

        return view('accounting.receipt-vouchers.index', compact('stats'));
    }

    // Ajax endpoint for DataTables
    public function getReceiptVouchersData(Request $request)
    {
        $user = Auth::user();

        $receipts = Receipt::with(['bankAccount', 'user', 'customer', 'loan'])
            ->whereHas('bankAccount.chartAccount.accountClassGroup', function ($query) use ($user) {
                $query->where('company_id', $user->company_id);
            })
            ->when($user->branch_id, function ($query) use ($user) {
                return $query->where('branch_id', $user->branch_id);
            })
            ->select('receipts.*');

        return DataTables::eloquent($receipts)
            ->addColumn('formatted_date', function ($receipt) {
                return $receipt->date ? $receipt->date->format('M d, Y') : 'N/A';
            })
            ->addColumn('reference_link', function ($receipt) {
                return '<a href="' . route('accounting.receipt-vouchers.show', Hashids::encode($receipt->id)) . '" 
                            class="text-primary fw-bold">
                            ' . e($receipt->reference) . '
                        </a>';
            })
            ->addColumn('bank_account_name', function ($receipt) {
                return optional($receipt->bankAccount)->name ?? 'N/A';
            })
            ->addColumn('payee_info', function ($receipt) {
                if ($receipt->payee_type == 'customer' && $receipt->customer) {
                    return '<span class="badge bg-primary me-1">Customer</span>' . e($receipt->customer->name ?? 'N/A');
                } elseif ($receipt->payee_type == 'supplier' && $receipt->supplier) {
                    return '<span class="badge bg-success me-1">Supplier</span>' . e($receipt->supplier->name ?? 'N/A');
                } elseif ($receipt->payee_type == 'other') {
                    return '<span class="badge bg-warning me-1">Other</span>' . e($receipt->payee_name ?? 'N/A');
                } else {
                    return '<span class="text-muted">No payee</span>';
                }
            })
            ->addColumn('description_limited', function ($receipt) {
                return $receipt->description ? Str::limit($receipt->description, 50) : 'No description';
            })
            ->addColumn('formatted_amount', function ($receipt) {
                return '<span class="text-end fw-bold">' . number_format($receipt->amount, 2) . '</span>';
            })
            ->addColumn('user_name', function ($receipt) {
                return optional($receipt->user)->name ?? 'N/A';
            })
            ->addColumn('status_badge', function ($receipt) {
                return $receipt->status_badge;
            })
            ->addColumn('actions', function ($receipt) {
                $actions = '';
                
                // View action
                if (auth()->user()->can('view receipt voucher details')) {
                    $actions .= '<a href="' . route('accounting.receipt-vouchers.show', Hashids::encode($receipt->id)) . '" 
                                    class="btn btn-sm btn-outline-success me-1" 
                                    data-bs-toggle="tooltip" 
                                    data-bs-placement="top" 
                                    title="View receipt voucher">
                                    <i class="bx bx-show"></i>
                                </a>';
                }
                
                if ($receipt->reference_type === 'manual') {
                    // Edit action
                    if (auth()->user()->can('edit receipt voucher')) {
                        $actions .= '<a href="' . route('accounting.receipt-vouchers.edit', Hashids::encode($receipt->id)) . '" 
                                        class="btn btn-sm btn-outline-info me-1" 
                                        data-bs-toggle="tooltip" 
                                        data-bs-placement="top" 
                                        title="Edit receipt voucher">
                                        <i class="bx bx-edit"></i>
                                    </a>';
                    }
                    
                    // Delete action
                    if (auth()->user()->can('delete receipt voucher')) {
                        $actions .= '<button type="button" 
                                        class="btn btn-sm btn-outline-danger delete-receipt-btn"
                                        data-bs-toggle="tooltip" 
                                        data-bs-placement="top" 
                                        title="Delete receipt voucher"
                                        data-receipt-id="' . Hashids::encode($receipt->id) . '"
                                        data-receipt-reference="' . e($receipt->reference) . '">
                                        <i class="bx bx-trash"></i>
                                    </button>';
                    }
                } else {
                    $actions .= '<button type="button" 
                                    class="btn btn-sm btn-outline-secondary" 
                                    title="Edit/Delete locked: Source is ' . ucfirst($receipt->reference_type) . ' transaction" 
                                    disabled>
                                    <i class="bx bx-lock"></i>
                                </button>';
                }
                
                return '<div class="text-center">' . $actions . '</div>';
            })
            ->rawColumns(['reference_link', 'payee_info', 'formatted_amount', 'status_badge', 'actions'])
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $user = Auth::user();

        // Get bank accounts for the current company and user's branches
        $bankAccounts = BankAccount::with('chartAccount')
            ->whereHas('chartAccount.accountClassGroup', function ($query) use ($user) {
                $query->where('company_id', $user->company_id);
            })
            ->forUserBranches($user)
            ->orderBy('name')
            ->get();

        // Get customers for the current company/branch
        $customers = Customer::where('company_id', $user->company_id)
            ->when($user->branch_id, function ($query) use ($user) {
                return $query->where('branch_id', $user->branch_id);
            })
            ->orderBy('name')
            ->get();

        // Get chart accounts for the current company
        $chartAccounts = ChartAccount::whereHas('accountClassGroup', function ($query) use ($user) {
            $query->where('company_id', $user->company_id);
        })
            ->orderBy('account_name')
            ->get();

        return view('accounting.receipt-vouchers.create', compact('bankAccounts', 'customers', 'chartAccounts'));
    }

    /**
     * Get loans for a customer (AJAX endpoint)
     */
    public function getCustomerLoans(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id'
        ]);

        $customerId = $request->customer_id;
        
        // Get all loans for the customer (active and applied status)
        $loans = \App\Models\Loan::where('customer_id', $customerId)
            ->whereIn('status', ['active', 'applied'])
            ->with(['product', 'branch'])
            ->orderBy('date_applied', 'desc')
            ->get()
            ->map(function ($loan) {
                $dateApplied = $loan->date_applied
                    ? (\Carbon\Carbon::parse($loan->date_applied)->format('M d, Y'))
                    : 'N/A';
                $disbursedOn = $loan->disbursed_on
                    ? (\Carbon\Carbon::parse($loan->disbursed_on)->format('M d, Y'))
                    : 'N/A';
                return [
                    'id' => $loan->id,
                    'loanNo' => $loan->loanNo,
                    'product_name' => $loan->product->name ?? 'N/A',
                    'amount' => number_format($loan->amount, 2),
                    'status' => ucfirst($loan->status),
                    'date_applied' => $dateApplied,
                    'disbursed_on' => $disbursedOn,
                    'branch_name' => $loan->branch->name ?? 'N/A',
                ];
            });

        return response()->json([
            'success' => true,
            'loans' => $loans
        ]);
    }

    /**
     * Get unpaid schedules for a loan (AJAX endpoint for repayment line items).
     */
    public function getLoanSchedules(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:loans,id'
        ]);

        $loan = \App\Models\Loan::findOrFail($request->loan_id);
        $schedules = $loan->schedule()
            ->with('repayments')
            ->where('status', '!=', 'restructured')
            ->orderBy('due_date')
            ->get();

        $unpaid = $schedules->filter(function ($schedule) {
            $totalDue = $schedule->principal + $schedule->interest + ($schedule->fee_amount ?? 0) + ($schedule->penalty_amount ?? 0);
            $paid = $schedule->repayments->sum(function ($r) {
                return $r->principal + $r->interest + ($r->fee_amount ?? 0) + ($r->penalt_amount ?? 0);
            });
            $remaining = max(0, $totalDue - $paid);
            return $remaining > 0;
        })->values()->map(function ($schedule) {
            $totalDue = $schedule->principal + $schedule->interest + ($schedule->fee_amount ?? 0) + ($schedule->penalty_amount ?? 0);
            $paid = $schedule->repayments->sum(function ($r) {
                return $r->principal + $r->interest + ($r->fee_amount ?? 0) + ($r->penalt_amount ?? 0);
            });
            $remaining = round(max(0, $totalDue - $paid), 2);
            $dueDate = $schedule->due_date;
            if (is_string($dueDate)) {
                $dueDate = \Carbon\Carbon::parse($dueDate)->format('M d, Y');
            } else {
                $dueDate = $dueDate ? $dueDate->format('M d, Y') : 'N/A';
            }
            $num = \App\Models\LoanSchedule::where('loan_id', $schedule->loan_id)->where('due_date', '<=', $schedule->due_date)->orderBy('due_date')->count();
            return [
                'id' => $schedule->id,
                'due_date' => $dueDate,
                'total_due' => round($totalDue, 2),
                'remaining' => $remaining,
                'schedule_number' => $num,
            ];
        });

        return response()->json([
            'success' => true,
            'schedules' => $unpaid,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        \Log::info('Receipt voucher store method called');

        $rules = [
            'date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payee_type' => 'required|in:customer,other',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:2048',
        ];

        if ($request->filled('loan_id')) {
            $rules['loan_id'] = 'required|exists:loans,id';
            $rules['repayment_lines'] = 'required|array|min:1';
            $rules['repayment_lines.*.schedule_id'] = 'required|exists:loan_schedules,id';
            $rules['repayment_lines.*.amount'] = 'required|numeric|min:0.01';
        } else {
            $rules['customer_id'] = 'nullable|required_if:payee_type,customer|exists:customers,id';
            $rules['payee_name'] = 'nullable|string|max:255|required_if:payee_type,other';
            $rules['line_items'] = 'required|array|min:1';
            $rules['line_items.*.chart_account_id'] = 'required|exists:chart_accounts,id';
            $rules['line_items.*.amount'] = 'required|numeric|min:0.01';
            $rules['line_items.*.description'] = 'nullable|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            \Log::error('Receipt voucher validation failed:', $validator->errors()->toArray());
            \Log::error('Request data that failed validation:', $request->all());
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        \Log::info('Validation passed, proceeding with creation');
        \Log::info('Request data:', $request->all());

        try {
            return $this->runTransaction(function () use ($request) {
                $user = Auth::user();
                $isLoanRepayment = $request->filled('loan_id');

                if ($isLoanRepayment) {
                    return $this->storeLoanRepaymentReceipt($request, $user);
                }

                $totalAmount = collect($request->line_items)->sum('amount');

                \Log::info('Creating receipt voucher with total amount:', ['total' => $totalAmount]);

                // Handle file upload
                $attachmentPath = null;
                if ($request->hasFile('attachment')) {
                    $file = $request->file('attachment');
                    $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                    $attachmentPath = $file->storeAs('receipt-attachments', $fileName, 'public');
                }

                // Set payee information
                if ($request->payee_type === 'customer') {
                    $payeeType = 'customer';
                    $payeeId = $request->customer_id;
                    $payeeName = null;
                    $customerId = $request->customer_id;
                    $supplierId = null;
                } else {
                    $payeeType = 'other';
                    $payeeId = null;
                    $payeeName = $request->payee_name;
                    $customerId = null;
                    $supplierId = null;
                }

                \Log::info('Payee information:', [
                    'type' => $payeeType,
                    'id' => $payeeId,
                    'name' => $payeeName,
                    'payee_type_request' => $request->payee_type,
                    'payee_name_request' => $request->payee_name
                ]);

                // Create receipt
                $receiptData = [
                    'reference' => $request->reference ?: 'RV-' . strtoupper(uniqid()),
                    'reference_type' => 'manual',
                    'reference_number' => $request->reference,
                    'amount' => $totalAmount,
                    'date' => $request->date,
                    'description' => $request->description,
                    'attachment' => $attachmentPath,
                    'user_id' => $user->id,
                    'bank_account_id' => $request->bank_account_id,
                    'payee_type' => $payeeType,
                    'payee_id' => $payeeId,
                    'payee_name' => $payeeName,
                    'customer_id' => $customerId,
                    'supplier_id' => $supplierId,
                    'branch_id' => $user->branch_id,
                    'approved' => true, // Auto-approve for now
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ];

                \Log::info('Receipt data to be created:', $receiptData);

                $receipt = Receipt::create($receiptData);

                \Log::info('Receipt created successfully:', ['receipt_id' => $receipt->id]);

                // Create receipt items
                $receiptItems = [];
                foreach ($request->line_items as $lineItem) {
                    $receiptItems[] = [
                        'receipt_id' => $receipt->id,
                        'chart_account_id' => $lineItem['chart_account_id'],
                        'amount' => $lineItem['amount'],
                        'description' => $lineItem['description'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                ReceiptItem::insert($receiptItems);
                \Log::info('Receipt items created:', ['count' => count($receiptItems)]);

                // Create GL transactions
                $bankAccount = BankAccount::find($request->bank_account_id);

                // Validate bank account is accessible (branch scope)
                if ($bankAccount) {
                    $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : $user->branch_id;
                    if ($currentBranchId && !$bankAccount->is_all_branches && $bankAccount->branch_id != $currentBranchId) {
                        DB::rollBack();
                        return redirect()->back()->withErrors(['bank_account_id' => 'You do not have access to this bank account.'])->withInput();
                    }
                }

                // Prepare description for GL transactions
                $glDescription = $request->description ?: "Receipt voucher {$receipt->reference}";
                if ($payeeType === 'other' && $payeeName) {
                    $glDescription = $payeeName . ' - ' . $glDescription;
                }

                // Debit bank account
                GlTransaction::create([
                    'chart_account_id' => $bankAccount->chart_account_id,
                    'customer_id' => $customerId,
                    'supplier_id' => $supplierId,
                    'amount' => $totalAmount,
                    'nature' => 'debit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $request->date,
                    'description' => $glDescription,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                ]);

                // Credit each chart account
                foreach ($request->line_items as $lineItem) {
                    $lineItemDescription = $lineItem['description'] ?: "Receipt voucher {$receipt->reference}";
                    if ($payeeType === 'other' && $payeeName) {
                        $lineItemDescription = $payeeName . ' - ' . $lineItemDescription;
                    }

                    GlTransaction::create([
                        'chart_account_id' => $lineItem['chart_account_id'],
                        'customer_id' => $customerId,
                        'supplier_id' => $supplierId,
                        'amount' => $lineItem['amount'],
                        'nature' => 'credit',
                        'transaction_id' => $receipt->id,
                        'transaction_type' => 'receipt',
                        'date' => $request->date,
                        'description' => $lineItemDescription,
                        'branch_id' => $user->branch_id,
                        'user_id' => $user->id,
                    ]);
                }

                \Log::info('GL transactions created successfully');

                return redirect()->route('accounting.receipt-vouchers.show', Hashids::encode($receipt->id))
                    ->with('success', 'Receipt voucher created successfully.');
            });
        } catch (\Exception $e) {
            \Log::error('Receipt voucher creation failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()
                ->withErrors(['error' => 'Failed to create receipt voucher: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Store receipt voucher for loan repayment (schedule-based line items).
     */
    private function storeLoanRepaymentReceipt(Request $request, $user)
    {
        $loan = Loan::with('customer')->findOrFail($request->loan_id);
        $totalAmount = collect($request->repayment_lines)->sum('amount');

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $attachmentPath = $file->storeAs('receipt-attachments', $fileName, 'public');
        }

        // For loan repayments, keep reference pointing to loan ID so it appears under the loan's Receipts tab
        $receipt = Receipt::create([
            'reference' => $loan->id,
            'reference_type' => 'loan_repayment',
            // Use reference_number to store any manual/voucher number typed by the user
            'reference_number' => $request->reference ?: null,
            'amount' => $totalAmount,
            'date' => $request->date,
            'description' => $request->description ?: "Loan repayment - {$loan->customer->name}",
            'attachment' => $attachmentPath,
            'user_id' => $user->id,
            'bank_account_id' => $request->bank_account_id,
            'payee_type' => 'customer',
            'payee_id' => $loan->customer_id,
            'payee_name' => $loan->customer->name ?? null,
            'customer_id' => $loan->customer_id,
            'supplier_id' => null,
            'branch_id' => $user->branch_id,
            'approved' => true,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $bankAccount = BankAccount::find($request->bank_account_id);
        $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : $user->branch_id;
        if ($bankAccount && $currentBranchId && !$bankAccount->is_all_branches && $bankAccount->branch_id != $currentBranchId) {
            DB::rollBack();
            return redirect()->back()->withErrors(['bank_account_id' => 'You do not have access to this bank account.'])->withInput();
        }

        GlTransaction::create([
            'chart_account_id' => $bankAccount->chart_account_id,
            'customer_id' => $loan->customer_id,
            'supplier_id' => null,
            'amount' => $totalAmount,
            'nature' => 'debit',
            'transaction_id' => $receipt->id,
            'transaction_type' => 'receipt',
            'date' => $request->date,
            'description' => $request->description ?: "Loan repayment - {$loan->customer->name}",
            'branch_id' => $user->branch_id,
            'user_id' => $user->id,
        ]);

        $paymentData = [
            'payment_date' => $request->date,
            'bank_account_id' => $request->bank_account_id,
            'bank_chart_account_id' => $bankAccount->chart_account_id ?? null,
        ];

        $service = new LoanRepaymentService();
        $service->processRepaymentLinesToReceipt($loan, $receipt, $request->repayment_lines, $paymentData);

        return redirect()->route('accounting.receipt-vouchers.show', Hashids::encode($receipt->id))
            ->with('success', 'Receipt voucher created and repayments applied successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
        }

        $receiptVoucher = Receipt::findOrFail($decoded[0]);

        $receiptVoucher->load([
            'bankAccount',
            'customer.company',
            'customer.branch',
            'user',
            'receiptItems.chartAccount',
            'glTransactions.chartAccount',
            'branch'
        ]);

        // Only load loan relationship if this receipt is linked to a loan
        if ($receiptVoucher->reference_type === 'loan') {
            $receiptVoucher->load('loan.customer', 'loan.product');
        }

        return view('accounting.receipt-vouchers.show', compact('receiptVoucher'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
        }

        $receiptVoucher = Receipt::findOrFail($decoded[0]);

        $user = Auth::user();

        // Get bank accounts for the current company and user's branches
        $bankAccounts = BankAccount::with('chartAccount')
            ->whereHas('chartAccount.accountClassGroup', function ($query) use ($user) {
                $query->where('company_id', $user->company_id);
            })
            ->forUserBranches($user)
            ->orderBy('name')
            ->get();

        // Get customers for the current company/branch
        $customers = Customer::where('company_id', $user->company_id)
            ->when($user->branch_id, function ($query) use ($user) {
                return $query->where('branch_id', $user->branch_id);
            })
            ->orderBy('name')
            ->get();

        // Get chart accounts for the current company
        $chartAccounts = ChartAccount::whereHas('accountClassGroup', function ($query) use ($user) {
            $query->where('company_id', $user->company_id);
        })
            ->orderBy('account_name')
            ->get();

        $receiptVoucher->load('receiptItems');

        return view('accounting.receipt-vouchers.edit', compact('receiptVoucher', 'bankAccounts', 'customers', 'chartAccounts'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $encodedId)
    {
        // Decode receipt voucher ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
        }

        $receiptVoucher = Receipt::findOrFail($decoded[0]);

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payee_type' => 'required|in:customer,other',
            'customer_id' => 'nullable|required_if:payee_type,customer|exists:customers,id',
            'payee_name' => 'nullable|string|max:255|required_if:payee_type,other',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:2048',
            'line_items' => 'required|array|min:1',
            'line_items.*.chart_account_id' => 'required|exists:chart_accounts,id',
            'line_items.*.amount' => 'required|numeric|min:0.01',
            'line_items.*.description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            return $this->runTransaction(function () use ($request, $receiptVoucher) {
                $user = Auth::user();
                $totalAmount = collect($request->line_items)->sum('amount');

                // Handle file upload and attachment removal
                $attachmentPath = $receiptVoucher->attachment;

                // Check if user wants to remove attachment
                if ($request->has('remove_attachment') && $request->remove_attachment == '1') {
                    // Delete old attachment if exists
                    if ($receiptVoucher->attachment && Storage::disk('public')->exists($receiptVoucher->attachment)) {
                        Storage::disk('public')->delete($receiptVoucher->attachment);
                    }
                    $attachmentPath = null;
                } elseif ($request->hasFile('attachment')) {
                    // Delete old attachment if exists
                    if ($receiptVoucher->attachment && Storage::disk('public')->exists($receiptVoucher->attachment)) {
                        Storage::disk('public')->delete($receiptVoucher->attachment);
                    }

                    $file = $request->file('attachment');
                    $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                    $attachmentPath = $file->storeAs('receipt-attachments', $fileName, 'public');
                }

                // Set payee information
                if ($request->payee_type === 'customer') {
                    $payeeType = 'customer';
                    $payeeId = $request->customer_id;
                    $payeeName = null;
                } else {
                    $payeeType = 'other';
                    $payeeId = null;
                    $payeeName = $request->payee_name;
                }

                // Prepare base update data
                $updateData = [
                    'amount' => $totalAmount,
                    'date' => $request->date,
                    'description' => $request->description,
                    'attachment' => $attachmentPath,
                    'bank_account_id' => $request->bank_account_id,
                    'payee_type' => $payeeType,
                    'payee_id' => $payeeId,
                    'payee_name' => $payeeName,
                ];

                // For loan repayment receipts, keep reference pointing at the loan ID and only adjust reference_number.
                // For manual/other receipts, allow editing the reference itself.
                if ($receiptVoucher->reference_type === 'loan_repayment') {
                    $updateData['reference_number'] = $request->reference ?: $receiptVoucher->reference_number;
                } else {
                    $updateData['reference'] = $request->reference ?: $receiptVoucher->reference;
                    $updateData['reference_number'] = $request->reference;
                }

                // Update receipt
                $receiptVoucher->update($updateData);

                // Delete existing receipt items and GL transactions
                $receiptVoucher->receiptItems()->delete();
                $receiptVoucher->glTransactions()->delete();

                // Create new receipt items
                $receiptItems = [];
                foreach ($request->line_items as $lineItem) {
                    $receiptItems[] = [
                        'receipt_id' => $receiptVoucher->id,
                        'chart_account_id' => $lineItem['chart_account_id'],
                        'amount' => $lineItem['amount'],
                        'description' => $lineItem['description'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                ReceiptItem::insert($receiptItems);

                // Create new GL transactions
                $bankAccount = BankAccount::find($request->bank_account_id);
                
                // Validate bank account is accessible by user's branches
                if ($bankAccount) {
                    $user = Auth::user();
                    $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : $user->branch_id;
                    if ($currentBranchId && !$bankAccount->is_all_branches && $bankAccount->branch_id != $currentBranchId) {
                        DB::rollBack();
                        return redirect()->back()->withErrors(['bank_account_id' => 'You do not have access to this bank account.'])->withInput();
                    }
                }

                // Prepare description for GL transactions
                $glDescription = $request->description ?: "Receipt voucher {$receiptVoucher->reference}";
                if ($payeeType === 'other' && $payeeName) {
                    $glDescription = $payeeName . ' - ' . $glDescription;
                }

                // Debit bank account
                GlTransaction::create([
                    'chart_account_id' => $bankAccount->chart_account_id,
                    'customer_id' => $payeeType === 'customer' ? $payeeId : null,
                    'amount' => $totalAmount,
                    'nature' => 'debit',
                    'transaction_id' => $receiptVoucher->id,
                    'transaction_type' => 'receipt',
                    'date' => $request->date,
                    'description' => $glDescription,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                ]);

                // Credit each chart account
                foreach ($request->line_items as $lineItem) {
                    $lineItemDescription = $lineItem['description'] ?: "Receipt voucher {$receiptVoucher->reference}";
                    if ($payeeType === 'other' && $payeeName) {
                        $lineItemDescription = $payeeName . ' - ' . $lineItemDescription;
                    }
                    
                    GlTransaction::create([
                        'chart_account_id' => $lineItem['chart_account_id'],
                        'customer_id' => $payeeType === 'customer' ? $payeeId : null,
                        'amount' => $lineItem['amount'],
                        'nature' => 'credit',
                        'transaction_id' => $receiptVoucher->id,
                        'transaction_type' => 'receipt',
                        'date' => $request->date,
                        'description' => $lineItemDescription,
                        'branch_id' => $user->branch_id,
                        'user_id' => $user->id,
                    ]);
                }

                return redirect()->route('accounting.receipt-vouchers.show', Hashids::encode($receiptVoucher->id))
                    ->with('success', 'Receipt voucher updated successfully.');
            });
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => 'Failed to update receipt voucher: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($encodedId)
    {
        // Decode the encoded ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
        }

        $receiptVoucher = Receipt::findOrFail($decoded[0]);

        try {
            return $this->runTransaction(function () use ($receiptVoucher) {
                // Delete attachment if exists
                if ($receiptVoucher->attachment && Storage::disk('public')->exists($receiptVoucher->attachment)) {
                    Storage::disk('public')->delete($receiptVoucher->attachment);
                }

                // Delete GL transactions first
                $receiptVoucher->glTransactions()->delete();

                // Delete receipt items
                $receiptVoucher->receiptItems()->delete();

                // Delete receipt
                $receiptVoucher->delete();

                return redirect()->route('accounting.receipt-vouchers.index')
                    ->with('success', 'Receipt voucher deleted successfully.');
            });
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => 'Failed to delete receipt voucher: ' . $e->getMessage()]);
        }
    }

    /**
     * Download attachment.
     */
    public function downloadAttachment($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
        }

        $receiptVoucher = Receipt::findOrFail($decoded[0]);

        if (!$receiptVoucher->attachment) {
            return redirect()->back()->withErrors(['error' => 'No attachment found.']);
        }

        if (!Storage::disk('public')->exists($receiptVoucher->attachment)) {
            return redirect()->back()->withErrors(['error' => 'Attachment file not found.']);
        }

        return Storage::disk('public')->download($receiptVoucher->attachment);
    }

    /**
     * Remove attachment.
     */
    public function removeAttachment($encodedId)
    {
        // Decode the ID
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
        }

        $receiptVoucher = Receipt::findOrFail($decoded[0]);

        try {
            // Delete attachment file if exists
            if ($receiptVoucher->attachment && Storage::disk('public')->exists($receiptVoucher->attachment)) {
                Storage::disk('public')->delete($receiptVoucher->attachment);
            }

            // Update receipt to remove attachment reference
            $receiptVoucher->update(['attachment' => null]);

            return redirect()->back()->with('success', 'Attachment removed successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to remove attachment: ' . $e->getMessage()]);
        }
    }

    /**
     * Export receipt voucher to PDF
     */
    public function exportPdf($encodedId)
    {
        Log::info('ReceiptVoucher exportPdf invoked', [
            'encoded_id' => $encodedId,
            'user_id' => Auth::id(),
        ]);

        try {
            // Decode the ID
            $decoded = Hashids::decode($encodedId);
            if (empty($decoded)) {
                Log::warning('ReceiptVoucher exportPdf decode failed', [
                    'encoded_id' => $encodedId,
                    'reason' => 'empty decoded array',
                ]);
                return redirect()->route('accounting.receipt-vouchers.index')->withErrors(['Receipt voucher not found.']);
            }

            $receiptVoucher = Receipt::with([
                'bankAccount.chartAccount.accountClassGroup',
                'customer',
                'user.company',
                'branch',
                'receiptItems.chartAccount',
            ])->findOrFail($decoded[0]);

            Log::info('ReceiptVoucher exportPdf loaded receipt', [
                'receipt_id' => $receiptVoucher->id,
                'reference' => $receiptVoucher->reference,
                'reference_type' => $receiptVoucher->reference_type ?? null,
            ]);

            // Check if user has access to this receipt voucher (when company context is available)
            $user = Auth::user();
            $companyId = optional(optional(optional($receiptVoucher->bankAccount)->chartAccount)->accountClassGroup)->company_id;
            if ($companyId !== null && $companyId !== $user->company_id) {
                abort(403, 'Unauthorized access to this receipt voucher.');
            }

            // Generate PDF using DomPDF
            $pdf = \PDF::loadView('accounting.receipt-vouchers.pdf', compact('receiptVoucher'));

            // Set paper size and orientation
            $pdf->setPaper('A4', 'portrait');

            // Generate filename
            $filename = 'receipt_voucher_' . $receiptVoucher->reference . '_' . date('Y-m-d_H-i-s') . '.pdf';

            // Return PDF for download
            return $pdf->download($filename);

        } catch (\Exception $e) {
            Log::error('ReceiptVoucher exportPdf failed', [
                'encoded_id' => $encodedId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->withErrors(['error' => 'Failed to export PDF: ' . $e->getMessage()]);
        }
    }

    /**
     * Show the form for creating a receipt from a loan.
     */
    public function createFromLoan($encodedLoanId)
    {
        // Decode the loan ID
        $decoded = Hashids::decode($encodedLoanId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $loan = \App\Models\Loan::with(['customer', 'product', 'bankAccount'])->findOrFail($decoded[0]);
        $user = Auth::user();

        // Get bank accounts for the current company
        $bankAccounts = BankAccount::with('chartAccount')
            ->whereHas('chartAccount.accountClassGroup', function ($query) use ($user) {
                $query->where('company_id', $user->company_id);
            })
            ->orderBy('name')
            ->get();

        // Get customers for the current company/branch
        $customers = Customer::where('company_id', $user->company_id)
            ->when($user->branch_id, function ($query) use ($user) {
                return $query->where('branch_id', $user->branch_id);
            })
            ->orderBy('name')
            ->get();

        // Get chart accounts for the current company
        $chartAccounts = ChartAccount::whereHas('accountClassGroup', function ($query) use ($user) {
            $query->where('company_id', $user->company_id);
        })
            ->orderBy('account_name')
            ->get();

        return view('accounting.receipt-vouchers.create-from-loan', compact('loan', 'bankAccounts', 'customers', 'chartAccounts'));
    }

    /**
     * Printable receipt for vouchers (simple layout: who paid, bank, amount, date, thanks)
     */
    public function print($id)
    {
        $receipt = Receipt::with(['customer', 'bankAccount.chartAccount', 'user.company', 'branch.company'])->findOrFail($id);

        // Resolve company (branch company first, then user company, then current_company helper)
        $company = $receipt->branch && $receipt->branch->company
            ? $receipt->branch->company
            : ($receipt->user && $receipt->user->company
                ? $receipt->user->company
                : (function_exists('current_company') ? current_company() : null));

        return view('accounting.receipt-vouchers.print', [
            'receipt' => $receipt,
            'company' => $company,
        ]);
    }

    /**
     * Store a receipt created from a loan.
     */
    public function storeFromLoan(Request $request, $encodedLoanId)
    {
        // Decode the loan ID
        $decoded = Hashids::decode($encodedLoanId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }

        $loan = \App\Models\Loan::findOrFail($decoded[0]);

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'payee_type' => 'required|in:customer,other',
            'customer_id' => 'nullable|required_if:payee_type,customer|exists:customers,id',
            'payee_name' => 'nullable|string|max:255|required_if:payee_type,other',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:pdf|max:2048',
            'line_items' => 'required|array|min:1',
            'line_items.*.chart_account_id' => 'required|exists:chart_accounts,id',
            'line_items.*.amount' => 'required|numeric|min:0.01',
            'line_items.*.description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            \Log::error('Receipt voucher validation failed:', $validator->errors()->toArray());
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        \Log::info('Validation passed, proceeding with creation from loan');

        try {
            return $this->runTransaction(function () use ($request, $loan) {
                $user = Auth::user();
                $totalAmount = collect($request->line_items)->sum('amount');

                \Log::info('Creating receipt voucher from loan with total amount:', ['total' => $totalAmount, 'loan_id' => $loan->id]);

                // Handle file upload
                $attachmentPath = null;
                if ($request->hasFile('attachment')) {
                    $file = $request->file('attachment');
                    $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                    $attachmentPath = $file->storeAs('receipt-attachments', $fileName, 'public');
                }

                // Set payee information
                if ($request->payee_type === 'customer') {
                    $payeeType = 'customer';
                    $payeeId = $request->customer_id;
                    $payeeName = null;
                } else {
                    $payeeType = 'other';
                    $payeeId = null;
                    $payeeName = $request->payee_name;
                }

                \Log::info('Payee information:', [
                    'type' => $payeeType,
                    'id' => $payeeId,
                    'name' => $payeeName
                ]);

                // Create receipt with loan reference
                $receipt = Receipt::create([
                    'reference' => $loan->id,
                    'reference_type' => 'loan',
                    'reference_number' => null,// Store loan ID as reference number
                    'amount' => $totalAmount,
                    'date' => $request->date,
                    'description' => $request->description,
                    'attachment' => $attachmentPath,
                    'user_id' => $user->id,
                    'bank_account_id' => $request->bank_account_id,
                    'payee_type' => $payeeType,
                    'payee_id' => $payeeId,
                    'payee_name' => $payeeName,
                    'branch_id' => $user->branch_id,
                    'approved' => true, // Auto-approve for now
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);

                \Log::info('Receipt created successfully from loan:', ['receipt_id' => $receipt->id, 'loan_id' => $loan->id]);

                // Create receipt items
                $receiptItems = [];
                foreach ($request->line_items as $lineItem) {
                    $receiptItems[] = [
                        'receipt_id' => $receipt->id,
                        'chart_account_id' => $lineItem['chart_account_id'],
                        'amount' => $lineItem['amount'],
                        'description' => $lineItem['description'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                ReceiptItem::insert($receiptItems);
                \Log::info('Receipt items created:', ['count' => count($receiptItems)]);

                // Create GL transactions
                $bankAccount = BankAccount::find($request->bank_account_id);
                
                // Validate bank account is accessible (branch scope)
                if ($bankAccount) {
                    $user = Auth::user();
                    $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : $user->branch_id;
                    if ($currentBranchId && !$bankAccount->is_all_branches && $bankAccount->branch_id != $currentBranchId) {
                        DB::rollBack();
                        return redirect()->back()->withErrors(['bank_account_id' => 'You do not have access to this bank account.'])->withInput();
                    }
                }

                // Debit bank account
                GlTransaction::create([
                    'chart_account_id' => $bankAccount->chart_account_id,
                    'customer_id' => $payeeType === 'customer' ? $payeeId : null,
                    'amount' => $totalAmount,
                    'nature' => 'debit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $request->date,
                    'description' => $request->description ?: "Receipt voucher {$receipt->reference} for loan {$loan->loanNo}",
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                ]);

                // Credit each chart account
                foreach ($request->line_items as $lineItem) {
                    GlTransaction::create([
                        'chart_account_id' => $lineItem['chart_account_id'],
                        'customer_id' => $payeeType === 'customer' ? $payeeId : null,
                        'amount' => $lineItem['amount'],
                        'nature' => 'credit',
                        'transaction_id' => $receipt->id,
                        'transaction_type' => 'receipt',
                        'date' => $request->date,
                        'description' => $lineItem['description'] ?: "Receipt voucher {$receipt->reference} for loan {$loan->loanNo}",
                        'branch_id' => $user->branch_id,
                        'user_id' => $user->id,
                    ]);
                }

                \Log::info('GL transactions created successfully for loan receipt');

                return redirect()->route('accounting.receipt-vouchers.show', Hashids::encode($receipt->id))
                    ->with('success', 'Receipt voucher created successfully from loan.');
            });
        } catch (\Exception $e) {
            \Log::error('Receipt voucher creation from loan failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()
                ->withErrors(['error' => 'Failed to create receipt voucher: ' . $e->getMessage()])
                ->withInput();
        }
    }
}
