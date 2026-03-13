<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\DailyInterestAccrual;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InterestCalculationService
{
    /**
     * Calculate and create daily interest accrual for a loan
     *
     * @param Loan $loan
     * @param Carbon $date
     * @return array|null Returns array with loan_id, interest_amount, principal_balance or null if skipped
     */
    public function calculateAndCreateAccrual(Loan $loan, Carbon $date): ?array
    {
        // Get principal remaining
        $principalRemaining = $loan->getPrincipalRemaining();

        if ($principalRemaining <= 0) {
            Log::info("Loan {$loan->loanNo} has no principal remaining. Skipping.");
            return null;
        }

        // Check if interest accrual should be frozen based on arrears days
        $product = $loan->product;
        if ($product && $product->can_freeze_interest_accrual && $product->arrears_days_to_stop_interest_accrual) {
            // Ensure schedule is loaded with repayments to calculate days in arrears correctly
            $loan->loadMissing(['schedule' => function ($query) {
                $query->with('repayments');
            }]);
            
            // Calculate days in arrears as of the accrual date (not today)
            $daysInArrears = $this->calculateDaysInArrears($loan, $date);
            
            if ($daysInArrears >= $product->arrears_days_to_stop_interest_accrual) {
                Log::info("Loan {$loan->loanNo} interest accrual frozen: Days in arrears ({$daysInArrears}) >= threshold ({$product->arrears_days_to_stop_interest_accrual}) as of {$date->toDateString()}. Skipping interest calculation.");
                return null;
            }
        }

        // Treat interest as ANNUAL percentage from loan product if available, otherwise fall back to loan's own interest
        // Rule: Daily = annual_interest / 365
        $product = $loan->product;
        $annualInterestRate = $product && $product->interest ? $product->interest : $loan->interest; // e.g. 12 for 12% per year
        $dailyInterestRate = ($annualInterestRate / 365) / 100; // decimal per day

        // Calculate daily interest amount
        $dailyInterestAmount = $principalRemaining * $dailyInterestRate;

        // Round to 2 decimal places
        $dailyInterestAmount = round($dailyInterestAmount, 2);

        if ($dailyInterestAmount <= 0) {
            Log::info("Loan {$loan->loanNo} calculated zero interest. Skipping.");
            return null;
        }

        // Use firstOrCreate for idempotency - prevents duplicates even if job runs multiple times
        // Database unique constraint on ['loan_id', 'accrual_date'] ensures no duplicates at DB level
        $accrual = DailyInterestAccrual::firstOrCreate(
            [
                'loan_id' => $loan->id,
                'accrual_date' => $date,
            ],
            [
                'principal_balance' => $principalRemaining,
                'interest_rate' => $dailyInterestRate,
                'daily_interest_amount' => $dailyInterestAmount,
                'branch_id' => $loan->branch_id,
                'user_id' => 1, // System user - you may want to create a specific system user
            ]
        );

        // If accrual already existed, skip processing (idempotent behavior)
        if ($accrual->wasRecentlyCreated === false) {
            Log::info("Interest already accrued for loan {$loan->loanNo} on {$date->toDateString()} - skipping duplicate processing");
            return null;
        }

        Log::info("Interest accrued for loan {$loan->loanNo}: Principal={$principalRemaining}, Annual Rate={$annualInterestRate}%, Daily Rate=" . round($dailyInterestRate * 100, 6) . "%, Daily Interest={$dailyInterestAmount}");

        return [
            'loan_id' => $loan->id,
            'interest_amount' => $dailyInterestAmount,
            'principal_balance' => $principalRemaining,
            'accrual' => $accrual,
        ];
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
