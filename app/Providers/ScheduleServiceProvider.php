<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * All scheduled tasks run synchronously (--sync) so cron only needs:
 *   php artisan schedule:run
 * No queue worker required.
 */
class ScheduleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // 00:00 — Subscription expiry check
            $schedule->command('subscription:check-expiry')
                ->dailyAt('00:00')
                ->withoutOverlapping(120)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/subscription-expiry-check.log'));

            // 00:05 — Penalty accrual (Penalties from /accounting/penalties)
            $schedule->command('accounting:accrue-penalties --sync --no-daily-interest')
                ->dailyAt('00:05')
                ->withoutOverlapping(120)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/penalty-accrual.log'));

            // 00:10 — Daily interest accrual (products with Daily method only)
            $schedule->command('loans:accrue-daily-interest')
                ->dailyAt('00:10')
                ->withoutOverlapping(120)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/daily-interest-accrual.log'));

            // 00:15 — Mature interest GL (As Expected products, due today)
            $schedule->command('loans:collect-mature-interest')
                ->dailyAt('00:15')
                ->withoutOverlapping(120)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/mature-interest-collection.log'));

            // 08:00 — Repayment SMS reminders
            $schedule->command('loans:send-repayment-reminders')
                ->dailyAt('08:00')
                ->withoutOverlapping(60)
                ->onOneServer()
                ->appendOutputTo(storage_path('logs/repayment-reminder.log'));
        });
    }
}
