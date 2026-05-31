<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LoanScheduleService
{
    /**
     * Add daily accrued interest to the active (first unpaid) schedule.
     */
    public function updateAccruedInterest(Loan $loan, float $dailyInterestAmount, Carbon $date): void
    {
        $schedule = $this->resolveAccrualTargetSchedule($loan);

        if (!$schedule) {
            Log::warning("Loan {$loan->loanNo}: no schedule found for daily interest accrual.");

            return;
        }

        $schedule->increment('accrued_interest', $dailyInterestAmount);
        Log::info("Added {$dailyInterestAmount} to accrued_interest for schedule ID {$schedule->id}, due {$schedule->due_date}");
    }

    /**
     * First schedule with remaining balance; else last schedule on the loan.
     */
    public function resolveAccrualTargetSchedule(Loan $loan): ?LoanSchedule
    {
        $loan->loadMissing(['schedule.repayments', 'product']);

        $schedules = $loan->schedule
            ->where('status', '!=', 'restructured')
            ->sortBy('due_date');

        foreach ($schedules as $schedule) {
            $schedule->setRelation('loan', $loan);
            if ($schedule->remaining_amount > 0.01) {
                return $schedule;
            }
        }

        return $schedules->last();
    }
}
