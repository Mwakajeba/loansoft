<?php

namespace App\Providers;

use App\Jobs\AccruePenaltyJob;
use App\Jobs\CalculateDailyInterestJob;
use Carbon\Carbon;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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
        // Run jobs once per day on first user login
        Event::listen(Login::class, function () {
            Log::info('Login event triggered - checking daily jobs');

            // Run penalty accrual once per day on first user login
            try {
                $penaltyCacheKey = 'penalty_accrual_job_ran_' . Carbon::today()->toDateString();

                // Only run once per day
                $penaltyAdded = Cache::add($penaltyCacheKey, true, Carbon::now()->endOfDay());
                if (!$penaltyAdded) {
                    Log::info('Penalty accrual job already ran today, skipping');
                } else {
                    Log::info('Running AccruePenaltyJob synchronously from login event (once per day)');
                    dispatch_sync(new AccruePenaltyJob());
                }
            } catch (\Throwable $e) {
                Log::error('Failed dispatching AccruePenaltyJob on login: ' . $e->getMessage());
            }

            // Run daily accrual interest once per day on first user login
            try {
                $dailyInterestCacheKey = 'daily_accrual_interest_job_ran_' . Carbon::today()->toDateString();

                // Only run once per day
                $dailyInterestAdded = Cache::add($dailyInterestCacheKey, true, Carbon::now()->endOfDay());
                if (!$dailyInterestAdded) {
                    Log::info('Daily accrual interest job already ran today, skipping');
                } else {
                    Log::info('Running CalculateDailyInterestJob synchronously from login event (once per day)');
                    dispatch_sync(new CalculateDailyInterestJob());
                }
            } catch (\Throwable $e) {
                Log::error('Failed dispatching CalculateDailyInterestJob on login: ' . $e->getMessage());
            }
        });
    }
}
