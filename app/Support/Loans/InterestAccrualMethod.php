<?php

namespace App\Support\Loans;

/**
 * Accrual Interest Calculation Method (stored on loan_products.penalt_deduction_criteria).
 */
final class InterestAccrualMethod
{
    public const DAILY = 'daily';

    public const DAILY_LEGACY = 'daily_bases';

    public const AS_EXPECTED = 'as_expected_interest';

    public const AS_EXPECTED_LEGACY = 'full_amount';

    public static function isDaily(?string $method): bool
    {
        return in_array($method, [self::DAILY, self::DAILY_LEGACY], true);
    }

    public static function isAsExpected(?string $method): bool
    {
        return in_array($method, [self::AS_EXPECTED, self::AS_EXPECTED_LEGACY], true);
    }

    public static function label(?string $method): string
    {
        return self::isDaily($method)
            ? 'Daily Accrual'
            : (self::isAsExpected($method) ? 'As Expected Interest' : 'Unknown');
    }

    /**
     * Normalize legacy DB values to canonical form for new saves.
     */
    public static function normalize(?string $method): ?string
    {
        return match ($method) {
            self::DAILY_LEGACY => self::DAILY,
            self::AS_EXPECTED_LEGACY => self::AS_EXPECTED,
            default => $method,
        };
    }
}
