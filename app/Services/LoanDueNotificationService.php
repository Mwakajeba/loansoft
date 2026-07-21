<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LoanDueNotificationService
{
    /**
     * Schedules due on a given date with remaining balance (same rules as loan schedule UI).
     *
     * @return Collection<int, object{name: string, amount_due: float, loan_id: int, schedule_id: int}>
     */
    public function getDueOnDate(int $branchId, ?Carbon $date = null): Collection
    {
        $date = ($date ?? Carbon::today())->startOfDay();

        $loans = Loan::query()
            ->with(['customer', 'schedule.repayments', 'product'])
            ->where('status', 'active')
            ->where('branch_id', $branchId)
            ->get();

        $rows = collect();

        foreach ($loans as $loan) {
            foreach ($loan->schedule as $schedule) {
                if (!$this->scheduleIsDueOnDate($schedule, $date)) {
                    continue;
                }

                $remaining = (float) $schedule->remaining_amount;
                if ($remaining <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
                    continue;
                }

                $rows->push((object) [
                    'name' => $loan->customer->name ?? 'N/A',
                    'amount_due' => round($remaining, 2),
                    'loan_id' => $loan->id,
                    'schedule_id' => $schedule->id,
                    'customer_id' => $loan->customer_id,
                    'phone1' => $loan->customer->phone1 ?? null,
                    'due_date' => Carbon::parse($schedule->due_date)->toDateString(),
                ]);
            }
        }

        return $rows->sortByDesc('amount_due')->values();
    }

    protected function scheduleIsDueOnDate(LoanSchedule $schedule, Carbon $date): bool
    {
        if (in_array($schedule->status, ['paid', 'restructured', 'cancelled'], true)) {
            return false;
        }

        return Carbon::parse($schedule->due_date)->isSameDay($date);
    }
}
