<?php

namespace App\Console\Commands;

use App\Jobs\CollectMatureInterestJob;
use Illuminate\Console\Command;

class CollectMatureInterestCommand extends Command
{
    protected $signature = 'loans:collect-mature-interest
                            {--async : Dispatch to queue instead of running immediately}';

    protected $description = 'Post mature interest to GL for schedules due today (As Expected products)';

    public function handle(): int
    {
        $this->info('Starting mature interest collection...');

        try {
            if ($this->option('async')) {
                CollectMatureInterestJob::dispatch();
                $this->warn('Job dispatched to queue.');
            } else {
                (new CollectMatureInterestJob())->handle();
            }

            $this->info('Mature interest collection completed.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
