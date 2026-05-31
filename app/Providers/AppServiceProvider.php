<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Penalty accrual and daily interest run via ScheduleServiceProvider (cron).
        // Manual run: php artisan accounting:accrue-penalties --sync
    }
}
