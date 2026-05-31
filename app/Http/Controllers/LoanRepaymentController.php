<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesDcbRequestInput;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Repayment;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\GlTransaction;
use App\Services\LoanRepaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class LoanRepaymentController extends Controller
{
    use NormalizesDcbRequestInput;

    protected $repaymentService;

    public function __construct(LoanRepaymentService $repaymentService)
    {
        $this->repaymentService = $repaymentService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Add debugging
            Log::info('Repayment request received', $request->all());

            $this->normalizeDcbRequestInput($request, 'dcb_repay', 'dcb', 'dcb_settle');

            $request->validate([
                'loan_id' => 'required|exists:loans,id',
                'schedule_id' => [
                    'nullable',
                    Rule::exists('loan_schedules', 'id')->where('loan_id', $request->loan_id),
                ],
                'payment_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01',
                'payment_source' => 'required|in:bank,cash_deposit,dcb',
                'bank_account_id' => 'required_if:payment_source,bank,dcb|nullable|exists:bank_accounts,id',
                'cash_deposit_id' => 'required_if:payment_source,cash_deposit|nullable|exists:cash_collaterals,id',
                'dcb_msisdn' => 'required_if:payment_source,dcb|nullable|string|max:20',
                'dcb_control_no' => 'nullable|string|max:64',
                'dcb_institution_code' => 'nullable|string|max:64',
                'dcb_destination_account' => 'nullable|string|max:64',
                'dcb_beneficiary_name' => 'nullable|string|max:120',
            ]);

            Log::info('Validation passed');

            // Get loan and check if amount matches settle amount
            $loan = Loan::with(['product', 'customer', 'schedule'])->findOrFail($request->loan_id);
            $settleAmount = $loan->total_amount_to_settle;
            $paymentAmount = $request->amount;

            Log::info('Amount comparison', [
                'payment_amount' => $paymentAmount,
                'settle_amount' => $settleAmount,
                'difference' => abs($paymentAmount - $settleAmount)
            ]);


            if ($request->payment_source === 'dcb') {
                $dcbService = app(\App\Services\DcbPaymentService::class);
                if (!$dcbService->isEnabled()) {
                    return redirect()->back()->with('error', 'DCB payments are not enabled. Configure DCB in Settings.');
                }

                $dcbResult = $dcbService->collectRepayment($loan, $paymentAmount, [
                    'payment_date' => $request->payment_date,
                    'schedule_id' => $request->input('schedule_id'),
                    'bank_account_id' => $request->bank_account_id,
                    'msisdn' => $request->dcb_msisdn,
                    'control_no' => $request->dcb_control_no,
                    'calculation_method' => $loan->product->interest_method ?? 'flat_rate',
                ]);

                if (!($dcbResult['success'] ?? false)) {
                    $msg = $dcbResult['message'] ?? 'DCB repayment request failed.';
                    if ($request->ajax() || $request->wantsJson()) {
                        return response()->json(['success' => false, 'message' => $msg], 422);
                    }

                    return redirect()->back()->with('error', $msg);
                }

                if ($dcbResult['pending'] ?? false) {
                    $msg = 'DCB collection initiated. Customer must approve payment on their phone. Repayment will post automatically when confirmed.';
                    if ($request->ajax() || $request->wantsJson()) {
                        return response()->json(['success' => true, 'message' => $msg, 'pending' => true]);
                    }

                    return redirect()->back()->with('success', $msg);
                }

                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['success' => true, 'message' => 'Repayment recorded successfully via DCB!']);
                }

                return redirect()->back()->with('success', 'Repayment recorded successfully via DCB!');
            }

            Log::info('Processing normal repayment', [
                'loan_id' => $request->loan_id,
                'amount' => $paymentAmount,
                'settle_amount' => $settleAmount
            ]);

            // Use normal repayment process
            $bankAccount = BankAccount::findOrFail($request->bank_account_id);
            
            // Validate bank account is accessible by user's branches or global scope
            $user = Auth::user();
            $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : null;
            if (!$currentBranchId) {
                $currentBranchId = $user->branch_id;
            }

            // Bank account is accessible when:
            // - it is available to all branches, OR
            // - it is explicitly scoped to the current branch
            $hasDirectScope = $bankAccount->is_all_branches
                || ($currentBranchId && (int) $bankAccount->branch_id === (int) $currentBranchId);

            if (!$hasDirectScope) {
                return redirect()->back()->withErrors([
                    'bank_account_id' => 'You do not have access to this bank account for the current branch.'
                ]);
            }
            
            $bankChartAccount = $bankAccount->chart_account_id;

            // Check cash deposit balance if using cash deposit
            if ($request->payment_source === 'cash_deposit') {
                $cashDeposit = \App\Models\CashCollateral::findOrFail($request->cash_deposit_id);

                if ($cashDeposit->amount < $request->amount) {
                    return redirect()->back()->with('error', 'Insufficient cash deposit balance. Available: TSHS ' . number_format($cashDeposit->amount, 2));
                }
            }

            // Prepare payment data based on source
            $paymentData = [
                'payment_date' => $request->payment_date,
                'payment_source' => $request->payment_source,
                'bank_chart_account_id' => $bankChartAccount,
            ];

            if ($request->payment_source === 'bank') {
                $paymentData['bank_account_id'] = $request->bank_account_id;
            } else {
                $paymentData['cash_deposit_id'] = $request->cash_deposit_id;
            }

            // Get calculation method from loan product
            $calculationMethod = $loan->product->interest_method ?? 'flat_rate';

            Log::info('Processing normal repayment', [
                'loan_id' => $request->loan_id,
                'amount' => $request->amount,
                'calculation_method' => $calculationMethod,
                'payment_source' => $request->payment_source
            ]);

            // Process repayment using service (schedule_id from "Repay Schedule Item" modal when present)
            $result = $this->repaymentService->processRepayment(
                $request->loan_id,
                $request->amount,
                $paymentData,
                $calculationMethod,
                $request->input('schedule_id')
            );

            Log::info('Repayment processing result', $result);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'message' => 'Repayment recorded successfully!']);
            }
            return redirect()->back()->with('success', 'Repayment recorded successfully!');
        } catch (\Exception $e) {
            Log::error('Loan repayment error: ' . $e->getMessage());
            Log::error('Repayment error stack trace: ' . $e->getTraceAsString());

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Failed to record repayment: ' . $e->getMessage()], 422);
            }
            return redirect()->back()->with('error', 'Failed to record repayment: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $repayment = Repayment::with(['loan', 'schedule', 'bankAccount', 'customer'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'repayment' => $repayment
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'payment_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01',
                'bank_account_id' => 'required|exists:bank_accounts,id',
            ]);

            $repayment = Repayment::with(['loan', 'bankAccount'])->findOrFail($id);
            $bankAccount = BankAccount::findOrFail($request->bank_account_id);
            
            $user = Auth::user();
            if (!$bankAccount->isAccessibleByUser($user)) {
                return redirect()->back()->withErrors(['bank_account_id' => 'You do not have access to this bank account.']);
            }

            $bankChartAccount = $bankAccount->chart_account_id;

            // Store the loan and schedule info before deletion
            $loanId = $repayment->loan_id;
            $targetScheduleId = $repayment->loan_schedule_id;

            // Delete the existing repayment (this will also delete receipt, journal, and GL transactions)
            $this->repaymentService->deleteRepayment($repayment->id);

            // Create new repayment with updated details
            $paymentData = [
                'payment_date' => $request->payment_date,
                'bank_account_id' => $request->bank_account_id,
                'bank_chart_account_id' => $bankChartAccount,
            ];

            // Get calculation method from loan product
            $loan = Loan::with('product')->findOrFail($loanId);
            $calculationMethod = $loan->product->interest_method ?? 'flat_rate';

            // Process new repayment using service
            $result = $this->repaymentService->processRepayment(
                $loanId,
                $request->amount,
                $paymentData,
                $calculationMethod,
                $targetScheduleId
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Repayment updated successfully!'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repayment update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update repayment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Internal method to delete repayment and associated records
     * This method delegates to the service for comprehensive deletion
     */
    private function deleteRepaymentInternal($repayment)
    {
        // Use the service method for comprehensive deletion
        return $this->repaymentService->deleteRepayment($repayment->id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            // Use service method which handles transaction internally
            $result = $this->repaymentService->deleteRepayment($id);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Repayment deleted successfully!'
            ]);
        } catch (\Exception $e) {
            Log::error('Repayment deletion error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete repayment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete repayments
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:repayments,id',
        ]);

        try {
            $deletedCount = 0;
            $errors = [];

            foreach ($validated['ids'] as $repaymentId) {
                try {
                    $this->repaymentService->deleteRepayment($repaymentId);
                    $deletedCount++;
                } catch (\Exception $e) {
                    $errors[] = "Repayment ID {$repaymentId}: " . $e->getMessage();
                    Log::error("Failed to delete repayment {$repaymentId}: " . $e->getMessage());
                }
            }

            $message = "Deleted {$deletedCount} repayment(s) successfully.";
            if (!empty($errors)) {
                $message .= " " . count($errors) . " failed: " . implode('; ', $errors);
            }

            return response()->json([
                'success' => $deletedCount > 0,
                'message' => $message,
                'deleted' => $deletedCount,
                'failed' => count($errors),
                'errors' => $errors,
            ]);
        } catch (\Throwable $e) {
            Log::error('Bulk repayment deletion error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete repayments: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Loan summary for quick repayment modal (loans list).
     */
    public function repaymentContext($loanId)
    {
        $loan = Loan::with(['customer', 'product', 'schedule.repayments'])->findOrFail($loanId);

        $schedules = $loan->schedule ?? collect();
        $paidPrincipal = 0.0;
        $outstandingInterest = 0.0;
        $outstandingPenalty = 0.0;
        $outstandingFees = 0.0;

        foreach ($schedules as $schedule) {
            $repayments = $schedule->repayments ?? collect();
            $paidPrincipal += (float) $repayments->sum('principal');
            $outstandingInterest += max(0, (float) ($schedule->interest ?? 0) - (float) $repayments->sum('interest'));
            $outstandingPenalty += max(0, (float) ($schedule->penalty_amount ?? 0) - (float) $repayments->sum('penalt_amount'));
            $outstandingFees += max(0, (float) ($schedule->fee_amount ?? 0) - (float) $repayments->sum('fee_amount'));
        }

        $outstandingPrincipal = max(0, round((float) $loan->amount - $paidPrincipal, 2));
        $outstandingInterest = round($outstandingInterest, 2);
        $outstandingPenalty = round($outstandingPenalty, 2);
        $outstandingFees = round($outstandingFees, 2);
        $totalOutstanding = round($outstandingPrincipal + $outstandingInterest + $outstandingPenalty + $outstandingFees, 2);

        $nextSchedule = $schedules
            ->filter(fn ($schedule) => !($schedule->is_fully_paid ?? false))
            ->sortBy('due_date')
            ->first();

        $nextInstallment = null;
        if ($nextSchedule) {
            $repayments = $nextSchedule->repayments ?? collect();
            $remainingPrincipal = max(0, (float) $nextSchedule->principal - (float) $repayments->sum('principal'));
            $remainingInterest = max(0, (float) $nextSchedule->interest - (float) $repayments->sum('interest'));
            $remainingFee = max(0, (float) ($nextSchedule->fee_amount ?? 0) - (float) $repayments->sum('fee_amount'));
            $remainingPenalty = max(0, (float) ($nextSchedule->penalty_amount ?? 0) - (float) $repayments->sum('penalt_amount'));
            $dueTotal = round($remainingPrincipal + $remainingInterest + $remainingFee + $remainingPenalty, 2);

            $nextInstallment = [
                'schedule_id' => $nextSchedule->id,
                'due_date' => $nextSchedule->due_date
                    ? Carbon::parse($nextSchedule->due_date)->format('M d, Y')
                    : 'N/A',
                'principal' => $remainingPrincipal,
                'interest' => $remainingInterest,
                'penalty' => $remainingPenalty,
                'fee' => $remainingFee,
                'total' => $dueTotal,
            ];
        }

        return response()->json([
            'loan_id' => $loan->id,
            'loan_no' => $loan->loanNo ?? (string) $loan->id,
            'customer_name' => $loan->customer->name ?? 'Unknown',
            'product_name' => $loan->product->name ?? 'N/A',
            'loan_amount' => (float) $loan->amount,
            'amount_total' => (float) ($loan->amount_total ?? 0),
            'total_paid' => round((float) $loan->getTotalPaidAmount(), 2),
            'outstanding_principal' => $outstandingPrincipal,
            'outstanding_interest' => $outstandingInterest,
            'outstanding_penalty' => $outstandingPenalty,
            'outstanding_fees' => $outstandingFees,
            'total_outstanding' => $totalOutstanding,
            'settle_amount' => round((float) $loan->total_amount_to_settle, 2),
            'next_installment' => $nextInstallment,
        ]);
    }

    /**
     * Get repayment history for a loan
     */
    public function getRepaymentHistory($loanId)
    {
        $repayments = Repayment::where('loan_id', $loanId)
            ->with(['schedule', 'bankAccount'])
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json($repayments);
    }

    /**
     * Get schedule details for repayment
     */
    public function getScheduleDetails($scheduleId)
    {
        $schedule = LoanSchedule::with(['loan'])->findOrFail($scheduleId);

        return response()->json([
            'schedule' => $schedule,
            'total_due' => $schedule->principal + $schedule->interest + $schedule->fee_amount + $schedule->penalty_amount,
        ]);
    }

    /**
     * Remove penalty from schedule
     */
    public function removePenalty(Request $request, $scheduleId)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'loan_id' => 'required|exists:loans,id',
                'schedule_id' => 'required|exists:loan_schedules,id',
                'reason' => 'nullable|string|max:500',
            ]);
            // Validate that the requested removal amount does not exceed current penalty
            $schedule = LoanSchedule::findOrFail($request->schedule_id);
            $currentPenaltyAmount = (float) $schedule->penalty_amount;
            $requestedAmount = (float) $request->amount;
            if ($requestedAmount > $currentPenaltyAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount cannot exceed current penalty amount.'
                ], 422);
            }

            $result = $this->repaymentService->removePenalty(
                $request->schedule_id,
                $request->reason,
                $request->amount,
                $request->loan_id
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Penalty removal error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove penalty: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate loan schedule
     */
    public function calculateSchedule(Request $request, $loanId)
    {
        try {
            $request->validate([
                'method' => 'required|in:flat_rate,reducing_equal_installment,reducing_equal_principal',
            ]);

            $loan = Loan::findOrFail($loanId);
            $schedules = $this->repaymentService->calculateSchedule($loan, $request->method);

            return response()->json([
                'success' => true,
                'schedules' => $schedules
            ]);
        } catch (\Exception $e) {
            Log::error('Schedule calculation error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk repayment processing
     */
    public function bulkRepayment(Request $request)
    {
        try {
            $request->validate([
                'repayments' => 'required|array|min:1',
                'repayments.*.loan_id' => 'required|exists:loans,id',
                'repayments.*.amount' => 'required|numeric|min:0.01',
                'repayments.*.payment_date' => 'required|date',
                'repayments.*.bank_account_id' => 'required|exists:bank_accounts,id',
            ]);
            $bankAccount = BankAccount::findOrFail($request->repayments[0]['bank_account_id']);
            
            $user = Auth::user();
            if (!$bankAccount->isAccessibleByUser($user)) {
                return redirect()->back()->withErrors(['repayments.0.bank_account_id' => 'You do not have access to this bank account.']);
            }

            $bankChartAccount = $bankAccount->chart_account_id;

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($request->repayments as $repaymentData) {
                try {
                    $paymentData = [
                        'payment_date' => $repaymentData['payment_date'],
                        'bank_account_id' => $repaymentData['bank_account_id'],
                        'bank_chart_account_id' => $bankChartAccount,
                    ];

                    $loan = Loan::with('product')->findOrFail($repaymentData['loan_id']);
                    $calculationMethod = $loan->product->interest_method ?? 'flat_rate';

                    $result = $this->repaymentService->processRepayment(
                        $repaymentData['loan_id'],
                        $repaymentData['amount'],
                        $paymentData,
                        $calculationMethod
                    );

                    $results[] = [
                        'loan_id' => $repaymentData['loan_id'],
                        'success' => true,
                        'result' => $result
                    ];
                    $successCount++;
                } catch (\Exception $e) {
                    $results[] = [
                        'loan_id' => $repaymentData['loan_id'],
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Processed {$successCount} repayments successfully, {$errorCount} failed",
                'results' => $results,
                'summary' => [
                    'total' => count($request->repayments),
                    'success' => $successCount,
                    'failed' => $errorCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk repayment error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk repayment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reverse a receipt (single)
     */
    public function reverseReceipt(Receipt $receipt)
    {
        try {
            $result = $this->repaymentService->reverseReceipt($receipt);
            
            return response()->json([
                'success' => true,
                'message' => $result['message']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reverse receipt', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Bulk reverse receipts
     */
    public function bulkReverseReceipts(Request $request)
    {
        $request->validate([
            'receipt_ids' => 'required|array',
            'receipt_ids.*' => 'required|integer|exists:receipts,id'
        ]);

        try {
            $receiptIds = $request->receipt_ids;
            $receipts = Receipt::whereIn('id', $receiptIds)
                ->whereIn('reference_type', ['loan_repayment', 'Repayment', 'loan'])
                ->get();

            $successCount = 0;
            $errors = [];

            foreach ($receipts as $receipt) {
                try {
                    $this->repaymentService->reverseReceipt($receipt);
                    $successCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'receipt_id' => $receipt->id,
                        'error' => $e->getMessage()
                    ];
                    Log::error('Failed to reverse receipt in bulk', [
                        'receipt_id' => $receipt->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Reversed {$successCount} receipt(s) successfully",
                'count' => $successCount,
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk reverse receipts error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk reverse: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore a reversed receipt
     */
    public function restoreReceipt($id)
    {
        try {
            $receipt = Receipt::withTrashed()->findOrFail($id);
            $result = $this->repaymentService->restoreReversedReceipt($receipt);
            
            return response()->json([
                'success' => true,
                'message' => $result['message']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to restore receipt', [
                'receipt_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Permanently delete a receipt
     */
    public function permanentlyDeleteReceipt($id)
    {
        try {
            $receipt = Receipt::withTrashed()->findOrFail($id);
            $result = $this->repaymentService->permanentlyDeleteReceipt($receipt);
            
            return response()->json([
                'success' => true,
                'message' => $result['message']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to permanently delete receipt', [
                'receipt_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Bulk permanently delete receipts
     */
    public function bulkPermanentlyDeleteReceipts(Request $request)
    {
        $request->validate([
            'receipt_ids' => 'required|array',
            'receipt_ids.*' => 'required|integer'
        ]);

        try {
            $receiptIds = $request->receipt_ids;
            $receipts = Receipt::withTrashed()
                ->whereIn('id', $receiptIds)
                ->whereIn('reference_type', ['loan_repayment', 'Repayment', 'loan'])
                ->get();

            $successCount = 0;
            $errors = [];

            foreach ($receipts as $receipt) {
                try {
                    $this->repaymentService->permanentlyDeleteReceipt($receipt);
                    $successCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'receipt_id' => $receipt->id,
                        'error' => $e->getMessage()
                    ];
                    Log::error('Failed to permanently delete receipt in bulk', [
                        'receipt_id' => $receipt->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Permanently deleted {$successCount} receipt(s)",
                'count' => $successCount,
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk permanent delete receipts error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk permanent delete: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Print receipt for repayment
     */
    public function printReceipt(Request $request, $id)
    {
        try {
            $repayment = Repayment::with([
                'loan.customer',
                'schedule',
                'receipt.bankAccount',
                'receipt.repayments',
            ])->findOrFail($id);

            $receipt = $repayment->receipt;
            $sharedReceiptCount = $receipt ? $receipt->repayments->count() : 0;
            $paymentDate = $repayment->payment_date
                ? Carbon::parse($repayment->payment_date)
                : ($receipt?->date ? Carbon::parse($receipt->date) : now());

            // Generate receipt data for thermal printer
            $receiptData = [
                'receipt_number' => $receipt?->display_number ?? 'N/A',
                'receipt_id' => $receipt?->id,
                'receipt_total_amount' => $receipt ? (float) $receipt->amount : null,
                'shared_receipt_installments' => $sharedReceiptCount > 1 ? $sharedReceiptCount : null,
                'date' => $paymentDate->format('d/m/Y'),
                'customer_name' => $repayment->customer->name,
                'loan_number' => $repayment->loan->loanNo,
                'amount_paid' => round((float) $repayment->amount_paid, 2),
                'schedule_number' => $repayment->schedule_number,
                'due_date' => $repayment->due_date
                    ? Carbon::parse($repayment->due_date)->format('d/m/Y')
                    : null,
                'remain_schedule' => round((float) $repayment->remain_schedule, 2),
                'remaining_schedules_count' => $repayment->remaining_schedules_count,
                'remaining_schedules_amount' => round((float) $repayment->remaining_schedules_amount, 2),
                'payment_breakdown' => [
                    'principal' => round((float) $repayment->principal, 2),
                    'interest' => round((float) $repayment->interest, 2),
                    'penalty' => round((float) $repayment->penalt_amount, 2),
                    'fee' => round((float) $repayment->fee_amount, 2),
                ],
                'bank_account' => $receipt?->bankAccount?->name ?? 'N/A',
                'received_by' => Auth::check() ? Auth::user()->name : 'System',
                'branch' => Auth::check() && Auth::user()->branch ? Auth::user()->branch->name : 'N/A',
            ];

            // If this is an AJAX call (used by the loan details page), return JSON
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'receipt_data' => $receiptData
                ]);
            }

            // Otherwise, render a printable HTML receipt (for direct browser access)
            return view('repayments.print', compact('receiptData'));
        } catch (\Exception $e) {
            Log::error('Receipt print error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    public function storeSettlementRepayment(Request $request)
    {
        try {
            $this->normalizeDcbRequestInput($request, 'dcb_settle', 'dcb', 'dcb_repay');

            $request->validate([
                'loan_id' => 'required|exists:loans,id',
                'payment_date' => 'required|date',
                'amount' => 'required|numeric|min:0.01',
                'payment_source' => 'required|in:bank,cash_deposit,dcb',
                'bank_account_id' => 'required_if:payment_source,bank,dcb|nullable|exists:bank_accounts,id',
                'cash_deposit_id' => 'required_if:payment_source,cash_deposit|nullable|exists:cash_collaterals,id',
                'dcb_msisdn' => 'required_if:payment_source,dcb|nullable|string|max:20',
                'dcb_control_no' => 'nullable|string|max:64',
            ]);

            info("request data >>>>>>>>>>>>>>", ['request' => $request->all()]);

            // Get loan and check if amount matches settle amount
            $loan = Loan::with(['product', 'customer', 'schedule.repayments'])->findOrFail($request->loan_id);
            $settleAmount = $loan->total_amount_to_settle;
            $paymentAmount = $request->amount;
            $isSettleRepayment = abs($paymentAmount - $settleAmount) <= 0.01;

            if (!$isSettleRepayment) {
                return redirect()->back()->with('error', 'Amount does not match the settle amount. Expected: TZS ' . number_format($settleAmount, 2));
            }

            Log::info('Processing settle repayment', [
                'loan_id' => $request->loan_id,
                'amount' => $paymentAmount,
                'settle_amount' => $settleAmount,
                'payment_source' => $request->payment_source
            ]);

            // Check cash deposit balance if using cash deposit
            if ($request->payment_source === 'cash_deposit') {
                $cashDeposit = \App\Models\CashCollateral::findOrFail($request->cash_deposit_id);

                if ($cashDeposit->amount < $request->amount) {
                    return redirect()->back()->with('error', 'Insufficient cash deposit balance. Available: TSHS ' . number_format($cashDeposit->amount, 2));
                }
            }

            if ($request->payment_source === 'dcb') {
                $dcbService = app(\App\Services\DcbPaymentService::class);
                if (!$dcbService->isEnabled()) {
                    return redirect()->back()->with('error', 'DCB payments are not enabled.');
                }

                $dcbResult = $dcbService->collectRepayment($loan, $paymentAmount, [
                    'payment_date' => $request->payment_date,
                    'bank_account_id' => $request->bank_account_id,
                    'msisdn' => $request->dcb_msisdn,
                    'control_no' => $request->dcb_control_no,
                    'settlement_type' => 'settlement',
                    'calculation_method' => $loan->product->interest_method ?? 'flat_rate',
                ]);

                if (!($dcbResult['success'] ?? false)) {
                    return redirect()->back()->with('error', $dcbResult['message'] ?? 'DCB settlement failed.');
                }

                if ($dcbResult['pending'] ?? false) {
                    return redirect()->back()->with('success', 'DCB collection initiated. Loan will settle when customer approves payment on their phone.');
                }

                $result = ['success' => true, 'loan_closed' => true];
            } else {
            // Prepare payment data based on source
            $paymentData = [
                'payment_date' => $request->payment_date,
                'payment_source' => $request->payment_source,
                'notes' => 'Settle repayment - pays current interest and all remaining principal'
            ];

            if ($request->payment_source === 'bank') {
                $bankAccount = BankAccount::findOrFail($request->bank_account_id);

                $user = Auth::user();
                if (!$bankAccount->isAccessibleByUser($user)) {
                    return redirect()->back()->withErrors(['bank_account_id' => 'You do not have access to this bank account.']);
                }

                $paymentData['bank_chart_account_id'] = $bankAccount->chart_account_id;
                $paymentData['bank_account_id'] = $request->bank_account_id;
            } else {
                $paymentData['cash_deposit_id'] = $request->cash_deposit_id;
            }

            $result = $this->repaymentService->processSettleRepayment($request->loan_id, $paymentAmount, $paymentData);
            }

            if ($result['success']) {
                $message = "Loan settled successfully. ";
                $message .= "Interest paid: TZS " . number_format($result['current_interest_paid'], 2) . ". ";
                $message .= "Principal paid: TZS " . number_format($result['total_principal_paid'], 2) . ".";

                if ($result['loan_closed']) {
                    $message .= " Loan has been closed.";
                }

                return redirect()->back()->with('success', $message);
            } else {
                return redirect()->back()->with('error', 'Failed to process settle repayment.');
            }
        } catch (\Exception $e) {
            Log::error('Settle repayment error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to process settle repayment: ' . $e->getMessage());
        }
    }
}
