<?php

namespace App\Console\Commands;

use App\Jobs\CalculateDailyInterestJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AccrueDailyInterestCommand extends Command
{
    protected $signature = 'loans:accrue-daily-interest
                            {--date= : Accrual date (Y-m-d), default today}
                            {--async : Dispatch to queue instead of running immediately}';

    protected $description = 'Accrue daily interest for loans on products with Daily accrual method';

    public function handle(): int
    {
        $date = $this->option('date') ?: Carbon::today()->toDateString();
        $this->info("Daily interest accrual for {$date}");

        $job = new CalculateDailyInterestJob($date);

        if ($this->option('async')) {
            dispatch($job);
            $this->warn('Job dispatched to queue.');
        } else {
            $job->handle();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
