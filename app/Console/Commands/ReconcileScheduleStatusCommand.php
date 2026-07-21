<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\LoanSchedule;
use Illuminate\Console\Command;

/**
 * Repair legacy schedules left as "active" with negligible remaining balances
 * (floating-point dust from old repayment rounding).
 */
class ReconcileScheduleStatusCommand extends Command
{
    protected $signature = 'loans:reconcile-schedule-status
                            {--loan= : Limit to a single loan id}
                            {--dry-run : List changes without writing}';

    protected $description = 'Mark legacy loan schedules with negligible remaining balances as paid';

    public function handle(): int
    {
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $dryRun = (bool) $this->option('dry-run');
        $threshold = Loan::OUTSTANDING_CLOSURE_THRESHOLD;

        $query = LoanSchedule::query()
            ->whereNotIn('status', ['paid', 'cancelled', 'restructured'])
            ->whereHas('loan', function ($q) use ($loanId) {
                $q->where('status', Loan::STATUS_ACTIVE);
                if ($loanId) {
                    $q->where('id', $loanId);
                }
            })
            ->with(['repayments', 'loan.product']);

        $schedules = $query->get();
        $marked = 0;
        $loansToCheck = [];

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Checking ' . $schedules->count() . ' active schedule(s)...');

        foreach ($schedules as $schedule) {
            $remaining = (float) $schedule->remaining_amount;
            if (!Loan::isNegligibleBalance($remaining)) {
                continue;
            }

            $loan = $schedule->loan;
            $this->line(sprintf(
                '  MARK PAID loan %s sched#%d due=%s remaining=%.4f',
                $loan->loanNo,
                $schedule->id,
                $schedule->due_date,
                $remaining
            ));

            if (!$dryRun) {
                $schedule->update(['status' => 'paid']);
            }

            $marked++;
            $loansToCheck[$loan->id] = $loan;
        }

        $closed = 0;
        if (!$dryRun && $loansToCheck !== []) {
            foreach ($loansToCheck as $loan) {
                $loan->refresh();
                $loan->load(['schedule.repayments', 'product']);

                if ($loan->isEligibleForClosing() && $loan->closeLoan()) {
                    $this->line("  CLOSED loan {$loan->loanNo}");
                    $closed++;
                }
            }
        }

        $suffix = $closed > 0 ? " Closed {$closed} loan(s)." : '';
        $this->info(($dryRun ? 'Would mark' : 'Marked') . " {$marked} schedule(s) as paid.{$suffix}");

        if ($marked === 0) {
            $this->comment("No schedules with remaining <= {$threshold} TZS found.");
        }

        return self::SUCCESS;
    }
}
