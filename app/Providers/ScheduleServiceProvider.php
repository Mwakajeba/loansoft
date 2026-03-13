<?php

namespace App\Providers;

use App\Jobs\CollectMatureInterestJob;
use App\Jobs\RepaymentReminderJob;
use App\Jobs\CheckSubscriptionExpiryJob;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Mature interest & penalty collection — daily at midnight
            $schedule->job(new CollectMatureInterestJob())
                ->dailyAt('00:00')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/mature-interest-collection.log'));

            // Repayment SMS reminders — daily at 08:00 AM
            $schedule->job(new RepaymentReminderJob())
                ->dailyAt('08:00')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/repayment-reminder.log'));

            // Subscription expiry check — daily at midnight
            $schedule->job(new CheckSubscriptionExpiryJob())
                ->dailyAt('00:00')
                ->withoutOverlapping()
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/subscription-expiry-check.log'));
        });
    }
}
