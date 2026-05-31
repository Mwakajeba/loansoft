<?php

namespace App\Support;

/**
 * Converts a monthly interest percentage to the rate applied per payment period.
 * The value entered in forms is treated as a monthly rate; the loan's interest_cycle
 * determines the per-period rate used in schedules (same as direct loan / calculator).
 */
class InterestRateConverter
{
    public static function fromMonthlyToCycle(float $monthlyRate, string $selectedCycle): float
    {
        switch (strtolower($selectedCycle)) {
            case 'daily':
                return $monthlyRate / 30;
            case 'weekly':
                return $monthlyRate / 4;
            case 'bimonthly':
                return $monthlyRate / 2;
            case 'monthly':
                return $monthlyRate;
            case 'quarterly':
                return $monthlyRate * 4;
            case 'semi_annually':
                return $monthlyRate * 6;
            case 'annually':
                return $monthlyRate * 12;
            default:
                return $monthlyRate;
        }
    }

    /**
     * Convert stored per-period rate back to monthly (for edit forms / display).
     */
    public static function fromCycleToMonthly(float $perPeriodRate, string $cycle): float
    {
        switch (strtolower($cycle)) {
            case 'daily':
                return $perPeriodRate * 30;
            case 'weekly':
                return $perPeriodRate * 4;
            case 'bimonthly':
                return $perPeriodRate * 2;
            case 'monthly':
                return $perPeriodRate;
            case 'quarterly':
                return $perPeriodRate / 4;
            case 'semi_annually':
                return $perPeriodRate / 6;
            case 'annually':
                return $perPeriodRate / 12;
            default:
                return $perPeriodRate;
        }
    }
}
