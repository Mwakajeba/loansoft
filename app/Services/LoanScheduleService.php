<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LoanScheduleService
{
    /**
     * Update accrued interest in loan schedules
     *
     * @param Loan $loan
     * @param float $dailyInterestAmount
     * @param Carbon $date
     * @return void
     */
    public function updateAccruedInterest(Loan $loan, float $dailyInterestAmount, Carbon $date): void
    {
        // Get the next unpaid or partially paid schedule
        $nextSchedule = LoanSchedule::where('loan_id', $loan->id)
            ->where('due_date', '>=', $date)
            ->orderBy('due_date', 'asc')
            ->first();

        if ($nextSchedule) {
            // Add daily interest to accrued_interest
            $nextSchedule->increment('accrued_interest', $dailyInterestAmount);
            Log::info("Added {$dailyInterestAmount} to accrued_interest for schedule ID {$nextSchedule->id}, due date: {$nextSchedule->due_date}");
        } else {
            // If no future schedule, add to the last schedule
            $lastSchedule = LoanSchedule::where('loan_id', $loan->id)
                ->orderBy('due_date', 'desc')
                ->first();
            
            if ($lastSchedule) {
                $lastSchedule->increment('accrued_interest', $dailyInterestAmount);
                Log::info("Added {$dailyInterestAmount} to accrued_interest for last schedule ID {$lastSchedule->id}");
            }
        }
    }
}
