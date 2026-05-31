<?php

namespace App\Console\Commands;

use App\Models\Penalty;
use App\Services\PenaltyAccrualService;
use App\Support\Accounting\PenaltyConfiguration;
use Illuminate\Console\Command;

class PreviewPenaltyCalculationCommand extends Command
{
    protected $signature = 'accounting:preview-penalty
                            {penalty : Penalty ID from accounting/penalties}
                            {--base=100000 : Sample overdue base amount (TZS)}
                            {--days=10 : Sample days after grace}';

    protected $description = 'Preview penalty amount for a configuration (no DB writes)';

    public function handle(): int
    {
        $penalty = Penalty::find($this->argument('penalty'));
        if (!$penalty) {
            $this->error('Penalty not found.');

            return self::FAILURE;
        }

        try {
            PenaltyConfiguration::assertAccrualReady($penalty);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $base = (float) $this->option('base');
        $days = (int) $this->option('days');
        $svc = PenaltyAccrualService::forDate();
        $amount = $svc->calculatePenaltyAmount($base, $penalty, $days);

        $this->info("Penalty: {$penalty->name} (#{$penalty->id})");
        $this->line("Type: {$penalty->penalty_type} | Frequency: {$penalty->charge_frequency} | Cycle: {$penalty->frequency_cycle}");
        $this->line("Deduction: {$penalty->deduction_type} | Rate/amount: {$penalty->amount}");
        $this->line("Sample base: TZS " . number_format($base, 2) . " | Days after grace: {$days}");
        $this->info('Calculated penalty: TZS ' . number_format($amount, 2));
        if ($penalty->charge_frequency === 'daily') {
            $this->line('(Daily: this amount is charged once per day while overdue and within limit days.)');
        } else {
            $this->line('(One-time: this amount is charged once per overdue schedule.)');
        }

        return self::SUCCESS;
    }
}
