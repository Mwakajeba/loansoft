<?php

namespace App\Console\Commands;

use App\Models\AccruedPenalty;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\PenaltyAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Repair legacy penalty data: schedule.penalty_amount out of sync with accrued_penalties.
 */
class ReconcilePenaltySchedulesCommand extends Command
{
    protected $signature = 'accounting:reconcile-penalty-schedules
                            {--date= : Accrual date for expected calculation (Y-m-d), default today}
                            {--loan= : Limit to a single loan id}
                            {--dry-run : List changes without writing}
                            {--recalculate : Reverse accruals where sum(accrued) != expected, then re-accrue}
                            {--sync : Set schedule.penalty_amount = sum(non-reversed accrued) when accrued is correct}';

    protected $description = 'Reconcile legacy schedule.penalty_amount with accrued_penalties (and optionally recalculate wrong accruals)';

    public function handle(): int
    {
        $date = $this->option('date') ?: Carbon::today()->toDateString();
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $dryRun = (bool) $this->option('dry-run');
        $doSync = (bool) $this->option('sync');
        $doRecalculate = (bool) $this->option('recalculate');

        if (!$doSync && !$doRecalculate) {
            $this->warn('Nothing to do. Pass --sync and/or --recalculate.');
            $this->line('  --sync           Fix schedule.penalty_amount; clear stale penalty when no active accruals');
            $this->line('  --recalculate    Reverse + re-post when accrued amount is wrong');
            $this->line('Example: php artisan accounting:reconcile-penalty-schedules --sync');
            $this->line('         php artisan accounting:reconcile-penalty-schedules --recalculate --sync');
            $this->line('         php artisan accounting:accrue-penalties --sync --force  (after --recalculate)');
            $this->line('         php artisan accounting:cleanup-orphan-penalty-gl  (pardoned: schedule 0, dashboard still shows penalty)');

            return self::FAILURE;
        }

        $service = PenaltyAccrualService::forDate($date);
        $synced = 0;
        $recalculated = 0;
        $skipped = 0;

        $scheduleIds = $this->scheduleIdsToCheck($loanId);

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Checking ' . count($scheduleIds) . ' schedule(s)...');

        foreach ($scheduleIds as $scheduleId) {
            $schedule = LoanSchedule::with(['loan.product', 'repayments'])->find($scheduleId);
            if (!$schedule || !$schedule->loan) {
                continue;
            }

            $loan = $schedule->loan;
            if ($loan->status !== 'active') {
                continue;
            }

            $accruedSum = (float) AccruedPenalty::where('loan_schedule_id', $schedule->id)
                ->whereNull('reversed_at')
                ->sum('penalty_amount');

            $scheduleAmount = (float) $schedule->penalty_amount;

            if (abs($accruedSum - $scheduleAmount) <= 0.05) {
                continue;
            }

            $penalty = $service->resolvePenaltyForLoan($loan);
            $expected = 0.0;
            if ($penalty && !$service->isWithinGracePeriod($loan, $schedule)) {
                $base = $service->calculatePenaltyBase($loan, $schedule, $penalty->deduction_type);
                $days = $service->daysOverdueAfterGrace($loan, $schedule);
                $expected = $service->calculatePenaltyAmount($base, $penalty, $days);
            }

            $accruedMatchesExpected = $penalty && abs($accruedSum - $expected) <= 0.02;
            $scheduleDoubleAccrued = $accruedSum > 0
                && abs($scheduleAmount - ($accruedSum * 2)) <= 0.05;

            // Wrong accruals: reverse for re-posting
            if ($doRecalculate && $penalty && $accruedSum > 0 && !$accruedMatchesExpected) {
                $this->line(sprintf(
                    '  RECALC loan %s sched#%d: accrued=%.2f expected=%.2f schedule=%.2f',
                    $loan->loanNo,
                    $schedule->id,
                    $accruedSum,
                    $expected,
                    $scheduleAmount
                ));

                if (!$dryRun) {
                    $service->reverseScheduleAccruals($schedule, 'Legacy penalty recalculation');
                    $schedule->refresh();
                }
                $recalculated++;

                continue;
            }

            // Stale schedule penalty: no active accruals (pardoned, reversed, or never posted)
            if ($doSync && $accruedSum <= 0 && $scheduleAmount > 0) {
                $this->line(sprintf(
                    '  CLEAR loan %s sched#%d: schedule penalty %.2f -> 0 (no active accrued_penalties)',
                    $loan->loanNo,
                    $schedule->id,
                    $scheduleAmount
                ));
                if (!$dryRun) {
                    $schedule->update(['penalty_amount' => 0]);
                }
                $synced++;

                continue;
            }

            // Accrued OK but schedule column wrong (common legacy bug)
            if ($doSync && ($accruedMatchesExpected || $scheduleDoubleAccrued) && $accruedSum > 0) {
                $target = $accruedMatchesExpected ? $expected : $accruedSum;

                $this->line(sprintf(
                    '  SYNC loan %s sched#%d: schedule %.2f -> %.2f (accrued=%.2f%s)',
                    $loan->loanNo,
                    $schedule->id,
                    $scheduleAmount,
                    $target,
                    $accruedSum,
                    $scheduleDoubleAccrued ? ', was ~2x accrued' : ''
                ));

                if (!$dryRun) {
                    $schedule->update(['penalty_amount' => round($target, 2)]);
                }
                $synced++;

                continue;
            }

            $skipped++;
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would sync' : 'Synced') . " {$synced} schedule(s).");
        if ($doRecalculate) {
            $this->info(($dryRun ? 'Would recalculate' : 'Reversed for recalculation') . " {$recalculated} schedule(s).");
            if ($recalculated > 0 && !$dryRun) {
                $this->line('Run: php artisan accounting:accrue-penalties --sync --force');
            }
        }
        if ($skipped > 0) {
            $this->line("Skipped {$skipped} schedule(s) (no safe auto-fix).");
        }

        return self::SUCCESS;
    }

    /**
     * @return int[]
     */
    protected function scheduleIdsToCheck(?int $loanId): array
    {
        $fromSchedule = LoanSchedule::query()
            ->where('penalty_amount', '>', 0)
            ->when($loanId, fn ($q) => $q->where('loan_id', $loanId))
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->pluck('id');

        $fromAccrued = AccruedPenalty::query()
            ->whereNull('reversed_at')
            ->when($loanId, fn ($q) => $q->where('loan_id', $loanId))
            ->distinct()
            ->pluck('loan_schedule_id');

        return $fromSchedule->merge($fromAccrued)->unique()->values()->all();
    }
}
