<?php

namespace App\Jobs;

use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\AccruedPenalty;
use App\Models\GlTransaction;
use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\JobLog;
use App\Jobs\CalculateDailyInterestJob;
use App\Helpers\SmsHelper;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Penalty Accrual Engine Job
 * 
 * This job calculates and accrues penalties for overdue loan schedules.
 * It respects grace periods, posts to GL transactions, creates journal entries,
 * and updates the penalty_amount in loan_schedules.
 */
class AccruePenaltyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 3;

    protected $accrualDate;

    /**
     * Create a new job instance.
     */
    public function __construct($accrualDate = null)
    {
        $this->accrualDate = $accrualDate ? Carbon::parse($accrualDate) : Carbon::today();
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $startTime = now();
        $jobLog = JobLog::create([
            'job_name' => 'AccruePenaltyJob',
            'status' => 'running',
            'started_at' => $startTime,
        ]);
        
        Log::info('Starting Penalty Accrual Engine', [
            'date' => $this->accrualDate->toDateString(),
            'job_log_id' => $jobLog->id
        ]);

        try {
            $activeLoans = Loan::where('status', 'active')
                ->with(['product', 'customer', 'branch', 'schedule' => function($query) {
                    $query->where('status', '!=', 'restructured')->with('repayments');
                }])
                ->get();
            
            Log::info('Found ' . $activeLoans->count() . ' active loans to process for penalty accrual');

            $totalProcessed = 0;
            $totalSuccessful = 0;
            $totalFailed = 0;
            $totalPenaltyAccrued = 0;
            $perScheduleDetails = [];

            foreach ($activeLoans as $loan) {
                try {
                    Log::info("Processing loan {$loan->loanNo} (ID: {$loan->id})");
                    $result = $this->processLoanPenaltyAccrual($loan);
                    
                    if ($result && $result['penalty_amount'] > 0) {
                        $totalProcessed++;
                        $totalSuccessful++;
                        $totalPenaltyAccrued += $result['penalty_amount'];
                        Log::info("Loan {$loan->loanNo}: Accrued TZS " . number_format($result['penalty_amount'], 2) . " in penalties");
                        
                        // Add details for each schedule that had penalty accrued
                        foreach ($result['schedules'] as $scheduleDetail) {
                            $perScheduleDetails[] = $scheduleDetail;
                        }
                    } else {
                        Log::info("Loan {$loan->loanNo}: No penalty accrued (result: " . ($result ? 'null' : 'no result') . ")");
                    }
                } catch (\Exception $e) {
                    $totalFailed++;
                    $perScheduleDetails[] = [
                        'loan_id' => $loan->id,
                        'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                        'customer_name' => $loan->customer ? $loan->customer->name : 'N/A',
                        'customer_id' => $loan->customer_id,
                        'penalty_amount' => null,
                        'error' => $e->getMessage(),
                    ];
                    Log::error("Failed to process penalty accrual for loan {$loan->id}: " . $e->getMessage());
                }
            }

            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);

            // Cache per-schedule details for Job Log Details page
            if (!empty($perScheduleDetails)) {
                \Illuminate\Support\Facades\Cache::put('penalty_accrual_job_details_' . $jobLog->id, $perScheduleDetails, now()->addDays(30));
            }

            // Update job log
            $jobLog->update([
                'status' => 'completed',
                'processed' => $totalProcessed,
                'successful' => $totalSuccessful,
                'failed' => $totalFailed,
                'total_amount' => $totalPenaltyAccrued,
                'summary' => "Processed {$totalProcessed} loans. Penalty accrued: TZS " . number_format($totalPenaltyAccrued, 2),
                'completed_at' => $endTime,
                'duration_seconds' => $duration,
            ]);

            Log::info('Penalty Accrual Engine completed', [
                'job_log_id' => $jobLog->id,
                'loans_processed' => $totalProcessed,
                'successful' => $totalSuccessful,
                'failed' => $totalFailed,
                'total_penalty_accrued' => number_format($totalPenaltyAccrued, 2),
                'date' => $this->accrualDate->toDateString(),
                'duration' => $duration . 's'
            ]);

            // Run daily accrual interest job after penalty accrual completes
            try {
                Log::info('Running CalculateDailyInterestJob after penalty accrual completion');
                dispatch_sync(new CalculateDailyInterestJob($this->accrualDate));
            } catch (\Throwable $e) {
                Log::error('Failed to run CalculateDailyInterestJob after penalty accrual: ' . $e->getMessage());
                // Don't throw - allow penalty accrual to be marked as successful even if daily interest fails
            }

        } catch (\Exception $e) {
            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);
            
            // Update job log with error
            $jobLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => $endTime,
                'duration_seconds' => $duration,
            ]);
            
            Log::error('Error in Penalty Accrual Engine: ' . $e->getMessage(), [
                'job_log_id' => $jobLog->id,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Process penalty accrual for a single loan
     * Returns array with penalty_amount and schedules details
     */
    private function processLoanPenaltyAccrual(Loan $loan): ?array
    {
        $product = $loan->product;

        if (!$product) {
            Log::info("Loan {$loan->loanNo}: No product found");
            return null;
        }

        if (!$product->penalty) {
            Log::info("Loan {$loan->loanNo}: Product {$product->id} has no penalty configured (penalty_ids: " . json_encode($product->penalty_ids) . ")");
            return null;
        }

        $penaltyConfig = $product->penalty;
        
        Log::info("Loan {$loan->loanNo}: Processing penalty accrual with penalty config ID: {$penaltyConfig->id}");

        // Get penalty configuration
        $penaltyType = $penaltyConfig->penalty_type ?? 'percentage'; // 'percentage' or 'fixed amount'
        $penaltyRate = $penaltyConfig->amount ?? 0;
        $deductionType = $penaltyConfig->deduction_type ?? 'over_due_principal_and_interest';
        $chargeFrequency = $penaltyConfig->charge_frequency ?? 'daily'; // 'daily' or 'one_time'
        $frequencyCycle = $penaltyConfig->frequency_cycle ?? 'monthly'; // 'daily', 'weekly', 'monthly', etc.
        
        // Get account IDs for GL posting
        $penaltyReceivableAccountId = $penaltyConfig->penalty_receivables_account_id;
        $penaltyIncomeAccountId = $penaltyConfig->penalty_income_account_id;

        if (!$penaltyReceivableAccountId || !$penaltyIncomeAccountId) {
            Log::warning("Loan {$loan->loanNo}: Missing penalty accounts for loan product {$product->id} (Receivable: {$penaltyReceivableAccountId}, Income: {$penaltyIncomeAccountId})");
            return null;
        }

        $totalPenaltyAccrued = 0;
        $schedules = [];

        // Get overdue schedules (exclude restructured schedules)
        $overdueSchedules = $loan->schedule()
            ->with(['repayments'])
            ->where('due_date', '<', $this->accrualDate)
            ->where('status', '!=', 'restructured')
            ->orderBy('due_date')
            ->get();
        
        Log::info("Loan {$loan->loanNo}: Found " . $overdueSchedules->count() . " overdue schedules (excluding restructured)");

        foreach ($overdueSchedules as $schedule) {
            // Calculate base amount first to include in details
            $baseAmount = $this->calculatePenaltyBase($loan, $schedule, $deductionType);
            
            $result = $this->processSchedulePenaltyAccrual(
                $loan,
                $schedule,
                $penaltyConfig,
                $penaltyType,
                $penaltyRate,
                $deductionType,
                $chargeFrequency,
                $frequencyCycle,
                $penaltyReceivableAccountId,
                $penaltyIncomeAccountId,
                $baseAmount
            );

            if ($result && $result['penalty_amount'] > 0) {
                $penaltyAmount = $result['penalty_amount'];
                $totalPenaltyAccrued += $penaltyAmount;
                $schedules[] = [
                    'loan_id' => $loan->id,
                    'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                    'customer_name' => $loan->customer ? $loan->customer->name : 'N/A',
                    'customer_id' => $loan->customer_id,
                    'schedule_id' => $schedule->id,
                    'due_date' => $schedule->due_date,
                    'penalty_amount' => (float) $penaltyAmount,
                    'penalty_rate' => (float) $penaltyRate,
                    'penalty_type' => $penaltyType,
                    'deduction_type' => $deductionType,
                    'frequency_cycle' => $frequencyCycle,
                    'base_amount' => (float) $baseAmount,
                    'accrual_date' => $this->accrualDate->toDateString(),
                ];
            }
        }

        if ($totalPenaltyAccrued > 0) {
            return [
                'penalty_amount' => $totalPenaltyAccrued,
                'schedules' => $schedules,
            ];
        }

        return null;
    }

    /**
     * Process penalty accrual for a single schedule
     */
    private function processSchedulePenaltyAccrual(
        Loan $loan,
        LoanSchedule $schedule,
        $penaltyConfig,
        string $penaltyType,
        float $penaltyRate,
        string $deductionType,
        string $chargeFrequency,
        string $frequencyCycle,
        int $penaltyReceivableAccountId,
        int $penaltyIncomeAccountId,
        float $baseAmount = null
    ): ?array {
        // Check grace period from schedule (end_grace_date) or product
        $graceEndDate = $this->getGraceEndDate($loan, $schedule);
        
        // If still within grace period, skip
        if ($this->accrualDate->lte($graceEndDate)) {
            Log::info("Schedule {$schedule->id} (Loan {$loan->loanNo}): Still within grace period. Due: {$schedule->due_date}, Grace End: {$graceEndDate->toDateString()}, Accrual Date: {$this->accrualDate->toDateString()}");
            return null;
        }

        // For one-time penalties: check if already accrued (stop after first accrual, regardless of due amount)
        // For daily penalties: check if already accrued today (allow daily accrual until fully paid)
        if ($this->isPenaltyAlreadyAccrued($schedule, $chargeFrequency)) {
            Log::info("Schedule {$schedule->id} (Loan {$loan->loanNo}): Penalty already accrued for this schedule");
            return null;
        }

        // For daily penalties only: stop penalty when due = 0 (fully paid)
        // One-time penalties charge once even if due > 0
        if ($chargeFrequency === 'daily' && $this->isScheduleFullyPaid($schedule)) {
            return null;
        }

        // Calculate days overdue (after grace period)
        $daysOverdue = $this->accrualDate->diffInDays($graceEndDate);
        
        Log::info("Schedule {$schedule->id} (Loan {$loan->loanNo}): Days overdue: {$daysOverdue}, Base amount: {$baseAmount}");

        // Check penalty limit days - stop daily penalty if schedule arrears >= penalty_limit_days
        // Only applies to daily penalties
        if ($chargeFrequency === 'daily' && $penaltyConfig->penalty_limit_days !== null) {
            if ($daysOverdue >= $penaltyConfig->penalty_limit_days) {
                Log::info("Penalty accrual stopped for schedule {$schedule->id} (Loan {$loan->loanNo}): Days overdue ({$daysOverdue}) >= penalty limit days ({$penaltyConfig->penalty_limit_days})");
                return null;
            }
        }

        // Calculate penalty base amount if not provided
        if ($baseAmount === null) {
            $baseAmount = $this->calculatePenaltyBase($loan, $schedule, $deductionType);
        }

        // Calculate penalty amount
        $penaltyAmount = $this->calculatePenaltyAmount(
            $baseAmount,
            $penaltyType,
            $penaltyRate,
            $chargeFrequency,
            $frequencyCycle,
            $daysOverdue
        );

        Log::info("Schedule {$schedule->id} (Loan {$loan->loanNo}): Calculated penalty amount: {$penaltyAmount}");

        if ($penaltyAmount <= 0) {
            Log::info("Schedule {$schedule->id} (Loan {$loan->loanNo}): Penalty amount is 0 or negative, skipping");
            return null;
        }

        // Begin transaction for this schedule
        DB::beginTransaction();

        try {
            // Create journal entry
            $journal = $this->createJournalEntry(
                $loan,
                $schedule,
                $penaltyAmount,
                $penaltyReceivableAccountId,
                $penaltyIncomeAccountId
            );

            // Create accrued penalty record
            $accruedPenalty = AccruedPenalty::create([
                'loan_id' => $loan->id,
                'loan_schedule_id' => $schedule->id,
                'customer_id' => $loan->customer_id,
                'branch_id' => $loan->branch_id,
                'penalty_amount' => $penaltyAmount,
                'accrual_date' => $this->accrualDate,
                'penalty_type' => $penaltyType,
                'penalty_rate' => $penaltyRate,
                'calculation_basis' => $deductionType,
                'days_overdue' => $daysOverdue,
                'journal_id' => $journal->id,
                'posted_to_gl' => true,
                'description' => "Penalty accrual for overdue schedule #{$schedule->id} - Loan {$loan->loanNo}",
                'user_id' => 1, // System user
            ]);

            // Post to GL transactions
            $this->postToGlTransactions(
                $loan,
                $schedule,
                $accruedPenalty,
                $journal,
                $penaltyAmount,
                $penaltyReceivableAccountId,
                $penaltyIncomeAccountId
            );

            // Update loan_schedule penalty_amount
            $schedule->increment('penalty_amount', $penaltyAmount);

            // Send Swahili SMS to customer
            //$customerPhone = $loan->customer->phone1;
            //$smsMsg = 'Ndugu mteja, adhabu ya TZS ' . number_format($penaltyAmount, 2) . ' imeongezwa kwenye mkopo wako tarehe ' . $this->accrualDate->format('d-m-Y') . 'Lipia mapema kuepuka adhabu zaidi. Asante.';
            //\App\Helpers\SmsHelper::send($customerPhone, $smsMsg);

            DB::commit();

            Log::info("Accrued penalty for schedule", [
                'loan_no' => $loan->loanNo,
                'schedule_id' => $schedule->id,
                'penalty_amount' => $penaltyAmount,
                'days_overdue' => $daysOverdue,
            ]);

            return [
                'penalty_amount' => $penaltyAmount,
                'base_amount' => $baseAmount,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to accrue penalty for schedule {$schedule->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the grace period end date for a schedule
     */
    private function getGraceEndDate(Loan $loan, LoanSchedule $schedule): Carbon
    {
        // First check if schedule has specific end_grace_date
        if ($schedule->end_grace_date) {
            return Carbon::parse($schedule->end_grace_date);
        }

        // Fall back to product grace period
        $graceDays = $loan->product->grace_period ?? 0;
        return Carbon::parse($schedule->due_date)->addDays($graceDays);
    }

    /**
     * Check if schedule is fully paid
     */
    private function isScheduleFullyPaid(LoanSchedule $schedule): bool
    {
        // Ensure repayments are loaded
        if (!$schedule->relationLoaded('repayments')) {
            $schedule->load('repayments');
        }
        
        $paidAmount = $schedule->repayments->sum(function ($rep) {
            return ($rep->principal ?? 0) + ($rep->interest ?? 0) + ($rep->fee_amount ?? 0) + ($rep->penalt_amount ?? 0);
        });

        // Use accrued_interest if available, otherwise fall back to interest
        $interestAmount = $schedule->accrued_interest ?? $schedule->interest ?? 0;
        $totalDue = ($schedule->principal ?? 0) + $interestAmount + ($schedule->fee_amount ?? 0) + ($schedule->penalty_amount ?? 0);

        $isPaid = $paidAmount >= $totalDue;
        
        if ($isPaid) {
            Log::info("Schedule {$schedule->id}: Fully paid (Paid: {$paidAmount}, Due: {$totalDue})");
        }
        
        return $isPaid;
    }

    /**
     * Check if penalty has already been accrued
     */
    private function isPenaltyAlreadyAccrued(LoanSchedule $schedule, string $chargeFrequency): bool
    {
        $query = AccruedPenalty::where('loan_schedule_id', $schedule->id);

        if ($chargeFrequency === 'daily') {
            $query->whereDate('accrual_date', $this->accrualDate);
        }

        return $query->exists();
    }

    /**
     * Calculate the base amount for penalty calculation
     */
    private function calculatePenaltyBase(Loan $loan, LoanSchedule $schedule, string $deductionType): float
    {
        // Calculate remaining unpaid amounts
        $paidPrincipal = $schedule->repayments->sum('principal');
        $paidInterest = $schedule->repayments->sum('interest');
        
        $unpaidPrincipal = max(0, $schedule->principal - $paidPrincipal);
        // Use accrued_interest instead of interest for accurate penalty calculation
        $interestAmount = $schedule->accrued_interest ?? 0;
        $unpaidInterest = max(0, $interestAmount - $paidInterest);

        return match ($deductionType) {
            'over_due_principal_amount' => $unpaidPrincipal,
            'over_due_interest_amount' => $unpaidInterest,
            'over_due_principal_and_interest' => $unpaidPrincipal + $unpaidInterest,
            'total_principal_amount_released' => $loan->amount,
            default => $unpaidPrincipal + $unpaidInterest,
        };
    }

    /**
     * Calculate penalty amount based on type and rate
     * Uses frequency_cycle to convert rate to daily rate
     */
    private function calculatePenaltyAmount(
        float $baseAmount,
        string $penaltyType,
        float $penaltyRate,
        string $chargeFrequency,
        string $frequencyCycle,
        int $daysOverdue
    ): float {
        if ($baseAmount <= 0 || $penaltyRate <= 0) {
            return 0;
        }

        if ($chargeFrequency === 'daily') {
            // Daily penalty calculation - convert rate to daily based on frequency cycle
            if ($penaltyType === 'percentage') {
                // Convert rate to daily rate based on frequency cycle
                $dailyRate = $this->convertRateToDaily($penaltyRate, $frequencyCycle);
                return round($baseAmount * $dailyRate / 100, 2);
            } else {
                // Fixed amount - convert to daily based on frequency cycle
                $dailyAmount = $this->convertFixedAmountToDaily($penaltyRate, $frequencyCycle);
                return round($dailyAmount, 2);
            }
        } else {
            // One-time penalty calculation
            if ($penaltyType === 'percentage') {
                return round($baseAmount * $penaltyRate / 100, 2);
            } else {
                return round($penaltyRate, 2);
            }
        }
    }

    /**
     * Convert penalty rate to daily rate based on frequency cycle
     * 
     * @param float $rate The penalty rate (e.g., 4 for 4%)
     * @param string $frequencyCycle The frequency cycle (daily, weekly, monthly, quarterly, semi_annually, annually)
     * @return float Daily rate
     */
    private function convertRateToDaily(float $rate, string $frequencyCycle): float
    {
        $cycle = strtolower(trim($frequencyCycle));
        
        switch ($cycle) {
            case 'daily':
                return $rate; // Already daily
                
            case 'weekly':
                return $rate / 7; // Divide by 7 days
                
            case 'monthly':
                return $rate / 30; // Divide by 30 days (approximate)
                
            case 'quarterly':
                return $rate / 90; // Divide by 90 days (3 months)
                
            case 'semi_annually':
                return $rate / 180; // Divide by 180 days (6 months)
                
            case 'annually':
            case 'yearly':
                return $rate / 365; // Divide by 365 days
                
            default:
                // Default to monthly if cycle not recognized
                Log::warning("Unknown frequency cycle '{$cycle}' in AccruePenaltyJob. Defaulting to monthly.");
                return $rate / 30;
        }
    }

    /**
     * Convert fixed penalty amount to daily amount based on frequency cycle
     * 
     * @param float $amount The fixed penalty amount
     * @param string $frequencyCycle The frequency cycle
     * @return float Daily amount
     */
    private function convertFixedAmountToDaily(float $amount, string $frequencyCycle): float
    {
        $cycle = strtolower(trim($frequencyCycle));
        
        switch ($cycle) {
            case 'daily':
                return $amount; // Already daily
                
            case 'weekly':
                return $amount / 7; // Divide by 7 days
                
            case 'monthly':
                return $amount / 30; // Divide by 30 days (approximate)
                
            case 'quarterly':
                return $amount / 90; // Divide by 90 days (3 months)
                
            case 'semi_annually':
                return $amount / 180; // Divide by 180 days (6 months)
                
            case 'annually':
            case 'yearly':
                return $amount / 365; // Divide by 365 days
                
            default:
                // Default to monthly if cycle not recognized
                Log::warning("Unknown frequency cycle '{$cycle}' in AccruePenaltyJob. Defaulting to monthly.");
                return $amount / 30;
        }
    }

    /**
     * Create journal entry for penalty accrual
     */
    private function createJournalEntry(
        Loan $loan,
        LoanSchedule $schedule,
        float $penaltyAmount,
        int $penaltyReceivableAccountId,
        int $penaltyIncomeAccountId
    ): Journal {
        // Create journal header
        $journal = Journal::create([
            'date' => $this->accrualDate,
            'reference' => 'PEN-' . $loan->loanNo . '-' . $schedule->id . '-' . $this->accrualDate->format('Ymd'),
            'reference_type' => 'Penalty Accrual',
            'customer_id' => $loan->customer_id,
            'description' => "Penalty accrual for overdue schedule #{$schedule->id} - Loan {$loan->loanNo}",
            'branch_id' => $loan->branch_id,
            'user_id' => 1,
            'approved' => true, // Auto-approve system-generated journals
            'approved_by' => 1,
            'approved_at' => now(),
        ]);

        // Create journal items (debit and credit)
        // Debit: Penalty Receivable
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $penaltyReceivableAccountId,
            'amount' => $penaltyAmount,
            'description' => "Penalty receivable for loan {$loan->loanNo}",
            'nature' => 'debit',
        ]);

        // Credit: Penalty Income
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $penaltyIncomeAccountId,
            'amount' => $penaltyAmount,
            'description' => "Penalty income for loan {$loan->loanNo}",
            'nature' => 'credit',
        ]);

        return $journal;
    }

    /**
     * Post penalty accrual to GL transactions
     */
    private function postToGlTransactions(
        Loan $loan,
        LoanSchedule $schedule,
        AccruedPenalty $accruedPenalty,
        Journal $journal,
        float $penaltyAmount,
        int $penaltyReceivableAccountId,
        int $penaltyIncomeAccountId
    ): void {
        // Debit: Penalty Receivable
        GlTransaction::create([
            'chart_account_id' => $penaltyReceivableAccountId,
            'customer_id' => $loan->customer_id,
            'amount' => $penaltyAmount,
            'nature' => 'debit',
            'transaction_id' => $accruedPenalty->id,
            'transaction_type' => 'Accrued Penalty',
            'date' => $this->accrualDate,
            'description' => "Penalty accrual for loan {$loan->loanNo}, schedule {$schedule->id}",
            'branch_id' => $loan->branch_id,
            'user_id' => 1,
        ]);

        // Credit: Penalty Income
        GlTransaction::create([
            'chart_account_id' => $penaltyIncomeAccountId,
            'customer_id' => $loan->customer_id,
            'amount' => $penaltyAmount,
            'nature' => 'credit',
            'transaction_id' => $accruedPenalty->id,
            'transaction_type' => 'Accrued Penalty',
            'date' => $this->accrualDate,
            'description' => "Penalty income accrual for loan {$loan->loanNo}, schedule {$schedule->id}",
            'branch_id' => $loan->branch_id,
            'user_id' => 1,
        ]);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Penalty Accrual Engine job failed: ' . $exception->getMessage(), [
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
