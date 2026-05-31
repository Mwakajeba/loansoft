<?php

namespace App\Console\Commands;

use App\Models\AccruedPenalty;
use App\Models\LoanSchedule;
use App\Services\PenaltyAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RepairPenaltyMetadataCommand extends Command
{
    protected $signature = 'accounting:repair-penalty-metadata {--dry-run : Show changes without saving}';

    protected $description = 'Fix negative days_overdue on legacy accrued_penalties rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        $rows = AccruedPenalty::with(['loan.product', 'loanSchedule'])
            ->whereNull('reversed_at')
            ->where('days_overdue', '<', 0)
            ->get();

        foreach ($rows as $row) {
            if (!$row->loan || !$row->loanSchedule) {
                continue;
            }

            $svc = PenaltyAccrualService::forDate($row->accrual_date?->toDateString());
            $correct = $svc->daysOverdueAfterGrace($row->loan, $row->loanSchedule);

            if ($correct < 0) {
                $correct = 0;
            }

            $this->line("AccruedPenalty #{$row->id}: days_overdue {$row->days_overdue} -> {$correct}");

            if (!$dryRun) {
                $row->update(['days_overdue' => $correct]);
            }
            $fixed++;
        }

        $this->info(($dryRun ? 'Would fix' : 'Fixed') . " {$fixed} row(s).");

        return self::SUCCESS;
    }
}
