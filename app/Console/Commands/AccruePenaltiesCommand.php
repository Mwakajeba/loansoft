<?php

namespace App\Console\Commands;

use App\Jobs\AccruePenaltyJob;
use App\Models\AccruedPenalty;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\PenaltyAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AccruePenaltiesCommand extends Command
{
    protected $signature = 'accounting:accrue-penalties
                            {--date= : Accrual date (Y-m-d), default today}
                            {--loan= : Process a single loan id only}
                            {--sync : Run synchronously in this process (recommended for cron)}
                            {--force : Run even if already completed today}
                            {--fix : Reverse incorrect accruals then re-accrue (one-time penalties)}
                            {--sync-schedules : After accrual, set schedule.penalty_amount = sum(accrued) when accrued is correct}
                            {--no-daily-interest : Skip CalculateDailyInterestJob after penalties}';

    protected $description = 'Accrue loan penalties from accounting penalty settings and product grace period (no login required)';

    public function handle(): int
    {
        $date = $this->option('date') ?: Carbon::today()->toDateString();
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $runDailyInterest = !$this->option('no-daily-interest');

        $this->info("Penalty accrual for {$date}" . ($loanId ? " (loan #{$loanId})" : ''));

        if ($this->option('fix')) {
            $fixed = $this->fixIncorrectAccruals($date, $loanId);
            $this->info("Fixed {$fixed} schedule(s) with incorrect penalty amounts.");
        }

        if ($this->option('sync-schedules')) {
            $synced = $this->syncSchedulePenaltyFromAccrued($date, $loanId);
            $this->info("Synced {$synced} schedule(s) penalty_amount from accrued_penalties.");
        }

        $job = new AccruePenaltyJob($date, $runDailyInterest, $loanId, $force);

        if ($sync) {
            $job->handle();
        } else {
            AccruePenaltyJob::dispatch($date, $runDailyInterest, $loanId, $force);
            $this->info('Penalty accrual job dispatched to queue. Use --sync for cron without a queue worker.');
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Reverse one-time penalty rows that used wrong base (e.g. excluded schedule interest).
     */
    protected function fixIncorrectAccruals(string $date, ?int $loanId): int
    {
        $service = PenaltyAccrualService::forDate($date);
        $fixed = 0;

        $loansQuery = Loan::where('status', 'active')->with(['product', 'schedule.repayments']);
        if ($loanId) {
            $loansQuery->where('id', $loanId);
        }

        foreach ($loansQuery->get() as $loan) {
            $penalty = $service->resolvePenaltyForLoan($loan);
            if (!$penalty || ($penalty->charge_frequency ?? 'daily') !== 'one_time') {
                continue;
            }

            foreach ($loan->schedule as $schedule) {
                if ($schedule->status === 'restructured') {
                    continue;
                }

                $existing = (float) AccruedPenalty::where('loan_schedule_id', $schedule->id)
                    ->whereNull('reversed_at')
                    ->sum('penalty_amount');

                if ($existing <= 0) {
                    continue;
                }

                if ($service->isWithinGracePeriod($loan, $schedule)) {
                    continue;
                }

                $deductionType = $penalty->deduction_type ?? 'over_due_principal_and_interest';
                $base = $service->calculatePenaltyBase($loan, $schedule, $deductionType);
                $daysOverdue = $service->daysOverdueAfterGrace($loan, $schedule);
                $expected = $service->calculatePenaltyAmount($base, $penalty, $daysOverdue);

                if (abs($existing - $expected) <= 0.02) {
                    continue;
                }

                $this->warn(sprintf(
                    'Loan %s schedule #%s: accrued %.2f, expected %.2f — reversing',
                    $loan->loanNo,
                    $schedule->id,
                    $existing,
                    $expected
                ));

                $service->reverseScheduleAccruals($schedule, 'Recalculated to match penalty settings');
                $schedule->refresh();
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Legacy: schedule.penalty_amount was doubled while accrued_penalties stayed correct.
     */
    protected function syncSchedulePenaltyFromAccrued(string $date, ?int $loanId): int
    {
        $service = PenaltyAccrualService::forDate($date);
        $synced = 0;

        $loansQuery = Loan::where('status', 'active')->with(['product', 'schedule']);
        if ($loanId) {
            $loansQuery->where('id', $loanId);
        }

        foreach ($loansQuery->get() as $loan) {
            $penalty = $service->resolvePenaltyForLoan($loan);

            foreach ($loan->schedule as $schedule) {
                $accruedSum = (float) AccruedPenalty::where('loan_schedule_id', $schedule->id)
                    ->whereNull('reversed_at')
                    ->sum('penalty_amount');

                $scheduleAmount = (float) $schedule->penalty_amount;

                if (abs($accruedSum - $scheduleAmount) <= 0.05) {
                    continue;
                }

                if ($accruedSum <= 0) {
                    continue;
                }

                $expected = 0.0;
                if ($penalty && !$service->isWithinGracePeriod($loan, $schedule)) {
                    $base = $service->calculatePenaltyBase($loan, $schedule, $penalty->deduction_type);
                    $days = $service->daysOverdueAfterGrace($loan, $schedule);
                    $expected = $service->calculatePenaltyAmount($base, $penalty, $days);
                }

                $target = $accruedSum;
                if ($penalty && abs($accruedSum - $expected) <= 0.02) {
                    $target = $expected;
                }

                $schedule->update(['penalty_amount' => round($target, 2)]);
                $synced++;
            }
        }

        return $synced;
    }
}
