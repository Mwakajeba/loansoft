<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Run the same daily batch as cron (for manual / recovery).
 */
class RunScheduledLoanJobsCommand extends Command
{
    protected $signature = 'loans:run-daily-batch
                            {--date= : Accrual date (Y-m-d) for penalty/interest jobs}';

    protected $description = 'Run all daily loan/accounting scheduled jobs in order (sync, no queue)';

    public function handle(): int
    {
        $date = $this->option('date');
        $dateOpt = $date ? ['--date' => $date] : [];

        $steps = [
            ['subscription:check-expiry', []],
            ['accounting:accrue-penalties', array_merge([
                '--sync' => true,
                '--no-daily-interest' => true,
            ], $dateOpt)],
            ['loans:accrue-daily-interest', $dateOpt],
            ['loans:collect-mature-interest', []],
        ];

        foreach ($steps as [$command, $options]) {
            $this->info("→ php artisan {$command}");
            $exit = Artisan::call($command, $options);
            $this->line(Artisan::output());
            if ($exit !== 0) {
                $this->error("Command {$command} failed with exit code {$exit}.");

                return self::FAILURE;
            }
        }

        $this->info('Daily batch completed.');

        return self::SUCCESS;
    }
}
