<?php

namespace App\Console\Commands;

use App\Models\LoanSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill accrued_interest from the scheduled interest column for legacy schedules
 * created before accrued_interest was seeded (defaults to 0).
 *
 * Without this, daily-accrual (and some repayment paths) treat interest due as 0
 * even when the interest column has a value.
 *
 * Includes paid schedules that still have an outstanding balance when interest
 * is counted (status may be "paid" while interest was ignored because accrued was 0).
 */
class SeedScheduleAccruedInterestCommand extends Command
{
    protected $signature = 'loans:seed-schedule-accrued-interest
                            {--loan= : Limit to a single loan id}
                            {--branch= : Limit to loans in a branch id}
                            {--include-all-paid : Also update paid schedules with zero outstanding}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Copy interest → accrued_interest on schedules where accrued_interest is empty (incl. paid with outstanding > 0)';

    public function handle(): int
    {
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $includeAllPaid = (bool) $this->option('include-all-paid');
        $dryRun = (bool) $this->option('dry-run');

        $query = LoanSchedule::query()
            ->where('interest', '>', 0)
            ->where(function ($q) {
                $q->whereNull('accrued_interest')
                    ->orWhere('accrued_interest', '<=', 0);
            })
            ->whereNotIn('status', ['cancelled', 'restructured'])
            ->when($loanId, fn ($q) => $q->where('loan_id', $loanId))
            ->when($branchId, function ($q) use ($branchId) {
                $q->whereHas('loan', fn ($loan) => $loan->where('branch_id', $branchId));
            })
            ->with(['repayments', 'loan:id,loanNo']);

        $candidates = $query->orderBy('id')->get();

        $toUpdate = $candidates->filter(function (LoanSchedule $schedule) use ($includeAllPaid) {
            if ($includeAllPaid || $schedule->status !== 'paid') {
                return true;
            }

            // Paid but still owing when interest column is counted (accrued was empty).
            return $this->outstandingUsingInterestColumn($schedule) > 0.009;
        });

        $count = $toUpdate->count();
        $paidWithBalance = $toUpdate->where('status', 'paid')->count();

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Found {$count} schedule(s) to update ({$paidWithBalance} paid with outstanding > 0).");

        if ($count === 0) {
            $this->info('Nothing to update.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $samples = $toUpdate->take(25);
            $this->table(
                ['Schedule ID', 'Loan', 'Due date', 'Interest', 'Accrued (now)', 'Outstanding*', 'Status'],
                $samples->map(fn (LoanSchedule $s) => [
                    $s->id,
                    optional($s->loan)->loanNo ?? $s->loan_id,
                    $s->due_date,
                    number_format((float) $s->interest, 2),
                    number_format((float) ($s->accrued_interest ?? 0), 2),
                    number_format($this->outstandingUsingInterestColumn($s), 2),
                    $s->status,
                ])->all()
            );

            if ($count > 25) {
                $this->line('... and ' . ($count - 25) . ' more.');
            }

            $this->line('* Outstanding uses the interest column (not empty accrued_interest).');
            $this->warn('Dry run only — re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $updated = 0;

        DB::transaction(function () use ($toUpdate, &$updated) {
            foreach ($toUpdate->chunk(500) as $chunk) {
                foreach ($chunk as $schedule) {
                    $interest = round((float) $schedule->interest, 2);
                    $schedule->update(['accrued_interest' => $interest]);
                    $updated++;
                }
            }
        });

        $this->info("Updated {$updated} schedule(s): accrued_interest = interest.");

        return self::SUCCESS;
    }

    /**
     * Remaining balance when scheduled interest is used (ignores empty accrued_interest).
     */
    private function outstandingUsingInterestColumn(LoanSchedule $schedule): float
    {
        $interestDue = max(
            (float) ($schedule->interest ?? 0),
            (float) ($schedule->accrued_interest ?? 0)
        );

        $totalDue = (float) ($schedule->principal ?? 0)
            + $interestDue
            + (float) ($schedule->fee_amount ?? 0)
            + (float) ($schedule->penalty_amount ?? 0);

        $paid = (float) $schedule->paid_amount;

        return round(max(0, $totalDue - $paid), 2);
    }
}
