<?php

namespace App\Services;

use App\Models\AccruedPenalty;
use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\Loan;
use App\Models\LoanBill;
use App\Models\LoanSchedule;
use App\Models\Repayment;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\GlTransaction;
use App\Models\ChartAccount;
use App\Models\BankAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class LoanRepaymentService
{
    /**
     * Process loan repayment with different calculation methods
     *
     * @param  int|string|null  $targetScheduleId  When set (e.g. from "Repay Schedule Item"), must match the earliest unpaid schedule for this loan.
     */
    public function processRepayment($loanId, $amount, $paymentData, $calculationMethod = 'flat_rate', $targetScheduleId = null)
    {
        // Store payment date for SMS
        $paymentDateForSms = $paymentData['payment_date'] ?? now();
        
        DB::beginTransaction();
        $loan = Loan::with(['product', 'customer', 'schedule', 'bills'])->findOrFail($loanId);
        $remainingAmount = round((float) $amount, 2);
        $processedRepayments = [];
        $totalPaidAmount = 0;
        $allSchedulePayments = [];
        $billPaidAmount = 0.0;

        // Get unpaid schedules ordered by due date
        $unpaidSchedules = $this->getUnpaidSchedules($loan);
        $openBills = $loan->bills()->open()->orderBy('id')->get();
        Log::info('Unpaid schedules loaded', [
            'count' => $unpaidSchedules->count(),
            'open_bills' => $openBills->count(),
        ]);

        if ($unpaidSchedules->count() === 0 && $openBills->count() === 0) {
            throw new \Exception('No unpaid schedules or loan bills found for this loan.');
        }

        if ($targetScheduleId !== null && $targetScheduleId !== '') {
            $targetScheduleId = (int) $targetScheduleId;
            if (!$loan->schedule()->whereKey($targetScheduleId)->exists()) {
                throw new \Exception('The selected schedule does not belong to this loan.');
            }
            $this->assertSchedulePayableInOrder($loan, $targetScheduleId);
        }

        // Apply open follow-up bills first (same loan number payment).
        $reservedForBills = 0.0;
        if ($openBills->count() > 0) {
            $openBillsTotal = round((float) $openBills->sum(fn ($bill) => $bill->remaining_amount), 2);
            $reservedForBills = min($remainingAmount, $openBillsTotal);
            $remainingAmount = round($remainingAmount - $reservedForBills, 2);
        }

        // Step 1: Calculate all schedule payments first to determine total amount
        foreach ($unpaidSchedules as $schedule) {
            if ($remainingAmount <= 0) {
                Log::info('No remaining amount, breaking loop', ['loanId' => $loanId]);
                break;
            }

            $schedulePayment = $this->processSchedulePayment($loan, $schedule, $remainingAmount, $paymentData);

            if (empty($schedulePayment) || !isset($schedulePayment['amount']) || $schedulePayment['amount'] <= 0) {
                Log::warning('No payment allocated for schedule, breaking loop', ['schedule_id' => $schedule->id]);
                break;
            }

            $remainingAmount -= $schedulePayment['amount'];
            $totalPaidAmount += $schedulePayment['amount'];
            $allSchedulePayments[] = [
                'schedule' => $schedule,
                'payment' => $schedulePayment
            ];
            $processedRepayments[] = $schedulePayment;
        }

        // If nothing could be allocated to bills or schedules, abort
        if ($totalPaidAmount <= 0 && $reservedForBills <= 0) {
            Log::warning('Repayment processing resulted in zero allocated amount. Aborting.', [
                'loan_id' => $loanId,
                'requested_amount' => $amount,
                'unpaid_schedules_count' => $unpaidSchedules->count(),
                'open_bills_count' => $openBills->count(),
            ]);

            DB::rollBack();
            throw new \Exception('Failed to record repayment because no amount could be allocated to any unpaid schedule or loan bill.');
        }

        // Step 2: Create ONE receipt for the total payment amount (only for bank/cash payments)
        $receipt = null;
        $receiptTotal = round($totalPaidAmount + $reservedForBills, 2);
        if (isset($paymentData['bank_account_id']) && $paymentData['bank_account_id'] && $receiptTotal > 0) {
            $receipt = $this->createReceipt($loan, $receiptTotal, $paymentData);
            Log::info('Receipt created for total payment', [
                'receipt_id' => $receipt->id,
                'amount' => $receiptTotal,
                'loan_id' => $loanId
            ]);
        }

        if ($reservedForBills > 0 && $openBills->count() > 0) {
            $billPaidAmount = $this->allocatePaymentToOpenBills(
                $loan,
                $openBills,
                $reservedForBills,
                $paymentData,
                $receipt
            );
            if ($billPaidAmount > 0) {
                $processedRepayments[] = [
                    'type' => 'loan_bill',
                    'amount' => $billPaidAmount,
                ];
            }
        }

        // Step 3: Create repayment records for each schedule (detail per installment in repayments table)
        foreach ($allSchedulePayments as $item) {
            $schedule = $item['schedule'];
            $schedulePayment = $item['payment'];

            $repayment = $this->createRepaymentRecord($loan, $schedule, $schedulePayment, $paymentData, $receipt);
            if (!$repayment) {
                Log::error('Failed to create repayment', ['loanId' => $loanId, 'schedule_id' => $schedule->id]);
                throw new \Exception('Repayment not saved');
            }

            if ($schedule->relationLoaded('repayments')) {
                $schedule->setRelation('repayments', $schedule->repayments->push($repayment));
            }

            $this->markSchedulePaidIfSettled($schedule);

            if (isset($paymentData['cash_deposit_id']) && $paymentData['cash_deposit_id']) {
                Log::info('Processing cash deposit repayment', ['cash_deposit_id' => $paymentData['cash_deposit_id']]);
                $this->createJournalEntry($loan, $repayment, $schedulePayment, $paymentData);
            } elseif (!isset($paymentData['bank_account_id']) || !$paymentData['bank_account_id']) {
                Log::warning('No payment method provided', ['loanId' => $loanId]);
            }
        }

        // Step 4: One bank debit (on receipt) + aggregated credits in GL (books stay balanced, not split per schedule line)
        if ($receipt && $allSchedulePayments) {
            $this->createAggregatedReceiptCredits($loan, $receipt, $allSchedulePayments);
        }

        // Check if loan is fully paid and close it automatically
        if ($this->isLoanFullyPaid($loan)) {
            $closed = $loan->closeLoan();
            if ($closed) {
                Log::info('Loan automatically closed after complete repayment', [
                    'loanId' => $loanId,
                    'loanNo' => $loan->loanNo
                ]);
            } else {
                Log::warning('Failed to close loan despite being fully paid', [
                    'loanId' => $loanId,
                    'loanNo' => $loan->loanNo
                ]);
            }
        }

        Log::info('Repayment transaction committed', ['loanId' => $loanId]);
        DB::commit();

        // Refresh loan to get updated outstanding balance
        $loan->refresh();
        $loan->load(['schedule', 'customer', 'company', 'branch.company', 'bills']);

        $grandPaid = round($totalPaidAmount + $billPaidAmount, 2);

        // Send SMS notification to customer after successful repayment
        $this->sendRepaymentSms($loan, $grandPaid, $paymentDateForSms);

        return [
            'success' => true,
            'paid_amount' => $grandPaid,
            'balance' => $remainingAmount,
            'processed_repayments' => $processedRepayments,
            'loan_status' => $loan->status,
            'receipt_id' => $receipt ? $receipt->id : null
        ];
    }

    /**
     * Record a staff payment against a single loan bill (bank receipt).
     */
    public function processBillPayment(LoanBill $bill, float $amount, array $paymentData): array
    {
        DB::beginTransaction();

        try {
            $loan = Loan::with(['product', 'customer', 'bills'])->findOrFail($bill->loan_id);
            $bill->refresh();

            if (! $bill->isOpen()) {
                throw new \Exception('Bill is not open for payment.');
            }

            $amount = min(round($amount, 2), $bill->remaining_amount);
            if ($amount <= 0) {
                throw new \Exception('Invalid bill payment amount.');
            }

            $receipt = $this->createReceipt($loan, $amount, array_merge($paymentData, [
                'description' => $paymentData['comments']
                    ?? $paymentData['description']
                    ?? "Loan bill payment: {$bill->description} - Loan #{$loan->id}",
            ]));

            // Override receipt description when createReceipt ignored custom description.
            if (! empty($paymentData['comments']) || ! empty($paymentData['description'])) {
                $receipt->update([
                    'description' => $paymentData['comments']
                        ?? $paymentData['description']
                        ?? $receipt->description,
                ]);
            }

            $applied = $this->allocatePaymentToOpenBills(
                $loan,
                collect([$bill]),
                $amount,
                $paymentData,
                $receipt
            );

            if ($applied <= 0) {
                throw new \Exception('Bill payment was not applied.');
            }

            DB::commit();

            return [
                'success' => true,
                'paid_amount' => $applied,
                'receipt_id' => $receipt->id,
                'bill_status' => $bill->fresh()->status,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Apply available amount to open loan bills (FIFO). Returns amount applied.
     */
    private function allocatePaymentToOpenBills(Loan $loan, $openBills, float $availableAmount, array $paymentData, ?Receipt $receipt = null): float
    {
        $remaining = round($availableAmount, 2);
        $totalApplied = 0.0;
        $userId = $paymentData['user_id'] ?? auth()->id();

        foreach ($openBills as $bill) {
            if ($remaining <= 0) {
                break;
            }

            $bill->refresh();
            if (! $bill->isOpen()) {
                continue;
            }

            $receivableAccountId = $bill->receivable_account_id
                ?: $loan->product?->principal_receivable_account_id;
            if ($receipt && ! $receivableAccountId) {
                throw new \RuntimeException("Loan bill #{$bill->id} has no receivable chart account.");
            }

            $applied = $bill->applyPayment($remaining);
            if ($applied <= 0) {
                continue;
            }

            $remaining = round($remaining - $applied, 2);
            $totalApplied = round($totalApplied + $applied, 2);

            if ($receipt && $receivableAccountId) {
                ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'chart_account_id' => $receivableAccountId,
                    'amount' => $applied,
                    'description' => "Loan bill: {$bill->description}",
                ]);

                GlTransaction::create([
                    'chart_account_id' => $receivableAccountId,
                    'customer_id' => $loan->customer_id,
                    'amount' => $applied,
                    'nature' => 'credit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $receipt->date,
                    'description' => "Loan bill payment: {$bill->description} (Loan #{$loan->id})",
                    'branch_id' => $receipt->branch_id,
                    'user_id' => $userId,
                ]);
            }

            if ($receipt && $bill->status === LoanBill::STATUS_PAID && ! $bill->receipt_id) {
                $bill->update(['receipt_id' => $receipt->id]);
            }

            Log::info('Loan bill payment applied', [
                'bill_id' => $bill->id,
                'loan_id' => $loan->id,
                'applied' => $applied,
                'status' => $bill->status,
            ]);
        }

        return $totalApplied;
    }

    /**
     * Process repayment lines from a receipt voucher: apply each (schedule_id, amount) to the loan
     * and create repayment records + GL transactions. Caller must have created the receipt and bank debit GL.
     *
     * @param \App\Models\Loan $loan
     * @param \App\Models\Receipt $receipt
     * @param array $scheduleAmounts Array of ['schedule_id' => int, 'amount' => float]
     * @param array $paymentData ['payment_date', 'bank_account_id', 'bank_chart_account_id' optional]
     * @return array ['success' => true, 'total_paid' => float]
     */
    public function processRepaymentLinesToReceipt($loan, $receipt, array $scheduleAmounts, array $paymentData = [])
    {
        $loan->load(['product', 'customer', 'schedule']);
        $totalPaid = 0;
        $allSchedulePayments = [];

        foreach ($scheduleAmounts as $line) {
            $scheduleId = (int) ($line['schedule_id'] ?? 0);
            $amount = (float) ($line['amount'] ?? 0);
            if ($scheduleId <= 0 || $amount <= 0) {
                continue;
            }

            $schedule = LoanSchedule::with('repayments')->find($scheduleId);
            if (!$schedule || $schedule->loan_id != $loan->id) {
                Log::warning('Invalid or mismatched schedule in receipt voucher', ['schedule_id' => $scheduleId, 'loan_id' => $loan->id]);
                continue;
            }

            $this->assertSchedulePayableInOrder($loan, $scheduleId);

            $schedulePayment = $this->processSchedulePayment($loan, $schedule, $amount, $paymentData);
            if (empty($schedulePayment['amount']) || $schedulePayment['amount'] <= 0) {
                continue;
            }

            $repayment = $this->createRepaymentRecord($loan, $schedule, $schedulePayment, $paymentData, $receipt);
            if ($repayment) {
                if ($schedule->relationLoaded('repayments')) {
                    $schedule->setRelation('repayments', $schedule->repayments->push($repayment));
                }

                $this->markSchedulePaidIfSettled($schedule);
                $allSchedulePayments[] = ['schedule' => $schedule, 'payment' => $schedulePayment];
                $totalPaid += $schedulePayment['amount'];
            }
        }

        if ($totalPaid > 0 && $allSchedulePayments) {
            $this->createAggregatedReceiptCredits($loan, $receipt, $allSchedulePayments);
        }

        if ($totalPaid > 0 && $this->isLoanFullyPaid($loan)) {
            $loan->closeLoan();
        }
        if ($totalPaid > 0) {
            $this->sendRepaymentSms($loan, $totalPaid, $paymentData['payment_date'] ?? now());
        }

        return ['success' => true, 'total_paid' => $totalPaid];
    }

    /**
     * Send SMS notification to customer and company after repayment.
     */
    private function sendRepaymentSms($loan, $amount, $paymentDate = null): void
    {
        app(LoanSmsNotificationService::class)->sendRepaymentNotification($loan, (float) $amount, $paymentDate);
    }
    private function getUnpaidSchedules($loan)
    {
        // Must match LoanSchedule accessors (paid_amount, remaining_amount, total_due):
        // - Repayment uses SoftDeletes: raw SQL must ignore deleted rows (otherwise "paid" sums are inflated and unpaid lists go empty while the UI still shows due amounts).
        // - Daily accrual products use accrued_interest as the interest component; schedules display matches that, not the flat interest column.
        $interestExpr = $loan->usesDailyInterestAccrual()
            ? 'COALESCE(loan_schedules.accrued_interest, 0)'
            : 'COALESCE(loan_schedules.interest, 0)';

        return $loan->schedule()
            ->where('status', '!=', 'restructured') // Exclude restructured schedules
            ->whereRaw(
                "(
                SELECT COALESCE(SUM(principal), 0) + COALESCE(SUM(interest), 0) + COALESCE(SUM(fee_amount), 0) + COALESCE(SUM(penalt_amount), 0)
                FROM repayments
                WHERE repayments.loan_schedule_id = loan_schedules.id
                AND repayments.deleted_at IS NULL
            ) < (
                loan_schedules.principal + {$interestExpr} + COALESCE(loan_schedules.fee_amount, 0) + COALESCE(loan_schedules.penalty_amount, 0)
            )"
            )
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Process payment for a single schedule
     */
    private function processSchedulePayment($loan, $schedule, $remainingAmount, $paymentData)
    {
        // Check if payment is made before or on due date and remove penalties if applicable
        $this->checkAndRemovePenaltyForOnTimePayment($schedule, $paymentData);

        // Get already paid amounts for this schedule
        $paidAmounts = $this->getPaidAmountsForSchedule($schedule);

        if (!$schedule->relationLoaded('loan')) {
            $schedule->setRelation('loan', $loan);
        }

        $interestDue = (float) $schedule->balance_interest_component;

        // Calculate remaining amounts (aligned with getUnpaidSchedules / schedule UI)
        $remainingAmounts = [
            'principal' => $schedule->principal - $paidAmounts['principal'],
            'interest' => $interestDue - $paidAmounts['interest'],
            'fee_amount' => $schedule->fee_amount - $paidAmounts['fee_amount'],
            'penalty_amount' => $schedule->penalty_amount - $paidAmounts['penalty_amount'],
        ];

        // Get repayment order from loan product
        $repaymentOrder = $this->getRepaymentOrder($loan);

        $allocatedAmounts = [
            'principal' => 0,
            'interest' => 0,
            'fee_amount' => 0,
            'penalty_amount' => 0
        ];

        $currentAmount = $remainingAmount;

        // Allocate payment according to repayment order
        foreach ($repaymentOrder as $component) {
            if ($currentAmount <= 0)
                break;

            if (isset($remainingAmounts[$component]) && $remainingAmounts[$component] > 0) {
                $amountToPay = min($currentAmount, $remainingAmounts[$component]);
                $allocatedAmounts[$component] = $amountToPay;
                $currentAmount -= $amountToPay;
            }
        }
        return [
            'schedule_id' => $schedule->id,
            'amount' => $remainingAmount - $currentAmount,
            'principal' => $allocatedAmounts['principal'],
            'interest' => $allocatedAmounts['interest'],
            'fee_amount' => $allocatedAmounts['fee_amount'],
            'penalty_amount' => $allocatedAmounts['penalty_amount']
        ];
    }

    /**
     * Get repayment order from loan product
     */
    private function getRepaymentOrder($loan)
    {
        // Default order if not configured
        $defaultOrder = ['penalty_amount', 'fee_amount', 'interest', 'principal'];

        if ($loan->product && $loan->product->repayment_order) {
            $rawOrder = $loan->product->repayment_order;

            // Normalize to array: accept array, JSON string, or comma-separated string
            if (is_array($rawOrder)) {
                $repaymentComponents = $rawOrder;
            } else if (is_string($rawOrder)) {
                $trimmed = trim($rawOrder);
                if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                    $decoded = json_decode($trimmed, true);
                    $repaymentComponents = is_array($decoded) ? $decoded : explode(',', $rawOrder);
                } else {
                    $repaymentComponents = explode(',', $rawOrder);
                }
            } else {
                $repaymentComponents = [];
            }

            $validComponents = [];

            // Map the components to the correct field names
            foreach ($repaymentComponents as $component) {
                $component = is_string($component) ? trim($component) : $component;
                switch ($component) {
                    case 'penalties':
                    case 'penalty':
                    case 'penalty_amount':
                        $validComponents[] = 'penalty_amount';
                        break;
                    case 'fees':
                    case 'fee':
                    case 'fee_amount':
                        $validComponents[] = 'fee_amount';
                        break;
                    case 'interest':
                        $validComponents[] = 'interest';
                        break;
                    case 'principal':
                        $validComponents[] = 'principal';
                        break;
                }
            }

            return !empty($validComponents) ? $validComponents : $defaultOrder;
        }

        return $defaultOrder;
    }

    /**
     * Get paid amounts for a schedule
     */
    private function getPaidAmountsForSchedule($schedule)
    {
        $repayments = $schedule->repayments;

        return [
            'principal' => $repayments->sum('principal'),
            'interest' => $repayments->sum('interest'),
            'fee_amount' => $repayments->sum('fee_amount'),
            'penalty_amount' => $repayments->sum('penalt_amount')
        ];
    }

    /**
     * Create receipt for total payment amount and bank debit GL transaction
     */
    private function createReceipt($loan, $totalAmount, $paymentData)
    {
        $receipt = Receipt::create([
            'reference' => $loan->id,
            'reference_type' => 'loan_repayment',
            'reference_number' => null,
            'amount' => $totalAmount,
            'date' => $paymentData['payment_date'] ?? now(),
            'description' => $paymentData['description']
                ?? $paymentData['comments']
                ?? "Loan repayment for {$loan->customer->name} - Loan #{$loan->id}",
            'user_id' => $paymentData['user_id'] ?? auth()->id(),
            'bank_account_id' => $paymentData['bank_account_id'] ?? $loan->bank_account_id,
            // Ensure receipt is linked to the customer model as well as payee fields
            'payee_type' => 'customer',
            'payee_id' => $loan->customer_id,
            'payee_name' => $loan->customer->name,
            'customer_id' => $loan->customer_id,
            'branch_id' => $paymentData['branch_id'] ?? auth()->user()->branch_id ?? 1,
            'approved' => true,
            'approved_by' => $paymentData['user_id'] ?? auth()->id(),
            'approved_at' => now(),
        ]);

        Log::info('Receipt created', [
            'receipt_id' => $receipt->id,
            'amount' => $totalAmount,
            'loan_id' => $loan->id
        ]);

        // Create bank debit GL transaction (once for total amount)
        $bankAccount = BankAccount::find($receipt->bank_account_id);
        if ($bankAccount && $bankAccount->chart_account_id) {
            GlTransaction::create([
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $totalAmount,
                'nature' => 'debit',
                'transaction_id' => $receipt->id,
                'transaction_type' => 'receipt',
                'date' => $receipt->date,
                'description' => "Loan repayment received - {$loan->customer->name}",
                'branch_id' => $receipt->branch_id,
                'user_id' => $paymentData['user_id'] ?? auth()->id(),
            ]);
            Log::info('Bank debit GL transaction created', [
                'receipt_id' => $receipt->id,
                'amount' => $totalAmount
            ]);
        }

        return $receipt;
    }

    /**
     * Block paying a later installment while an earlier one still has a balance.
     */
    public function assertSchedulePayableInOrder(Loan $loan, int $scheduleId): void
    {
        $firstUnpaid = $this->getUnpaidSchedules($loan)->first();
        if (!$firstUnpaid) {
            throw new \Exception('No unpaid schedules found for this loan.');
        }
        if ((int) $firstUnpaid->id !== (int) $scheduleId) {
            $due = Carbon::parse($firstUnpaid->due_date)->format('M d, Y');
            throw new \Exception(
                "You must pay the earliest unpaid installment first (due {$due}). Cannot pay a later schedule while earlier ones are outstanding."
            );
        }
    }

    /**
     * Block deleting an earlier repayment when a later installment was already paid.
     */
    public function assertCanDeleteRepayment(Repayment $repayment): void
    {
        $dueDate = $repayment->due_date;
        if (!$dueDate && $repayment->schedule) {
            $dueDate = $repayment->schedule->due_date;
        }

        if (!$dueDate) {
            return;
        }

        $laterPaidExists = Repayment::where('loan_id', $repayment->loan_id)
            ->where('id', '!=', $repayment->id)
            ->where(function ($query) use ($dueDate, $repayment) {
                $query->where('due_date', '>', $dueDate)
                    ->orWhereHas('schedule', function ($q) use ($dueDate) {
                        $q->where('due_date', '>', $dueDate);
                    });
            })
            ->exists();

        if ($laterPaidExists) {
            throw new \Exception(
                'Cannot delete this repayment because a later installment has already been paid. Delete or reverse later repayments first, starting from the most recent.'
            );
        }
    }

    /**
     * Post one bank debit (already on receipt) and aggregated credit lines by GL account.
     * Repayments table still stores per-schedule breakdown; GL shows consolidated credits.
     */
    private function createAggregatedReceiptCredits(Loan $loan, Receipt $receipt, array $allSchedulePayments): void
    {
        $creditsByAccount = [];
        $scheduleIds = [];
        $missingAccounts = [];

        foreach ($allSchedulePayments as $item) {
            $schedule = $item['schedule'];
            $schedulePayment = $item['payment'];
            $scheduleIds[] = $schedule->id;

            $accounts = $this->resolveComponentChartAccounts($loan, $schedule, $schedulePayment);

            foreach (['principal', 'interest', 'fee_amount', 'penalty_amount'] as $component) {
                $amount = (float) ($schedulePayment[$component] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $accountId = $accounts[$component] ?? null;
                if (!$accountId) {
                    $missingAccounts[] = [
                        'component' => $component,
                        'amount' => $amount,
                        'schedule_id' => $schedule->id,
                    ];
                    continue;
                }
                $creditsByAccount[$accountId] = ($creditsByAccount[$accountId] ?? 0) + $amount;
            }
        }

        if (!empty($missingAccounts)) {
            throw new \Exception(
                'Cannot post repayment: loan product is missing chart account(s) for '
                . collect($missingAccounts)->pluck('component')->unique()->implode(', ')
                . '. Configure accounts on the loan product before recording payment.'
            );
        }

        $scheduleLabel = count($scheduleIds) === 1
            ? 'Schedule #' . $scheduleIds[0]
            : 'Schedules #' . implode(', #', $scheduleIds);

        $totalCredits = 0.0;
        foreach ($creditsByAccount as $accountId => $amount) {
            $amount = round($amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $totalCredits += $amount;
            $description = "Loan repayment ({$scheduleLabel}) - Loan #{$loan->id}";

            ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'chart_account_id' => $accountId,
                'amount' => $amount,
                'description' => $description,
            ]);

            GlTransaction::create([
                'chart_account_id' => $accountId,
                'customer_id' => $loan->customer_id,
                'amount' => $amount,
                'nature' => 'credit',
                'transaction_id' => $receipt->id,
                'transaction_type' => 'receipt',
                'date' => $receipt->date,
                'description' => $description,
                'branch_id' => $receipt->branch_id,
                'user_id' => auth()->id(),
            ]);
        }

        $this->assertReceiptGlBalanced($receipt->id, (float) $receipt->amount);

        Log::info('Aggregated receipt credits posted', [
            'receipt_id' => $receipt->id,
            'credit_lines' => count($creditsByAccount),
            'total_credits' => round($totalCredits, 2),
            'schedules' => $scheduleIds,
        ]);
    }

    /**
     * Ensure receipt debit equals sum of receipt credits (double-entry integrity).
     */
    private function assertReceiptGlBalanced(int $receiptId, ?float $expectedTotal = null): void
    {
        $debitTotal = (float) GlTransaction::where('transaction_id', $receiptId)
            ->where('transaction_type', 'receipt')
            ->where('nature', 'debit')
            ->sum('amount');

        $creditTotal = (float) GlTransaction::where('transaction_id', $receiptId)
            ->where('transaction_type', 'receipt')
            ->where('nature', 'credit')
            ->sum('amount');

        if (abs($debitTotal - $creditTotal) > 0.02) {
            throw new \Exception(
                "Receipt GL is out of balance (debit {$debitTotal} vs credit {$creditTotal}). Transaction rolled back."
            );
        }

        if ($expectedTotal !== null && $debitTotal > 0 && abs($debitTotal - $expectedTotal) > 0.02) {
            throw new \Exception(
                "Receipt amount ({$expectedTotal}) does not match bank GL debit ({$debitTotal}). Transaction rolled back."
            );
        }
    }

    /**
     * Resolve GL accounts for each repayment component (same rules as per-schedule posting).
     */
    private function resolveComponentChartAccounts(Loan $loan, LoanSchedule $schedule, array $schedulePayment): array
    {
        $feeAccountId = null;
        if (isset($loan->product->fees_ids)) {
            $feeIds = is_array($loan->product->fees_ids) ? $loan->product->fees_ids : json_decode($loan->product->fees_ids, true);
            if (is_array($feeIds)) {
                foreach ($feeIds as $feeId) {
                    $fee = \DB::table('fees')->where('id', $feeId)->first();
                    if ($fee && $fee->include_in_schedule == 1 && $fee->chart_account_id) {
                        $feeAccountId = $fee->chart_account_id;
                        break;
                    }
                }
            }
        }

        $penaltyAccountId = null;
        if (isset($loan->product->penalty_ids)) {
            $penaltyIds = is_array($loan->product->penalty_ids) ? $loan->product->penalty_ids : json_decode($loan->product->penalty_ids, true);
            if (is_array($penaltyIds)) {
                foreach ($penaltyIds as $penaltyId) {
                    $penalty = \DB::table('penalties')->where('id', $penaltyId)->first();
                    if ($penalty && $penalty->penalty_receivables_account_id) {
                        $penaltyAccountId = $penalty->penalty_receivables_account_id;
                        break;
                    }
                }
            }
        }

        $interestAccountId = $loan->product->interest_revenue_account_id ?? null;
        $receivableId = $loan->product->interest_receivable_account_id;
        $incomeId = $loan->product->interest_revenue_account_id;

        if ($receivableId && $incomeId && ($schedulePayment['interest'] ?? 0) > 0) {
            $exists = GlTransaction::where('chart_account_id', $receivableId)
                ->where('customer_id', $loan->customer_id)
                ->where('date', $schedule->due_date)
                ->where('amount', $schedulePayment['interest'])
                ->where('transaction_type', 'Mature Interest')
                ->exists();

            $incomeExists = GlTransaction::where('chart_account_id', $incomeId)
                ->where('customer_id', $loan->customer_id)
                ->where('date', $schedule->due_date)
                ->where('amount', $schedulePayment['interest'])
                ->where('transaction_type', 'Mature Interest')
                ->exists();

            if ($exists && $incomeExists) {
                $interestAccountId = $receivableId;
            }
        }

        return [
            'principal' => $loan->product->principal_receivable_account_id ?? null,
            'interest' => $interestAccountId,
            'fee_amount' => $feeAccountId,
            'penalty_amount' => $penaltyAccountId,
        ];
    }

    /**
     * Rebuild aggregated GL credits after a partial repayment delete on a shared receipt.
     */
    private function rebuildReceiptGlFromRepayments(Receipt $receipt, Loan $loan): void
    {
        GlTransaction::where('transaction_id', $receipt->id)
            ->where('transaction_type', 'receipt')
            ->where('nature', 'credit')
            ->delete();

        ReceiptItem::where('receipt_id', $receipt->id)->delete();

        $repayments = Repayment::where('receipt_id', $receipt->id)->get();
        $allSchedulePayments = [];

        foreach ($repayments as $repayment) {
            $schedule = LoanSchedule::with('loan')->find($repayment->loan_schedule_id);
            if (!$schedule) {
                continue;
            }
            if (!$schedule->relationLoaded('loan')) {
                $schedule->setRelation('loan', $loan);
            }
            $allSchedulePayments[] = [
                'schedule' => $schedule,
                'payment' => [
                    'principal' => (float) $repayment->principal,
                    'interest' => (float) $repayment->interest,
                    'fee_amount' => (float) $repayment->fee_amount,
                    'penalty_amount' => (float) $repayment->penalt_amount,
                ],
            ];
        }

        $total = round($repayments->sum(fn ($r) => $this->repaymentTotal($r)), 2);
        $receipt->update(['amount' => $total]);

        $bankDebit = GlTransaction::where('transaction_id', $receipt->id)
            ->where('transaction_type', 'receipt')
            ->where('nature', 'debit')
            ->first();

        if ($bankDebit) {
            if ($total <= 0) {
                $bankDebit->delete();
            } else {
                $bankDebit->update(['amount' => $total]);
            }
        }

        if ($total > 0 && $allSchedulePayments) {
            $this->createAggregatedReceiptCredits($loan, $receipt, $allSchedulePayments);
        } elseif ($total <= 0) {
            $this->purgeReceiptCompletely($receipt);
        } else {
            throw new \Exception(
                "Receipt #{$receipt->id} still has amount {$total} but no repayments are linked. Clean up manually or contact support."
            );
        }
    }

    /**
     * Create repayment record
     */
    private function createRepaymentRecord($loan, $schedule, $schedulePayment, $paymentData, $receipt = null)
    {
        $repaymentData = [
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'loan_schedule_id' => $schedule->id,
            'receipt_id' => $receipt ? $receipt->id : null,
            // Store the bank GL (chart account) as designed by the schema
            'bank_account_id' => $paymentData['bank_chart_account_id'] ?? null,
            'payment_date' => $paymentData['payment_date'] ?? now(),
            'due_date' => $schedule->due_date,
            'principal' => $schedulePayment['principal'],
            'interest' => $schedulePayment['interest'],
            'fee_amount' => $schedulePayment['fee_amount'],
            'penalt_amount' => $schedulePayment['penalty_amount'],
            'cash_deposit' => $schedulePayment['amount'],
        ];

        Log::info('Creating repayment record', $repaymentData);

        try {
            $repayment = Repayment::create($repaymentData);
            Log::info('Repayment created successfully', ['id' => $repayment->id, 'receipt_id' => $receipt ? $receipt->id : null]);
            return $repayment;
        } catch (\Exception $e) {
            Log::error('Failed to create repayment record: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create GL transactions for a repayment linked to a receipt
     * This creates credit entries for each component (principal, interest, fees, penalties)
     */
    private function createGLTransactions($loan, $repayment, $schedulePayment, $paymentData, $receipt)
    {
        Log::info('Starting createGLTransactions', [
            'loan_id' => $loan->id,
            'repayment_id' => $repayment->id,
            'schedulePayment' => $schedulePayment,
            'bank_account_id' => $receipt->bank_account_id,
            'receipt_id' => $receipt->id
        ]);

        // Get chart accounts for components
        $feeAccountId = null;
        if (isset($loan->product->fees_ids)) {
            $feeIds = is_array($loan->product->fees_ids) ? $loan->product->fees_ids : json_decode($loan->product->fees_ids, true);
            if (is_array($feeIds)) {
                foreach ($feeIds as $feeId) {
                    $fee = \DB::table('fees')->where('id', $feeId)->first();
                    if ($fee && $fee->include_in_schedule == 1 && $fee->chart_account_id) {
                        $feeAccountId = $fee->chart_account_id;
                        break;
                    }
                }
            }
        }

        $penaltyAccountId = null;
        if (isset($loan->product->penalty_ids)) {
            $penaltyIds = is_array($loan->product->penalty_ids) ? $loan->product->penalty_ids : json_decode($loan->product->penalty_ids, true);
            if (is_array($penaltyIds)) {
                foreach ($penaltyIds as $penaltyId) {
                    $penalty = \DB::table('penalties')->where('id', $penaltyId)->first();
                    if ($penalty && $penalty->penalty_receivables_account_id) {
                        $penaltyAccountId = $penalty->penalty_receivables_account_id;
                        break;
                    }
                }
            }
        }

        $chartAccounts = [
            'principal' => $loan->product->principal_receivable_account_id ?? null,
            'interest' => $loan->product->interest_revenue_account_id ?? null,
            'fee_amount' => $feeAccountId,
            'penalty_amount' => $penaltyAccountId ?? null
        ];

        $components = [
            'principal' => $schedulePayment['principal'],
            'interest' => $schedulePayment['interest'],
            'fee_amount' => $schedulePayment['fee_amount'],
            'penalty_amount' => $schedulePayment['penalty_amount']
        ];

        // Check if interest receivable has been posted
        $receivableId = $loan->product->interest_receivable_account_id;
        $incomeId = $loan->product->interest_revenue_account_id;

        if ($receivableId && $incomeId) {
            $exists = GlTransaction::where('chart_account_id', $receivableId)
                ->where('customer_id', $loan->customer_id)
                ->where('date', $repayment->due_date)
                ->where('amount', $schedulePayment['interest'])
                ->where('transaction_type', 'Mature Interest')
                ->exists();

            $incomeExists = GlTransaction::where('chart_account_id', $incomeId)
                ->where('customer_id', $loan->customer_id)
                ->where('date', $repayment->due_date)
                ->where('amount', $schedulePayment['interest'])
                ->where('transaction_type', 'Mature Interest')
                ->exists();

            if ($exists && $incomeExists) {
                Log::info('Interest receivable and interest income have been posted, using receivable account');
                $chartAccounts['interest'] = $receivableId;
            }
        }

        // Credit: Each component to its respective account
        foreach ($components as $component => $amount) {
            $accountId = $chartAccounts[$component] ?? null;
            if ($amount > 0 && $accountId) {
                Log::info('GL Credit Posting', [
                    'component' => $component,
                    'chart_account_id' => $accountId,
                    'amount' => $amount,
                    'customer_id' => $loan->customer_id,
                    'receipt_id' => $receipt->id
                ]);
                ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'chart_account_id' => $accountId,
                    'amount' => $amount,
                    'description' => ucfirst($component) . " payment for loan #{$loan->id} - Schedule #{$repayment->loan_schedule_id}"
                ]);
                GlTransaction::create([
                    'chart_account_id' => $accountId,
                    'customer_id' => $loan->customer_id,
                    'amount' => $amount,
                    'nature' => 'credit',
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => $receipt->date,
                    'description' => ucfirst($component) . " payment for loan #{$loan->id} - Schedule #{$repayment->loan_schedule_id}",
                    'branch_id' => $receipt->branch_id,
                    'user_id' => auth()->id(),
                ]);
            } else if ($amount > 0 && !$accountId) {
                Log::error('Missing chart account for GL component', [
                    'component' => $component,
                    'amount' => $amount,
                    'loan_id' => $loan->id,
                    'receipt_id' => $receipt->id
                ]);
            }
        }
    }

    /**
     * Create journal entry for cash deposit payments
     */
    /**
     * Create journal entry for cash deposit payments
     */
    private function createJournalEntry($loan, $repayment, $schedulePayment, $paymentData)
    {
        // Log::info('createJournalEntry called', [
        //     'loan_id' => $loan->id,
        //     'repayment_id' => $repayment->id ?? null,
        //     'schedulePayment' => $schedulePayment,
        //     'cash_deposit_id' => $paymentData['cash_deposit_id'] ?? null,
        //     'cash_deposit_before' => $cashDeposit->amount,
        // ]);
        // Get cash deposit account
        $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);
        // Reduce cash deposit balance
        $cashDeposit->decrement('amount', $schedulePayment['amount']);
        Log::info('Cash collateral decremented', [
            'cash_deposit_id' => $cashDeposit->id,
            'cash_deposit_after' => $cashDeposit->amount,
        ]);

        // Create journal record for withdrawal from cash deposit
        $journal = Journal::create([
            'reference' => $repayment->id,
            'reference_type' => 'Withdrawal',
            'customer_id' => $loan->customer_id,
            'description' => "Loan repayment from cash deposit for {$loan->customer->name} - Loan #{$loan->id}",
            'branch_id' => auth()->user()->branch_id ?? 1,
            'user_id' => auth()->id(),
            'date' => $paymentData['payment_date'] ?? now(),
        ]);
        Log::info('Journal created', ['journal_id' => $journal->id]);

        // Debit: Cash collateral account (total amount)
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'amount' => $schedulePayment['amount'],
            'description' => "Loan repayment from cash deposit",
            'nature' => 'debit',
        ]);
        Log::info('JournalItem debit created', ['journal_id' => $journal->id, 'amount' => $schedulePayment['amount']]);

        // Always credit all components, not only principal
        $chartAccounts = [
            'principal' => $loan->product->principal_receivable_account_id ?? null,
            'interest' => $loan->product->interest_revenue_account_id ?? null,
            'fee_amount' => $loan->product->fee_income_account_id ?? null,
            'penalty_amount' => $loan->product->penalty_receivables_account_id ?? null
        ];

        Log::info('chart accounts', $chartAccounts);

        $components = [
            'principal' => $schedulePayment['principal'],
            'interest' => $schedulePayment['interest'],
            'fee_amount' => $schedulePayment['fee_amount'],
            'penalty_amount' => $schedulePayment['penalty_amount']
        ];
        info("components amounts", $components);

        // check if the interest receivable has been posted first, if not, do not create the interest receivable by debiting  and credit interest income
        $receivableId = $loan->product->interest_receivable_account_id;
        $incomeId = $loan->product->interest_revenue_account_id;

        Log::info("Interest accounts for product {$loan->product->id}", [
            'receivable_id' => $receivableId,
            'income_id' => $incomeId,
        ]);

        if (!$receivableId) {
            Log::warning("Missing interest accounts for product {$loan->product->id}");
            return 0;
        }

        $exists = GlTransaction::where('chart_account_id', $receivableId)
            ->where('customer_id', $loan->customer_id)
            ->where('date', $repayment->due_date)
            ->where('amount', $schedulePayment['interest'])
            ->where('transaction_type', 'Mature Interest')
            ->exists();

        Log::info("Interest accounts for product {$loan->product->id}", [
            'exists' => $exists,
        ]);

        if (!$incomeId) {
            Log::warning("Missing interest income account for product {$loan->product->id}");
            return 0;
        }
        Log::info('income account', [$incomeId]);

        $incomeExists = GlTransaction::where('chart_account_id', $incomeId)
            ->where('customer_id', $loan->customer_id)
            ->where('date', $repayment->due_date)
            ->where('amount', $schedulePayment['interest'])
            ->where('transaction_type', 'Mature Interest')
            ->exists();

        Log::info("Interest accounts for product {$loan->product->id}", [
            'exists' => $incomeExists,
        ]);


        if ($exists && $incomeExists) {
            Log::info('Interest receivable and interest income have been posted ovewtite the array chartAccont interest to be receivable instead of icome');
            $chartAccounts['interest'] = $receivableId;
        }

        foreach ($components as $component => $amount) {
            if ($amount > 0 && !empty($chartAccounts[$component])) {
                JournalItem::create([
                    'journal_id' => $journal->id,
                    'chart_account_id' => $chartAccounts[$component],
                    'amount' => $amount,
                    'description' => ucfirst($component) . " repayment for loan #{$loan->id}",
                    'nature' => 'credit',
                ]);
                GlTransaction::create([
                    'chart_account_id' => $chartAccounts[$component],
                    'customer_id' => $loan->customer_id,
                    'amount' => $amount,
                    'nature' => 'credit',
                    'transaction_id' => $journal->id,
                    'transaction_type' => 'journal repayment',
                    'date' => $journal->date,
                    'description' => ucfirst($component) . " repayment from cash deposit - Loan #{$loan->id}",
                    'branch_id' => $journal->branch_id,
                    'user_id' => $journal->user_id,
                ]);
            }
        }

        // Debit: Cash collateral account (total amount)
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'amount' => $schedulePayment['amount'],
            'description' => "Loan repayment from cash deposit",
            'nature' => 'debit',
        ]);
        GlTransaction::create([
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'customer_id' => $loan->customer_id,
            'amount' => $schedulePayment['amount'],
            'nature' => 'debit',
            'transaction_id' => $journal->id,
            'transaction_type' => 'journal repayment',
            'date' => $journal->date,
            'description' => "Loan repayment from cash deposit - Loan #{$loan->id}",
            'branch_id' => $journal->branch_id,
            'user_id' => $journal->user_id,
        ]);
    }

    /**
     * Get chart accounts for loan components
     */
    private function getChartAccounts($loan)
    {
        // Use chart accounts from loan product
        $chartAccounts = [];

        if ($loan->product) {
            $chartAccounts = [
                'principal' => $loan->product->principal_receivable_account_id,
                'interest' => $loan->product->interest_receivable_account_id,
                'fee_amount' => $loan->product->fee ? $loan->product->fee->chart_account_id : null, // Use interest account for fees
                'penalty_amount' => $loan->product->penalty ? $loan->product->penalty->penalty_receivables_account_id : null // Use interest account for penalties
            ];
        }

        return $chartAccounts;
    }

    /**
     * Check if loan is fully paid using the same logic as closeLoan method
     */
    private function isLoanFullyPaid($loan)
    {
        // Use the same logic as the Loan model's isEligibleForClosing method
        return $loan->isEligibleForClosing();
    }

    /**
     * Remove penalty from schedule (for pardon functionality)
     */
    public function removePenalty($scheduleId, $reason = null, $amount = null, $loanId = null)
    {
        DB::beginTransaction();

        try {
            $schedule = LoanSchedule::with(['repayments', 'loan.product'])->findOrFail($scheduleId);
            $loan = $schedule->loan ?? ($loanId ? Loan::with('product')->find($loanId) : null);

            $currentPenaltyAmount = (float) $schedule->penalty_amount;
            $removeAmount = round((float) $amount, 2);

            if ($removeAmount <= 0) {
                throw new \Exception('Removal amount must be greater than zero.');
            }
            if ($removeAmount > $currentPenaltyAmount + 0.01) {
                throw new \Exception('Removal amount cannot exceed the current penalty on this schedule.');
            }

            $penaltyPaidAmount = $schedule->repayments
                ? (float) $schedule->repayments->sum('penalt_amount')
                : 0;

            if ($penaltyPaidAmount > 0) {
                throw new \Exception(
                    "Penalty removal not allowed. TZS " . number_format($penaltyPaidAmount, 2) . ' of penalty on this installment has already been paid.'
                );
            }

            $reversedAccrued = $this->reverseAccruedPenaltiesForSchedule($schedule, $removeAmount, $reason);
            $remainingToReverse = max(0, $removeAmount - $reversedAccrued);
            $reversedLegacy = $this->reverseLegacyPenaltyGlForSchedule($schedule, $remainingToReverse);
            $totalGlReversed = round($reversedAccrued + $reversedLegacy, 2);

            if ($totalGlReversed > 0 && $totalGlReversed + 0.02 < $removeAmount) {
                throw new \Exception(
                    'Penalty could not be fully reversed in GL (reversed '
                    . number_format($totalGlReversed, 2) . ' of ' . number_format($removeAmount, 2)
                    . '). Schedule was not changed to avoid mismatched data.'
                );
            }

            $newPenaltyAmount = max(0, round($currentPenaltyAmount - $removeAmount, 2));
            $schedule->update(['penalty_amount' => $newPenaltyAmount]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Penalty removed successfully. Schedule, accrued penalty records, and GL have been updated.',
                'reversed_accrued' => $reversedAccrued,
                'reversed_legacy_gl' => $reversedLegacy,
                'new_penalty_amount' => $newPenaltyAmount,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to remove penalty for schedule ID: {$scheduleId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Waive accrued / unpaid interest on a schedule and reduce loan interest totals.
     */
    public function waiveAccruedInterest($scheduleId, $reason = null, $amount = null, $loanId = null)
    {
        DB::beginTransaction();

        try {
            $schedule = LoanSchedule::with(['repayments', 'loan.product'])->findOrFail($scheduleId);
            $loan = $schedule->loan ?? ($loanId ? Loan::with('product')->find($loanId) : null);

            if (! $loan) {
                throw new \Exception('Loan not found for this schedule.');
            }

            if (! in_array($loan->status, [Loan::STATUS_ACTIVE, Loan::STATUS_DEFAULTED], true)) {
                throw new \Exception('Accrued interest can only be waived on active loans.');
            }

            if (in_array($schedule->status, ['paid', 'cancelled', 'restructured'], true)) {
                throw new \Exception('Interest cannot be waived on this schedule item.');
            }

            $interestPaid = (float) $schedule->repayments->sum('interest');
            $interestDue = (float) $schedule->balance_interest_component;
            $waivable = max(0, round($interestDue - $interestPaid, 2));

            $waiveAmount = $amount !== null ? round((float) $amount, 2) : $waivable;

            if ($waiveAmount <= 0) {
                throw new \Exception('Waiver amount must be greater than zero.');
            }
            if ($waiveAmount > $waivable + 0.01) {
                throw new \Exception('Waiver amount cannot exceed unpaid accrued interest on this schedule.');
            }

            $newInterestDue = max($interestPaid, round($interestDue - $waiveAmount, 2));

            if ($loan->usesDailyInterestAccrual()) {
                $schedule->update(['accrued_interest' => $newInterestDue]);
            } else {
                $newScheduled = max($interestPaid, round((float) $schedule->interest - $waiveAmount, 2));
                $newAccrued = min((float) ($schedule->accrued_interest ?? 0), $newInterestDue);
                if ($newAccrued > $newScheduled) {
                    $newAccrued = $newScheduled;
                }

                $schedule->update([
                    'interest' => $newScheduled,
                    'accrued_interest' => $newAccrued,
                ]);
            }

            $loan->interest_amount = max(0, round((float) $loan->interest_amount - $waiveAmount, 2));
            $loan->amount_total = max((float) $loan->amount, round((float) $loan->amount_total - $waiveAmount, 2));
            $loan->save();

            Log::info("Waived accrued interest for schedule ID: {$scheduleId}", [
                'loan_id' => $loan->id,
                'waive_amount' => $waiveAmount,
                'new_interest_due' => $newInterestDue,
                'reason' => $reason,
                'user_id' => auth()->id(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Accrued interest waived successfully. Schedule and loan totals have been updated.',
                'waived_amount' => $waiveAmount,
                'new_interest_due' => $newInterestDue,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to waive accrued interest for schedule ID: {$scheduleId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Reverse Accrued Penalty rows (and their GL) for a schedule, newest first.
     */
    private function reverseAccruedPenaltiesForSchedule(LoanSchedule $schedule, float $amountToReverse, ?string $reason): float
    {
        $remaining = $amountToReverse;
        $reversedTotal = 0.0;

        $accruedRows = AccruedPenalty::where('loan_schedule_id', $schedule->id)
            ->whereNull('reversed_at')
            ->orderByDesc('accrual_date')
            ->orderByDesc('id')
            ->get();

        foreach ($accruedRows as $accrued) {
            if ($remaining <= 0) {
                break;
            }

            $rowAmount = (float) $accrued->penalty_amount;
            if ($rowAmount <= 0) {
                continue;
            }

            if ($rowAmount > $remaining + 0.01) {
                Log::warning('Partial accrued penalty reversal skipped — reverse full accrual rows only', [
                    'accrued_penalty_id' => $accrued->id,
                    'row_amount' => $rowAmount,
                    'remaining' => $remaining,
                ]);
                break;
            }

            $this->reverseSingleAccruedPenalty($accrued, $reason);
            $remaining -= $rowAmount;
            $reversedTotal += $rowAmount;
        }

        return $reversedTotal;
    }

    private function reverseSingleAccruedPenalty(AccruedPenalty $accruedPenalty, ?string $reason): void
    {
        $originals = GlTransaction::where('transaction_id', $accruedPenalty->id)
            ->where('transaction_type', 'Accrued Penalty')
            ->get();

        foreach ($originals as $gl) {
            GlTransaction::create([
                'chart_account_id' => $gl->chart_account_id,
                'customer_id' => $gl->customer_id,
                'supplier_id' => $gl->supplier_id,
                'amount' => $gl->amount,
                'nature' => $gl->nature === 'debit' ? 'credit' : 'debit',
                'transaction_id' => $accruedPenalty->id,
                'transaction_type' => 'Accrued Penalty Reversal',
                'date' => now(),
                'description' => trim(($gl->description ?? '') . ' (Reversal' . ($reason ? ": {$reason}" : '') . ')'),
                'branch_id' => $gl->branch_id,
                'user_id' => auth()->id() ?? 1,
            ]);
        }

        if ($accruedPenalty->journal_id) {
            $this->reversePenaltyAccrualJournal($accruedPenalty, $reason);
        }

        $accruedPenalty->update(['reversed_at' => now()]);
    }

    /**
     * Mirror reversal in journal for an accrued penalty row.
     */
    private function reversePenaltyAccrualJournal(AccruedPenalty $accruedPenalty, ?string $reason): void
    {
        $items = JournalItem::where('journal_id', $accruedPenalty->journal_id)->get();
        if ($items->isEmpty()) {
            return;
        }

        $reversalJournal = Journal::create([
            'date' => now(),
            'reference' => 'PEN-REV-' . $accruedPenalty->id,
            'reference_type' => 'Penalty Accrual Reversal',
            'customer_id' => $accruedPenalty->customer_id,
            'description' => 'Penalty accrual reversal' . ($reason ? ": {$reason}" : ''),
            'branch_id' => $accruedPenalty->branch_id,
            'user_id' => auth()->id() ?? 1,
            'approved' => true,
            'approved_by' => auth()->id() ?? 1,
            'approved_at' => now(),
        ]);

        foreach ($items as $item) {
            JournalItem::create([
                'journal_id' => $reversalJournal->id,
                'chart_account_id' => $item->chart_account_id,
                'amount' => $item->amount,
                'description' => ($item->description ?? 'Penalty reversal') . ' (Reversal)',
                'nature' => $item->nature === 'debit' ? 'credit' : 'debit',
            ]);
        }

        $accruedPenalty->update(['reversal_journal_id' => $reversalJournal->id]);
    }

    /**
     * Reverse legacy penalty GL posted with transaction_id = schedule_id (CollectMatureInterestJob).
     */
    private function reverseLegacyPenaltyGlForSchedule(LoanSchedule $schedule, float $amountToReverse): float
    {
        if ($amountToReverse <= 0) {
            return 0.0;
        }

        $remaining = $amountToReverse;
        $reversedTotal = 0.0;

        $debits = GlTransaction::where('transaction_id', $schedule->id)
            ->where('transaction_type', 'Penalty')
            ->where('nature', 'debit')
            ->orderByDesc('id')
            ->get();

        foreach ($debits as $debit) {
            if ($remaining <= 0) {
                break;
            }

            $rowAmount = (float) $debit->amount;
            if ($rowAmount <= 0) {
                continue;
            }

            if ($rowAmount > $remaining + 0.01) {
                break;
            }

            $credits = GlTransaction::where('transaction_id', $schedule->id)
                ->where('transaction_type', 'Penalty')
                ->where('nature', 'credit')
                ->where('amount', $rowAmount)
                ->get();

            foreach ($credits as $credit) {
                GlTransaction::create([
                    'chart_account_id' => $credit->chart_account_id,
                    'customer_id' => $credit->customer_id,
                    'amount' => $credit->amount,
                    'nature' => 'debit',
                    'transaction_id' => $schedule->id,
                    'transaction_type' => 'Penalty Reversal',
                    'date' => now(),
                    'description' => ($credit->description ?? 'Penalty reversal') . ' (Reversal)',
                    'branch_id' => $credit->branch_id,
                    'user_id' => auth()->id() ?? 1,
                ]);
            }

            GlTransaction::create([
                'chart_account_id' => $debit->chart_account_id,
                'customer_id' => $debit->customer_id,
                'amount' => $debit->amount,
                'nature' => 'credit',
                'transaction_id' => $schedule->id,
                'transaction_type' => 'Penalty Reversal',
                'date' => now(),
                'description' => ($debit->description ?? 'Penalty reversal') . ' (Reversal)',
                'branch_id' => $debit->branch_id,
                'user_id' => auth()->id() ?? 1,
            ]);

            $remaining -= $rowAmount;
            $reversedTotal += $rowAmount;
        }

        return $reversedTotal;
    }

    /**
     * Check if payment is made before or on due date and remove penalties if applicable
     */
    private function checkAndRemovePenaltyForOnTimePayment($schedule, $paymentData)
    {
        try {
            // Get the payment date (use provided date or current date)
            $paymentDate = isset($paymentData['payment_date'])
                ? Carbon::parse($paymentData['payment_date'])
                : Carbon::today();

            // Get the schedule due date
            $dueDate = Carbon::parse($schedule->due_date);

            // Check if payment is made before or on the due date
            if ($paymentDate->lte($dueDate) && $schedule->penalty_amount > 0) {
                Log::info("Payment made on/before due date. Removing penalty for schedule {$schedule->id}", [
                    'schedule_id' => $schedule->id,
                    'payment_date' => $paymentDate->format('Y-m-d'),
                    'due_date' => $dueDate->format('Y-m-d'),
                    'penalty_amount' => $schedule->penalty_amount,
                    'customer_id' => $schedule->customer_id
                ]);


                // Remove penalty from schedule and GL transactions with all required parameters
                $this->removePenalty(
                    $schedule->id,
                    'Paid earlier or on due date',
                    $schedule->penalty_amount,
                    $schedule->loan_id
                );

                // Refresh the schedule model to get updated penalty_amount
                $schedule->refresh();

                Log::info("Penalty successfully removed for on-time payment on schedule {$schedule->id}");
            }
        } catch (\Exception $e) {
            // Log the error but don't stop the payment process
            Log::error("Failed to check/remove penalty for on-time payment on schedule {$schedule->id}", [
                'error' => $e->getMessage(),
                'schedule_id' => $schedule->id
            ]);
        }
    }

    /**
     * Calculate loan schedule using different methods
     */
    public function calculateSchedule($loan, $method = 'flat_rate')
    {
        switch ($method) {
            case 'flat_rate':
                return $this->calculateFlatRateSchedule($loan);
            case 'reducing_equal_installment':
                return $this->calculateReducingEqualInstallmentSchedule($loan);
            case 'reducing_equal_principal':
                return $this->calculateReducingEqualPrincipalSchedule($loan);
            default:
                throw new \Exception('Invalid calculation method');
        }
    }

    /**
     * Calculate flat rate schedule
     */
    private function calculateFlatRateSchedule($loan)
    {
        $principal = $loan->amount;
        $interestRate = $loan->interest / 100;
        $period = $loan->period;

        // Flat rate calculation
        $totalInterest = $principal * $interestRate * $period;
        $totalAmount = $principal + $totalInterest;
        $monthlyInstallment = $totalAmount / $period;
        $monthlyInterest = $totalInterest / $period;
        $monthlyPrincipal = $principal / $period;

        $schedules = [];
        $currentDate = Carbon::parse($loan->disbursed_on)->addMonth();

        for ($i = 1; $i <= $period; $i++) {
            $schedules[] = [
                'installment_no' => $i,
                'due_date' => $currentDate->format('Y-m-d'),
                'principal' => $monthlyPrincipal,
                'interest' => $monthlyInterest,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'total_installment' => $monthlyInstallment
            ];

            $currentDate->addMonth();
        }

        return $schedules;
    }

    /**
     * Calculate reducing balance with equal installments
     */
    private function calculateReducingEqualInstallmentSchedule($loan)
    {
        $principal = $loan->amount;
        $interestRate = $loan->interest / 100 / 12; // Monthly rate
        $period = $loan->period;

        // Calculate equal monthly installment
        $monthlyInstallment = $principal * ($interestRate * pow(1 + $interestRate, $period)) / (pow(1 + $interestRate, $period) - 1);

        $schedules = [];
        $currentDate = Carbon::parse($loan->disbursed_on)->addMonth();
        $remainingPrincipal = $principal;

        for ($i = 1; $i <= $period; $i++) {
            $monthlyInterest = $remainingPrincipal * $interestRate;
            $monthlyPrincipal = $monthlyInstallment - $monthlyInterest;

            // Adjust for last payment
            if ($i == $period) {
                $monthlyPrincipal = $remainingPrincipal;
                $monthlyInstallment = $monthlyPrincipal + $monthlyInterest;
            }

            $schedules[] = [
                'installment_no' => $i,
                'due_date' => $currentDate->format('Y-m-d'),
                'principal' => $monthlyPrincipal,
                'interest' => $monthlyInterest,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'total_installment' => $monthlyInstallment
            ];

            $remainingPrincipal -= $monthlyPrincipal;
            $currentDate->addMonth();
        }

        return $schedules;
    }

    /**
     * Calculate reducing balance with equal principal
     */
    private function calculateReducingEqualPrincipalSchedule($loan)
    {
        $principal = $loan->amount;
        $interestRate = $loan->interest / 100 / 12; // Monthly rate
        $period = $loan->period;

        $monthlyPrincipal = $principal / $period;

        $schedules = [];
        $currentDate = Carbon::parse($loan->disbursed_on)->addMonth();
        $remainingPrincipal = $principal;

        for ($i = 1; $i <= $period; $i++) {
            $monthlyInterest = $remainingPrincipal * $interestRate;
            $monthlyInstallment = $monthlyPrincipal + $monthlyInterest;

            $schedules[] = [
                'installment_no' => $i,
                'due_date' => $currentDate->format('Y-m-d'),
                'principal' => $monthlyPrincipal,
                'interest' => $monthlyInterest,
                'fee_amount' => 0,
                'penalty_amount' => 0,
                'total_installment' => $monthlyInstallment
            ];

            $remainingPrincipal -= $monthlyPrincipal;
            $currentDate->addMonth();
        }

        return $schedules;
    }

    /**
     * Create journal entries for cash deposit payments
     * DR: Cash Deposit Account (reducing balance)
     * CR: Principal/Interest/Penalty/Fee Accounts
     */
    private function createCashDepositJournalEntries($payment, $loan, $schedulePayment, $repayment, $cashDeposit)
    {
        // Get chart accounts from loan product or use defaults
        $principalAccount = ChartAccount::find($loan->product->principal_gl_account_id ?? 1);
        $interestAccount = ChartAccount::find($loan->product->interest_gl_account_id ?? 2);
        $penaltyAccount = ChartAccount::find($loan->product->penalty_gl_account_id ?? 3);
        $feeAccount = ChartAccount::find($loan->product->fee_gl_account_id ?? 4);
        $cashDepositAccount = ChartAccount::find($cashDeposit->type->chart_account_id ?? 5);

        $journalRef = 'LOAN-REPAY-CD-' . $loan->id . '-' . time();

        // Create payment items for tracking
        if ($schedulePayment['principal'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $principalAccount->id,
                'description' => 'Principal payment from cash deposit',
                'amount' => $schedulePayment['principal'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['principal'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for principal payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Principal Account (loan repayment)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $principalAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['principal'],
                'description' => "Principal payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['interest'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $interestAccount->id,
                'description' => 'Interest payment from cash deposit',
                'amount' => $schedulePayment['interest'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['interest'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for interest payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Interest Account (interest income)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $interestAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['interest'],
                'description' => "Interest payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['penalty'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $penaltyAccount->id,
                'description' => 'Penalty payment from cash deposit',
                'amount' => $schedulePayment['penalty'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['penalty'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for penalty payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Penalty Account (penalty income)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $penaltyAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['penalty'],
                'description' => "Penalty payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['fee'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $feeAccount->id,
                'description' => 'Fee payment from cash deposit',
                'amount' => $schedulePayment['fee'],
            ]);

            // DR: Cash Deposit Account (reducing the deposit)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => $schedulePayment['fee'],
                'credit' => 0,
                'description' => "Cash deposit withdrawal for fee payment - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            // CR: Fee Account (fee income)
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $feeAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['fee'],
                'description' => "Fee payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['interest'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $interestAccount->id,
                'description' => 'Interest payment from cash deposit',
                'amount' => $schedulePayment['interest'],
            ]);

            // DR: Interest Account, CR: Cash Deposit Account
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $interestAccount->id,
                'debit' => $schedulePayment['interest'],
                'credit' => 0,
                'description' => "Interest payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['interest'],
                'description' => "Interest payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['penalty'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $penaltyAccount->id,
                'description' => 'Penalty payment from cash deposit',
                'amount' => $schedulePayment['penalty'],
            ]);

            // DR: Penalty Account, CR: Cash Deposit Account
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $penaltyAccount->id,
                'debit' => $schedulePayment['penalty'],
                'credit' => 0,
                'description' => "Penalty payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['penalty'],
                'description' => "Penalty payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }

        if ($schedulePayment['fee'] > 0) {
            \App\Models\PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $feeAccount->id,
                'description' => 'Fee payment from cash deposit',
                'amount' => $schedulePayment['fee'],
            ]);

            // DR: Fee Account, CR: Cash Deposit Account
            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $feeAccount->id,
                'debit' => $schedulePayment['fee'],
                'credit' => 0,
                'description' => "Fee payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);

            GlTransaction::create([
                'reference' => $journalRef,
                'reference_type' => 'loan_repayment',
                'chart_account_id' => $cashDepositAccount->id,
                'debit' => 0,
                'credit' => $schedulePayment['fee'],
                'description' => "Fee payment from cash deposit - Loan #{$loan->id}",
                'transaction_date' => $payment->date,
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()->branch_id ?? 1,
            ]);
        }
    }

    /**
     * Process settle repayment - pays current interest and all remaining principal
     *
     * @param int $loanId The loan ID
     * @param float $amount The settle amount to be paid
     * @param array $paymentData Payment data including bank account, payment date, etc.
     * @return array Result of the settlement
     */
    public function processSettleRepayment($loanId, float $amount, array $paymentData): array
    {
        DB::beginTransaction();

        try {
            $loan = Loan::with(['product', 'customer', 'schedule.repayments'])->findOrFail($loanId);

            Log::info('Processing settle repayment', [
                'loan_id' => $loanId,
                'loan_status' => $loan->status,
                'schedule_count' => $loan->schedule ? $loan->schedule->count() : 0,
                'amount' => $amount
            ]);

            $settlementPlan = $loan->buildSettlementPlan($paymentData['payment_date'] ?? null);
            $expectedSettleAmount = round((float) ($settlementPlan['settle_amount'] ?? 0), 2);

            if ($expectedSettleAmount <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
                throw new \Exception('Loan has no outstanding settlement balance.');
            }

            if (abs($amount - $expectedSettleAmount) > 0.01) {
                throw new \Exception("Settle amount mismatch. Expected: {$expectedSettleAmount}, Provided: {$amount}");
            }

            // Handle cash deposit balance reduction if using cash deposit
            if (isset($paymentData['payment_source']) && $paymentData['payment_source'] === 'cash_deposit') {
                $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);
                $cashDeposit->decrement('amount', $amount);
                Log::info('Cash collateral decremented for settle repayment', [
                    'cash_deposit_id' => $cashDeposit->id,
                    'amount_decremented' => $amount,
                    'remaining_balance' => $cashDeposit->amount,
                ]);
            }

            $processedSchedules = [];
            $totalInterestPaid = 0.0;
            $totalPrincipalPaid = 0.0;
            $totalFeesPaid = 0.0;
            $totalPenaltyPaid = 0.0;

            $plannedPayments = array_merge(
                $settlementPlan['due_schedule_payments'] ?? [],
                $settlementPlan['future_principal_payments'] ?? []
            );

            foreach ($plannedPayments as $plannedPayment) {
                if (($plannedPayment['amount'] ?? 0) <= 0) {
                    continue;
                }

                /** @var LoanSchedule $schedule */
                $schedule = $plannedPayment['schedule'];
                if (!$schedule->relationLoaded('loan')) {
                    $schedule->setRelation('loan', $loan);
                }

                $repayment = $this->createRepaymentRecord($loan, $schedule, $plannedPayment, $paymentData);
                $this->createSettleRepaymentGL($loan, $repayment, $plannedPayment, $paymentData);

                if ($schedule->relationLoaded('repayments')) {
                    $schedule->setRelation('repayments', $schedule->repayments->push($repayment));
                }

                $this->markSchedulePaidIfSettled($schedule);

                $totalPrincipalPaid += (float) ($plannedPayment['principal'] ?? 0);
                $totalInterestPaid += (float) ($plannedPayment['interest'] ?? 0);
                $totalFeesPaid += (float) ($plannedPayment['fee_amount'] ?? 0);
                $totalPenaltyPaid += (float) ($plannedPayment['penalty_amount'] ?? 0);
                $processedSchedules[] = [
                    'schedule_id' => $schedule->id,
                    'principal_paid' => (float) ($plannedPayment['principal'] ?? 0),
                    'interest_paid' => (float) ($plannedPayment['interest'] ?? 0),
                    'fee_paid' => (float) ($plannedPayment['fee_amount'] ?? 0),
                    'penalty_paid' => (float) ($plannedPayment['penalty_amount'] ?? 0),
                ];
            }

            $loan->refresh();
            $loan->load(['product', 'customer', 'schedule.repayments']);

            // Check if loan should be closed
            $shouldClose = $loan->isEligibleForClosing();
            if ($shouldClose) {
                $loan->status = Loan::STATUS_COMPLETE;
                $loan->save();
            }

            DB::commit();

            // Refresh loan to get updated outstanding balance
            $loan->refresh();
            $loan->load(['schedule', 'customer', 'company', 'branch.company']);

            // Send SMS notification to customer after successful settlement
            $this->sendRepaymentSms($loan, $amount);

            return [
                'success' => true,
                'message' => 'Loan settled successfully',
                'current_interest_paid' => round($totalInterestPaid, 2),
                'total_principal_paid' => round($totalPrincipalPaid, 2),
                'total_fees_paid' => round($totalFeesPaid, 2),
                'total_penalties_paid' => round($totalPenaltyPaid, 2),
                'processed_schedules' => $processedSchedules,
                'loan_closed' => $shouldClose
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Settle repayment failed', [
                'loan_id' => $loanId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function createSettleRepaymentGL(Loan $loan, Repayment $repayment, array $schedulePayment, array $paymentData): void
    {
        $totalAmount = round((float) ($schedulePayment['amount'] ?? 0), 2);
        if ($totalAmount <= 0) {
            return;
        }

        $debitAccountId = $paymentData['bank_chart_account_id'] ?? null;
        if (($paymentData['payment_source'] ?? null) === 'cash_deposit') {
            $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);
            $debitAccountId = $cashDeposit->type->chart_account_id ?? null;
        }

        if ($debitAccountId) {
            GlTransaction::create([
                'chart_account_id' => $debitAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $totalAmount,
                'nature' => 'debit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Repayment',
                'date' => $repayment->payment_date,
                'description' => "Settle repayment for loan {$loan->loanNo} - schedule {$repayment->loan_schedule_id}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }

        $creditAccounts = $this->resolveSettleCreditAccounts($loan);
        foreach (['principal', 'interest', 'fee_amount', 'penalty_amount'] as $component) {
            $componentAmount = round((float) ($schedulePayment[$component] ?? 0), 2);
            $accountId = $creditAccounts[$component] ?? null;

            if ($componentAmount <= 0 || !$accountId) {
                continue;
            }

            GlTransaction::create([
                'chart_account_id' => $accountId,
                'customer_id' => $loan->customer_id,
                'amount' => $componentAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Repayment',
                'date' => $repayment->payment_date,
                'description' => ucfirst(str_replace('_', ' ', $component)) . " settled for loan {$loan->loanNo} - schedule {$repayment->loan_schedule_id}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    private function resolveSettleCreditAccounts(Loan $loan): array
    {
        $feeAccountId = null;
        if (isset($loan->product->fees_ids)) {
            $feeIds = is_array($loan->product->fees_ids)
                ? $loan->product->fees_ids
                : json_decode($loan->product->fees_ids, true);

            if (is_array($feeIds)) {
                foreach ($feeIds as $feeId) {
                    $fee = \DB::table('fees')->where('id', $feeId)->first();
                    if ($fee && $fee->include_in_schedule == 1 && $fee->chart_account_id) {
                        $feeAccountId = $fee->chart_account_id;
                        break;
                    }
                }
            }
        }

        $penaltyAccountId = null;
        if (isset($loan->product->penalty_ids)) {
            $penaltyIds = is_array($loan->product->penalty_ids)
                ? $loan->product->penalty_ids
                : json_decode($loan->product->penalty_ids, true);

            if (is_array($penaltyIds)) {
                foreach ($penaltyIds as $penaltyId) {
                    $penalty = \DB::table('penalties')->where('id', $penaltyId)->first();
                    if ($penalty && $penalty->penalty_receivables_account_id) {
                        $penaltyAccountId = $penalty->penalty_receivables_account_id;
                        break;
                    }
                }
            }
        }

        return [
            'principal' => $loan->product->principal_receivable_account_id ?? null,
            'interest' => $loan->product->interest_receivable_account_id ?? $loan->product->interest_revenue_account_id ?? null,
            'fee_amount' => $feeAccountId,
            'penalty_amount' => $penaltyAccountId,
        ];
    }

    /**
     * Create GL transactions for settle interest payment
     */
    private function createSettleInterestGL(Loan $loan, Repayment $repayment, float $interestAmount, array $paymentData)
    {
        // Debit: Bank/Cash account
        GlTransaction::create([
            'chart_account_id' => $paymentData['bank_chart_account_id'],
            'customer_id' => $loan->customer_id,
            'amount' => $interestAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Interest',
            'date' => $repayment->payment_date,
            'description' => "Settle interest payment for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Interest receivable or revenue account
        $interestAccountId = $loan->product->interest_receivable_account_id ?? $loan->product->interest_revenue_account_id;
        if ($interestAccountId) {
            GlTransaction::create([
                'chart_account_id' => $interestAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $interestAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Interest',
                'date' => $repayment->payment_date,
                'description' => "Settle interest payment for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle principal payment
     */
    private function createSettlePrincipalGL(Loan $loan, Repayment $repayment, float $principalAmount, array $paymentData)
    {
        // Debit: Bank/Cash account
        GlTransaction::create([
            'chart_account_id' => $paymentData['bank_chart_account_id'],
            'customer_id' => $loan->customer_id,
            'amount' => $principalAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Principal',
            'date' => $repayment->payment_date,
            'description' => "Settle principal payment for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Principal receivable account
        $principalAccountId = $loan->product->principal_receivable_account_id;
        if ($principalAccountId) {
            GlTransaction::create([
                'chart_account_id' => $principalAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $principalAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Principal',
                'date' => $repayment->payment_date,
                'description' => "Settle principal payment for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle interest payment from cash deposit
     */
    private function createSettleInterestGLFromCashDeposit(Loan $loan, Repayment $repayment, float $interestAmount, array $paymentData)
    {
        // Get cash deposit account
        $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);

        // Debit: Cash collateral account (reducing the deposit)
        GlTransaction::create([
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'customer_id' => $loan->customer_id,
            'amount' => $interestAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Interest',
            'date' => $repayment->payment_date,
            'description' => "Settle interest payment from cash deposit for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Interest receivable or revenue account
        $interestAccountId = $loan->product->interest_receivable_account_id ?? $loan->product->interest_revenue_account_id;
        if ($interestAccountId) {
            GlTransaction::create([
                'chart_account_id' => $interestAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $interestAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Interest',
                'date' => $repayment->payment_date,
                'description' => "Settle interest payment from cash deposit for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle principal payment from cash deposit
     */
    private function createSettlePrincipalGLFromCashDeposit(Loan $loan, Repayment $repayment, float $principalAmount, array $paymentData)
    {
        // Get cash deposit account
        $cashDeposit = \App\Models\CashCollateral::findOrFail($paymentData['cash_deposit_id']);

        // Debit: Cash collateral account (reducing the deposit)
        GlTransaction::create([
            'chart_account_id' => $cashDeposit->type->chart_account_id ?? 1,
            'customer_id' => $loan->customer_id,
            'amount' => $principalAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Principal',
            'date' => $repayment->payment_date,
            'description' => "Settle principal payment from cash deposit for loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Principal receivable account
        $principalAccountId = $loan->product->principal_receivable_account_id;
        if ($principalAccountId) {
            GlTransaction::create([
                'chart_account_id' => $principalAccountId,
                'customer_id' => $loan->customer_id,
                'amount' => $principalAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Principal',
                'date' => $repayment->payment_date,
                'description' => "Settle principal payment from cash deposit for loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Remove all repayments for a loan (including soft-deleted) with receipts, journals, and GL entries.
     * Used when deleting a loan — does not restore cash deposits.
     */
    public function deleteAllRepaymentsForLoan(int $loanId): void
    {
        $repaymentIds = Repayment::withTrashed()
            ->where('loan_id', $loanId)
            ->pluck('id')
            ->all();

        $receiptIds = Repayment::withTrashed()
            ->where('loan_id', $loanId)
            ->whereNotNull('receipt_id')
            ->pluck('receipt_id')
            ->unique()
            ->values()
            ->all();

        $loanReceiptIds = Receipt::withTrashed()
            ->where('reference', $loanId)
            ->whereIn('reference_type', ['loan_repayment', 'Repayment', 'loan'])
            ->pluck('id')
            ->all();

        if (!empty($repaymentIds)) {
            $repaymentReceiptIds = Receipt::withTrashed()
                ->whereIn('reference', $repaymentIds)
                ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                ->pluck('id')
                ->all();
            $receiptIds = array_values(array_unique(array_merge($receiptIds, $loanReceiptIds, $repaymentReceiptIds)));
        } else {
            $receiptIds = array_values(array_unique(array_merge($receiptIds, $loanReceiptIds)));
        }

        foreach ($receiptIds as $receiptId) {
            $receipt = Receipt::withTrashed()->find($receiptId);
            if (!$receipt) {
                continue;
            }

            GlTransaction::where('transaction_id', $receipt->id)
                ->whereIn('transaction_type', ['receipt', 'receipt_reversal'])
                ->delete();

            ReceiptItem::where('receipt_id', $receipt->id)->delete();

            Repayment::withTrashed()->where('receipt_id', $receipt->id)->forceDelete();

            $receipt->forceDelete();
        }

        if (!empty($repaymentIds)) {
            $journalIds = Journal::whereIn('reference', $repaymentIds)
                ->where('reference_type', 'Withdrawal')
                ->pluck('id')
                ->all();

            if (!empty($journalIds)) {
                JournalItem::whereIn('journal_id', $journalIds)->delete();
                GlTransaction::whereIn('transaction_id', $journalIds)
                    ->where('transaction_type', 'journal repayment')
                    ->delete();
                Journal::whereIn('id', $journalIds)->delete();
            }

            GlTransaction::whereIn('transaction_id', $repaymentIds)
                ->whereIn('transaction_type', ['receipt', 'journal repayment', 'Settle Interest', 'Settle Principal'])
                ->delete();

            Repayment::withTrashed()->where('loan_id', $loanId)->forceDelete();
        }

        Log::info('All repayments removed for loan', [
            'loan_id' => $loanId,
            'repayment_count' => count($repaymentIds),
            'receipt_count' => count($receiptIds),
        ]);
    }

    /**
     * Delete repayment and all associated records
     * This method deletes all related data created during repayment processing
     */
    public function deleteRepayment($repaymentId)
    {
        DB::beginTransaction();

        try {
            $repayment = Repayment::with(['loan', 'schedule'])->findOrFail($repaymentId);
            $loan = $repayment->loan;
            $originalLoanStatus = $loan->status;

            $this->assertCanDeleteRepayment($repayment);

            $receiptIdForRebuild = $repayment->receipt_id;
            $hasSharedReceipt = $receiptIdForRebuild
                && Repayment::where('receipt_id', $receiptIdForRebuild)
                    ->where('id', '!=', $repayment->id)
                    ->exists();

            Log::info('Starting comprehensive repayment deletion', [
                'repayment_id' => $repayment->id,
                'loan_id' => $loan->id,
                'customer_id' => $repayment->customer_id
            ]);

            // 1. Find and delete Receipt and related records
            $this->deleteRepaymentReceipt($repayment);

            // 2. Find and delete Journal and related records
            $this->deleteRepaymentJournal($repayment);

            // 3. Delete all GL transactions related to this repayment
            $this->deleteRepaymentGLTransactions($repayment);

            // 4. Restore cash deposit if applicable
            $this->restoreCashDepositForRepayment($repayment);

            // 5. Update loan status if it was closed due to this repayment
            $this->updateLoanStatusAfterDeletion($loan, $originalLoanStatus);

            // 6. Delete the repayment record
            $scheduleId = $repayment->loan_schedule_id;
            $repayment->delete();

            if ($scheduleId) {
                $this->syncSchedulePaymentStatusForScheduleIds([$scheduleId]);
            }

            if ($hasSharedReceipt && $receiptIdForRebuild) {
                $receipt = Receipt::find($receiptIdForRebuild);
                if ($receipt) {
                    $this->rebuildReceiptGlFromRepayments($receipt, $loan);
                }
            }

            DB::commit();

            Log::info('Repayment deletion completed successfully', [
                'repayment_id' => $repaymentId,
                'loan_id' => $loan->id
            ]);

            return [
                'success' => true,
                'message' => 'Repayment and all associated records deleted successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repayment deletion failed', [
                'repayment_id' => $repaymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Total amount recorded on a single repayment row.
     */
    private function repaymentTotal(Repayment $repayment): float
    {
        return round(
            (float) $repayment->principal
            + (float) $repayment->interest
            + (float) $repayment->fee_amount
            + (float) $repayment->penalt_amount,
            2
        );
    }

    private function isLoanFeeReceipt(Receipt $receipt): bool
    {
        return $receipt->reference_type === 'loan' && !empty($receipt->reference);
    }

    private function isLoanRepaymentReceipt(Receipt $receipt): bool
    {
        return in_array($receipt->reference_type, ['loan_repayment', 'Repayment'], true);
    }

    /**
     * Delete receipt and all associated records for a repayment.
     * Handles shared receipts (one receipt, multiple schedule repayments).
     */
    private function deleteRepaymentReceipt($repayment)
    {
        $receipt = null;
        if ($repayment->receipt_id) {
            $receipt = Receipt::find($repayment->receipt_id);
        }
        if (!$receipt) {
            $receipt = Receipt::where('reference', $repayment->id)
                ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                ->first();
        }

        if (!$receipt) {
            Log::info('No receipt found for repayment', ['repayment_id' => $repayment->id]);
            return;
        }

        $siblingCount = Repayment::where('receipt_id', $receipt->id)
            ->where('id', '!=', $repayment->id)
            ->count();

        if ($siblingCount > 0) {
            // Repayment row deleted by caller; rebuild aggregated GL from remaining rows on this receipt
            return;
        }

        $this->purgeReceiptCompletely($receipt);
    }

    /**
     * Hard-delete a receipt, its line items, and all related GL rows.
     */
    private function purgeReceiptCompletely(Receipt $receipt): void
    {
        $receiptItemsCount = ReceiptItem::where('receipt_id', $receipt->id)->delete();
        $receiptGLCount = GlTransaction::where('transaction_id', $receipt->id)
            ->whereIn('transaction_type', ['receipt', 'receipt_reversal'])
            ->delete();

        $receipt->delete();

        Log::info('Receipt deletion completed', [
            'receipt_id' => $receipt->id,
            'receipt_items_deleted' => $receiptItemsCount,
            'gl_transactions_deleted' => $receiptGLCount,
        ]);
    }

    /**
     * Post reversing GL entries for all original receipt postings.
     */
    private function createReceiptGlReversals(Receipt $receipt, string $reversalType = 'receipt_reversal'): int
    {
        $originalGlTransactions = GlTransaction::where('transaction_id', $receipt->id)
            ->where('transaction_type', 'receipt')
            ->get();

        foreach ($originalGlTransactions as $glTransaction) {
            $oppositeNature = $glTransaction->nature === 'debit' ? 'credit' : 'debit';

            GlTransaction::create([
                'chart_account_id' => $glTransaction->chart_account_id,
                'customer_id' => $glTransaction->customer_id,
                'supplier_id' => $glTransaction->supplier_id,
                'amount' => $glTransaction->amount,
                'nature' => $oppositeNature,
                'transaction_id' => $receipt->id,
                'transaction_type' => $reversalType,
                'date' => now(),
                'description' => ($glTransaction->description ?? '') . ' (Reversal)',
                'branch_id' => $glTransaction->branch_id,
                'user_id' => auth()->id(),
            ]);
        }

        return $originalGlTransactions->count();
    }

    /**
     * Delete journal and all associated records for a repayment
     */
    private function deleteRepaymentJournal($repayment)
    {
        // Find journal by reference (repayment ID) and reference_type
        $journal = Journal::where('reference', $repayment->id)
            ->where('reference_type', 'Withdrawal')
            ->first();

        if (!$journal) {
            Log::info('No journal found for repayment', ['repayment_id' => $repayment->id]);
            return;
        }

        Log::info('Deleting journal and associated data', [
            'journal_id' => $journal->id,
            'repayment_id' => $repayment->id
        ]);

        // Delete journal items
        $journalItemsCount = JournalItem::where('journal_id', $journal->id)->delete();
        Log::info('Deleted journal items', [
            'journal_id' => $journal->id,
            'count' => $journalItemsCount
        ]);

        // Delete GL transactions for this journal
        $journalGLCount = GlTransaction::where('transaction_id', $journal->id)
            ->where('transaction_type', 'journal repayment')
            ->delete();
        Log::info('Deleted GL transactions for journal', [
            'journal_id' => $journal->id,
            'count' => $journalGLCount
        ]);

        // Delete the journal
        $journal->delete();

        Log::info('Journal deletion completed', [
            'journal_id' => $journal->id,
            'journal_items_deleted' => $journalItemsCount,
            'gl_transactions_deleted' => $journalGLCount
        ]);
    }

    /**
     * Delete GL rows keyed by repayment id only (settle / cash journal).
     * Shared receipt GL is handled by deleteRepaymentReceipt + rebuildReceiptGlFromRepayments.
     */
    private function deleteRepaymentGLTransactions($repayment)
    {
        $repaymentGLCount = GlTransaction::where('transaction_id', $repayment->id)
            ->whereIn('transaction_type', ['journal repayment', 'Settle Interest', 'Settle Principal'])
            ->delete();

        $journal = Journal::where('reference', $repayment->id)
            ->where('reference_type', 'Withdrawal')
            ->first();
        if ($journal) {
            GlTransaction::where('transaction_id', $journal->id)
                ->where('transaction_type', 'journal repayment')
                ->delete();
        }

        Log::info('Deleted repayment-scoped GL transactions', [
            'repayment_id' => $repayment->id,
            'count' => $repaymentGLCount,
        ]);
    }

    /**
     * Restore cash deposit if repayment was made from cash deposit
     */
    private function restoreCashDepositForRepayment($repayment)
    {
        // Check if journal exists (indicates cash deposit payment)
        $journal = Journal::where('reference', $repayment->id)
            ->where('reference_type', 'Withdrawal')
            ->first();

        if (!$journal) {
            Log::info('No journal found, not a cash deposit repayment', ['repayment_id' => $repayment->id]);
            return;
        }

        // Find cash deposit from journal items (look for debit entries to cash deposit account)
        $journalItems = JournalItem::where('journal_id', $journal->id)
            ->where('nature', 'debit')
            ->get();

        if ($journalItems->isEmpty()) {
            Log::warning('No debit journal items found for cash deposit restoration', [
                'journal_id' => $journal->id
            ]);
            return;
        }

        // Get the cash deposit account ID from the first debit item
        $cashDepositAccountId = $journalItems->first()->chart_account_id;

        // Find the cash deposit record
        $cashDeposit = \App\Models\CashCollateral::whereHas('type', function ($query) use ($cashDepositAccountId) {
            $query->where('chart_account_id', $cashDepositAccountId);
        })->where('customer_id', $repayment->customer_id)->first();

        if ($cashDeposit) {
            $amountToRestore = $repayment->principal + $repayment->interest + $repayment->fee_amount + $repayment->penalt_amount;
            $cashDeposit->increment('amount', $amountToRestore);

            Log::info('Restored cash deposit amount', [
                'cash_deposit_id' => $cashDeposit->id,
                'amount_restored' => $amountToRestore,
                'new_balance' => $cashDeposit->amount
            ]);
        } else {
            Log::warning('Cash deposit not found for restoration', [
                'customer_id' => $repayment->customer_id,
                'chart_account_id' => $cashDepositAccountId
            ]);
        }
    }

    /**
     * Update loan status after repayment deletion
     */
    private function updateLoanStatusAfterDeletion($loan, $originalStatus)
    {
        // Accept a few possible closed/completed representations
        $closedValues = [
            defined('App\\Models\\Loan::STATUS_COMPLETE') ? \App\Models\Loan::STATUS_COMPLETE : 'completed',
            'complete',
            'closed',
            'completed'
        ];

        if (!in_array($originalStatus, $closedValues, true)) {
            // Loan wasn't closed/completed originally — nothing to do
            return;
        }

        try {
            // Refresh model and ensure schedules & repayments are loaded
            $loan->refresh();
            $loan->loadMissing(['schedule.repayments']);

            // If loan is no longer eligible for closing, revert status to active
            if (!$loan->isEligibleForClosing()) {
                $previous = $loan->status;
                $loan->status = \App\Models\Loan::STATUS_ACTIVE;
                $loan->save();

                Log::info('Loan status reverted to active after repayment deletion', [
                    'loan_id' => $loan->id,
                    'previous_status' => $previous,
                    'original_status' => $originalStatus
                ]);
            } else {
                // If still eligible for closing, ensure status is completed
                if ($loan->status !== \App\Models\Loan::STATUS_COMPLETE) {
                    $loan->status = \App\Models\Loan::STATUS_COMPLETE;
                    $loan->save();
                }
                Log::info('Loan remains eligible for closing after repayment deletion', ['loan_id' => $loan->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update loan status after repayment deletion', [
                'loan_id' => $loan->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reverse a receipt (accounting reversal + soft-delete)
     * 
     * @param \App\Models\Receipt $receipt
     * @return array
     * @throws \Exception
     */
    public function reverseReceipt(Receipt $receipt)
    {
        if ($this->isLoanFeeReceipt($receipt)) {
            return $this->reverseLoanFeeReceipt($receipt);
        }

        if (!$this->isLoanRepaymentReceipt($receipt)) {
            throw new \Exception('Receipt is not a loan-related receipt');
        }

        $loan = Loan::find($receipt->reference);
        $originalLoanStatus = $loan ? $loan->status : null;

        DB::beginTransaction();
        try {
            $reversalCount = $this->createReceiptGlReversals($receipt);

            Log::info('GL reversal entries created', [
                'receipt_id' => $receipt->id,
                'count' => $reversalCount,
            ]);

            $repayments = Repayment::where('receipt_id', $receipt->id)->get();
            $scheduleIds = $repayments->pluck('loan_schedule_id');
            foreach ($repayments as $repayment) {
                $repayment->delete();
            }

            $this->syncSchedulePaymentStatusForScheduleIds($scheduleIds);

            Log::info('Repayments soft-deleted', [
                'receipt_id' => $receipt->id,
                'count' => $repayments->count(),
            ]);

            $receipt->delete();

            if ($loan && $originalLoanStatus !== null) {
                $this->updateLoanStatusAfterDeletion($loan, $originalLoanStatus);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Receipt reversed successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reverse receipt', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Reverse a loan fee receipt (reference_type = loan).
     */
    private function reverseLoanFeeReceipt(Receipt $receipt): array
    {
        DB::beginTransaction();
        try {
            $this->createReceiptGlReversals($receipt);
            $receipt->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Loan fee receipt reversed successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Restore a reversed receipt
     * 
     * @param \App\Models\Receipt $receipt
     * @return array
     * @throws \Exception
     */
    public function restoreReversedReceipt(Receipt $receipt)
    {
        if (!$receipt->trashed()) {
            throw new \Exception('Receipt is not deleted');
        }

        if (!$this->isLoanFeeReceipt($receipt) && !$this->isLoanRepaymentReceipt($receipt)) {
            throw new \Exception('Receipt is not a loan-related receipt');
        }

        DB::beginTransaction();
        try {
            $reversalGlTransactions = GlTransaction::where('transaction_id', $receipt->id)
                ->where('transaction_type', 'receipt_reversal')
                ->get();

            foreach ($reversalGlTransactions as $reversalGl) {
                $oppositeNature = $reversalGl->nature === 'debit' ? 'credit' : 'debit';

                GlTransaction::create([
                    'chart_account_id' => $reversalGl->chart_account_id,
                    'customer_id' => $reversalGl->customer_id,
                    'supplier_id' => $reversalGl->supplier_id,
                    'amount' => $reversalGl->amount,
                    'nature' => $oppositeNature,
                    'transaction_id' => $receipt->id,
                    'transaction_type' => 'receipt',
                    'date' => now(),
                    'description' => str_replace(' (Reversal)', '', $reversalGl->description ?? ''),
                    'branch_id' => $reversalGl->branch_id,
                    'user_id' => auth()->id(),
                ]);
            }

            if ($this->isLoanRepaymentReceipt($receipt)) {
                Repayment::withTrashed()
                    ->where('receipt_id', $receipt->id)
                    ->restore();
            }

            $receipt->restore();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Receipt restored successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to restore receipt', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Permanently delete a receipt and all related data
     * 
     * @param \App\Models\Receipt $receipt
     * @return array
     * @throws \Exception
     */
    public function permanentlyDeleteReceipt(Receipt $receipt)
    {
        if (!$this->isLoanFeeReceipt($receipt) && !$this->isLoanRepaymentReceipt($receipt)) {
            throw new \Exception('Receipt is not a loan-related receipt');
        }

        $loan = Loan::find($receipt->reference);
        $originalLoanStatus = $loan ? $loan->status : null;

        DB::beginTransaction();
        try {
            GlTransaction::where('transaction_id', $receipt->id)
                ->whereIn('transaction_type', ['receipt', 'receipt_reversal'])
                ->delete();

            ReceiptItem::where('receipt_id', $receipt->id)->delete();

            if ($this->isLoanRepaymentReceipt($receipt)) {
                $scheduleIds = Repayment::withTrashed()
                    ->where('receipt_id', $receipt->id)
                    ->pluck('loan_schedule_id');

                Repayment::withTrashed()
                    ->where('receipt_id', $receipt->id)
                    ->forceDelete();

                $this->syncSchedulePaymentStatusForScheduleIds($scheduleIds);
            }

            $receipt = Receipt::withTrashed()->findOrFail($receipt->id);
            $receipt->forceDelete();

            if ($loan && $originalLoanStatus !== null) {
                $this->updateLoanStatusAfterDeletion($loan, $originalLoanStatus);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Receipt permanently deleted',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to permanently delete receipt', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    private function paymentContextUserId(array $paymentData): ?int
    {
        if (isset($paymentData['user_id'])) {
            return (int) $paymentData['user_id'];
        }

        return auth()->id();
    }

    private function paymentContextBranchId(Loan $loan, array $paymentData): int
    {
        if (isset($paymentData['branch_id'])) {
            return (int) $paymentData['branch_id'];
        }

        return (int) (auth()->user()?->branch_id ?? $loan->branch_id ?? 1);
    }

    private function markSchedulePaidIfSettled(LoanSchedule $schedule): void
    {
        $this->syncSchedulePaymentStatus($schedule);
    }

    /**
     * Align schedule status with actual repayment totals (paid vs active).
     */
    private function syncSchedulePaymentStatus(LoanSchedule $schedule): void
    {
        if (in_array($schedule->status, ['restructured', 'cancelled'], true)) {
            return;
        }

        $schedule->loadMissing(['repayments', 'loan.product']);

        $remaining = round(max(0, (float) $schedule->total_due - (float) $schedule->paid_amount), 2);

        if (Loan::isNegligibleBalance($remaining)) {
            if ($schedule->status !== 'paid') {
                $schedule->update(['status' => 'paid']);
            }

            return;
        }

        if ($schedule->status === 'paid') {
            $schedule->update(['status' => 'active']);
        }
    }

    /**
     * @param  iterable<int|string|null>  $scheduleIds
     */
    private function syncSchedulePaymentStatusForScheduleIds(iterable $scheduleIds): void
    {
        foreach (collect($scheduleIds)->filter()->unique() as $scheduleId) {
            $schedule = LoanSchedule::with(['repayments', 'loan.product'])->find($scheduleId);
            if ($schedule) {
                $this->syncSchedulePaymentStatus($schedule);
            }
        }
    }

    /**
     * Add/adjust penalty on a schedule and post matching accounting entries.
     */
    public function adjustPenalty($scheduleId, $amount, $reason = null, $loanId = null)
    {
        DB::beginTransaction();

        try {
            $schedule = LoanSchedule::with(['loan.product'])->findOrFail($scheduleId);
            $loan = $schedule->loan ?? ($loanId ? Loan::with('product')->find($loanId) : null);

            if (!$loan) {
                throw new \Exception('Loan not found for penalty adjustment.');
            }

            $adjustAmount = round((float) $amount, 2);
            if ($adjustAmount <= 0) {
                throw new \Exception('Adjustment amount must be greater than zero.');
            }

            $accrualService = PenaltyAccrualService::forDate(now()->toDateString());
            $penalty = $accrualService->resolvePenaltyForLoan($loan);

            if (!$penalty) {
                throw new \Exception('No active penalty configuration found for this loan product.');
            }

            $accrualService->postAccrual($loan, $schedule, $penalty, [
                'base_amount' => 0.0,
                'penalty_amount' => $adjustAmount,
                'days_overdue' => 0,
                'deduction_type' => 'manual_adjustment',
                'charge_frequency' => 'manual',
            ]);

            if ($reason) {
                AccruedPenalty::where('loan_schedule_id', $schedule->id)
                    ->whereNull('reversed_at')
                    ->latest('id')
                    ->limit(1)
                    ->update([
                        'description' => trim("Manual penalty adjustment for schedule #{$schedule->id}: {$reason}"),
                    ]);
            }

            $schedule->refresh();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Penalty adjusted successfully. Schedule and accounting records updated.',
                'adjusted_amount' => $adjustAmount,
                'new_penalty_amount' => (float) $schedule->penalty_amount,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to adjust penalty for schedule ID: {$scheduleId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
