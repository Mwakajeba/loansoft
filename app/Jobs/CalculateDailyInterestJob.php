<?php

namespace App\Jobs;

use App\Models\Loan;
use App\Models\JobLog;
use App\Services\InterestCalculationService;
use App\Services\LoanScheduleService;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateDailyInterestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 3;

    protected $date;
    protected $interestService;
    protected $scheduleService;
    protected $accountingService;

    /**
     * Create a new job instance.
     */
    public function __construct($date = null, InterestCalculationService $interestService = null, LoanScheduleService $scheduleService = null, AccountingService $accountingService = null)
    {
        $this->date = $date ? Carbon::parse($date) : Carbon::today();
        $this->interestService = $interestService ?? app(InterestCalculationService::class);
        $this->scheduleService = $scheduleService ?? app(LoanScheduleService::class);
        $this->accountingService = $accountingService ?? app(AccountingService::class);
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $startTime = now();
        $jobLog = JobLog::create([
            'job_name' => 'CalculateDailyInterestJob',
            'status' => 'running',
            'started_at' => $startTime,
        ]);
        
        Log::info('Starting daily interest calculation job for date: ' . $this->date->toDateString(), [
            'job_log_id' => $jobLog->id,
            'note' => 'Only processing loans with products using daily accrual interest method (penalt_deduction_criteria: daily or daily_bases)'
        ]);

        try {
            // Initialize counters
            $totalProcessed = 0;
            $totalSuccessful = 0;
            $totalFailed = 0;
            $totalSkipped = 0; // Track skipped loans (frozen, no principal, etc.)
            $totalInterestAccrued = 0;
            $perLoanDetails = [];

            // Use chunking to process loans in batches of 200 for better memory management
            // Only include loans disbursed before today (interest starts accruing the day after disbursement)
            // Only process loans where product uses daily accrual interest method
            // Load schedule with repayments to calculate days in arrears for freeze check
            Loan::where('status', Loan::STATUS_ACTIVE)
                ->whereNotNull('disbursed_on')
                ->where('disbursed_on', '<', $this->date)
                ->whereHas('product', function ($query) {
                    // Filter to only loans with products using daily accrual method
                    // Support both new value 'daily' and legacy value 'daily_bases' for backward compatibility
                    $query->whereIn('penalt_deduction_criteria', ['daily', 'daily_bases']);
                })
                ->with(['product', 'customer', 'branch', 'repayments', 'schedule.repayments'])
                ->chunk(200, function ($loans) use (&$totalProcessed, &$totalSuccessful, &$totalFailed, &$totalSkipped, &$totalInterestAccrued, &$perLoanDetails) {
                    foreach ($loans as $loan) {
                        $totalProcessed++;
                        
                        // Each loan is processed in its own transaction
                        // If one loan fails, it won't affect others
                        try {
                            $result = DB::transaction(function () use ($loan) {
                                return $this->processLoanInterest($loan);
                            });
                            
                            if ($result) {
                                $totalSuccessful++;
                                $totalInterestAccrued += $result['interest_amount'];
                                $perLoanDetails[] = [
                                    'loan_id' => $loan->id,
                                    'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                                    'customer_name' => $loan->customer ? $loan->customer->name : 'N/A',
                                    'customer_id' => $loan->customer_id,
                                    'principal_balance' => (float) $result['principal_balance'],
                                    'interest_accrued' => (float) $result['interest_amount'],
                                    'accrual_date' => $this->date->toDateString(),
                                    'status' => 'success',
                                ];
                            } else {
                                // Loan was skipped (frozen, no principal, zero interest, or already processed)
                                $totalSkipped++;
                                $skipReason = $this->getSkipReason($loan);
                                $perLoanDetails[] = [
                                    'loan_id' => $loan->id,
                                    'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                                    'customer_name' => $loan->customer ? $loan->customer->name : 'N/A',
                                    'customer_id' => $loan->customer_id,
                                    'principal_balance' => null,
                                    'interest_accrued' => null,
                                    'accrual_date' => $this->date->toDateString(),
                                    'status' => 'skipped',
                                    'skip_reason' => $skipReason,
                                ];
                            }
                        } catch (\Exception $e) {
                            $totalFailed++;
                            $perLoanDetails[] = [
                                'loan_id' => $loan->id,
                                'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                                'customer_name' => $loan->customer ? $loan->customer->name : 'N/A',
                                'customer_id' => $loan->customer_id,
                                'principal_balance' => null,
                                'interest_accrued' => null,
                                'accrual_date' => $this->date->toDateString(),
                                'status' => 'failed',
                                'error' => $e->getMessage(),
                            ];
                            Log::error("Failed to calculate interest for loan {$loan->id}: " . $e->getMessage());
                            // Continue processing next loan - transaction already rolled back automatically
                        }
                    }
                    
                    // Log progress after each chunk
                    Log::info("Processed chunk: {$totalProcessed} loans so far (Success: {$totalSuccessful}, Skipped: {$totalSkipped}, Failed: {$totalFailed})");
                });
            
            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);

            // Cache per-loan details for Job Log Details page
            if (!empty($perLoanDetails)) {
                \Illuminate\Support\Facades\Cache::put('daily_interest_job_details_' . $jobLog->id, $perLoanDetails, now()->addDays(30));
            }

            // Update job log
            $jobLog->update([
                'status' => 'completed',
                'processed' => $totalProcessed,
                'successful' => $totalSuccessful,
                'failed' => $totalFailed,
                'total_amount' => $totalInterestAccrued,
                'summary' => "Processed {$totalProcessed} loans. Successful: {$totalSuccessful}, Skipped: {$totalSkipped}, Failed: {$totalFailed}. Interest accrued: TZS " . number_format($totalInterestAccrued, 2),
                'completed_at' => $endTime,
                'duration_seconds' => $duration,
            ]);

            Log::info("Daily interest calculation completed. Processed {$totalProcessed} loans. Successful: {$totalSuccessful}, Skipped: {$totalSkipped}, Failed: {$totalFailed}. Total interest accrued: TZS " . number_format($totalInterestAccrued, 2), [
                'job_log_id' => $jobLog->id,
                'successful' => $totalSuccessful,
                'skipped' => $totalSkipped,
                'failed' => $totalFailed,
                'duration' => $duration . 's'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);
            
            // Update job log with error
            $jobLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => $endTime,
                'duration_seconds' => $duration,
            ]);
            
            Log::error('Error in daily interest calculation job: ' . $e->getMessage(), [
                'job_log_id' => $jobLog->id
            ]);
            Log::error($e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Process daily interest for a single loan using services
     * 
     * @param Loan $loan
     * @return array|null
     */
    private function processLoanInterest(Loan $loan): ?array
    {
        try {
            // Step 1: Calculate and create interest accrual
            $result = $this->interestService->calculateAndCreateAccrual($loan, $this->date);
            
            if (!$result) {
                return null; // Skipped (no principal, zero interest, or already processed)
            }

            $accrual = $result['accrual'];
            $dailyInterestAmount = $result['interest_amount'];

            // Step 2: Update loan schedule accrued interest
            $this->scheduleService->updateAccruedInterest($loan, $dailyInterestAmount, $this->date);

            // Step 3: Post accounting transactions
            $this->accountingService->postDailyInterestTransactions($loan, $accrual, $this->date);

            return [
                'loan_id' => $loan->id,
                'interest_amount' => $dailyInterestAmount,
                'principal_balance' => $result['principal_balance'],
            ];
        } catch (\Exception $e) {
            Log::error("Error processing interest for loan {$loan->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the reason why a loan was skipped
     * 
     * @param Loan $loan
     * @return string
     */
    private function getSkipReason(Loan $loan): string
    {
        // Check principal remaining
        $principalRemaining = $loan->getPrincipalRemaining();
        if ($principalRemaining <= 0) {
            return 'No principal remaining';
        }

        // Check if interest accrual is frozen
        $product = $loan->product;
        if ($product && $product->can_freeze_interest_accrual && $product->arrears_days_to_stop_interest_accrual) {
            // Ensure schedule is loaded with repayments
            $loan->loadMissing(['schedule' => function ($query) {
                $query->with('repayments');
            }]);
            
            // Calculate days in arrears as of the accrual date
            $daysInArrears = $this->calculateDaysInArrears($loan, $this->date);
            
            if ($daysInArrears >= $product->arrears_days_to_stop_interest_accrual) {
                return "Interest accrual frozen (Days in arrears: {$daysInArrears} >= threshold: {$product->arrears_days_to_stop_interest_accrual})";
            }
        }

        // Check if already processed (would be caught by firstOrCreate, but check here for completeness)
        $existingAccrual = \App\Models\DailyInterestAccrual::where('loan_id', $loan->id)
            ->where('accrual_date', $this->date)
            ->first();
        
        if ($existingAccrual) {
            return 'Already processed';
        }

        // Default skip reason
        return 'Skipped (zero interest or other reason)';
    }

    /**
     * Calculate days in arrears for a loan as of a specific date
     * 
     * @param Loan $loan
     * @param Carbon $asOfDate
     * @return int
     */
    private function calculateDaysInArrears(Loan $loan, Carbon $asOfDate): int
    {
        $firstOverdueDate = null;

        foreach ($loan->schedule->sortBy('due_date') as $scheduleItem) {
            $dueDate = Carbon::parse($scheduleItem->due_date);

            // If the due date has passed as of the accrual date and there's a remaining amount
            if ($dueDate->lt($asOfDate)) {
                // Calculate remaining amount: total due - paid
                $interestAmount = $scheduleItem->accrued_interest ?? $scheduleItem->interest ?? 0;
                $totalDue = $scheduleItem->principal + $interestAmount + $scheduleItem->fee_amount + $scheduleItem->penalty_amount;
                $paidAmount = $scheduleItem->repayments ? $scheduleItem->repayments->sum(function ($rep) {
                    return $rep->principal + $rep->interest + $rep->fee_amount + $rep->penalt_amount;
                }) : 0;
                $remainingAmount = max(0, $totalDue - $paidAmount);

                if ($remainingAmount > 0) {
                    $firstOverdueDate = $dueDate;
                    break; // We found the first overdue date
                }
            }
        }

        if ($firstOverdueDate) {
            return round($firstOverdueDate->diffInDays($asOfDate));
        }

        return 0; // No arrears
    }
}
