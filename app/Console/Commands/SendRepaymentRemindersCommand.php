<?php

namespace App\Console\Commands;

use App\Jobs\RepaymentReminderJob;
use Illuminate\Console\Command;

class SendRepaymentRemindersCommand extends Command
{
    protected $signature = 'loans:send-repayment-reminders';

    protected $description = 'Send SMS repayment reminders to customers whose loans are due soon (controlled by system settings)';

    public function handle(): int
    {
        $this->info('Running repayment reminder job...');

        try {
            // dispatchSync runs the job immediately — no queue worker needed
            RepaymentReminderJob::dispatchSync();
            $this->info('Repayment reminder job completed successfully. Check the logs for details.');
        } catch (\Exception $e) {
            $this->error('Error running repayment reminder job: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
