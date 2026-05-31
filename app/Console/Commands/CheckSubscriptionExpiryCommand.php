<?php

namespace App\Console\Commands;

use App\Jobs\CheckSubscriptionExpiryJob;
use Illuminate\Console\Command;

class CheckSubscriptionExpiryCommand extends Command
{
    protected $signature = 'subscription:check-expiry
                            {--async : Dispatch to queue instead of running immediately}';

    protected $description = 'Check expiring subscriptions, send reminders, and lock expired accounts';

    public function handle(): int
    {
        $this->info('Starting subscription expiry check...');

        try {
            if ($this->option('async')) {
                CheckSubscriptionExpiryJob::dispatch();
                $this->warn('Job dispatched to queue.');
            } else {
                (new CheckSubscriptionExpiryJob())->handle();
            }

            $this->info('Subscription expiry check completed.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
