<?php

namespace App\Support\Loans;

use App\Models\SystemSetting;

class LoanRounding
{
    public static function enabled(): bool
    {
        return (bool) SystemSetting::getValue('loan_rounding_enabled', false);
    }

    public static function method(): string
    {
        $m = strtolower((string) SystemSetting::getValue('loan_rounding_method', 'nearest'));
        if (!in_array($m, ['nearest', 'up', 'down'], true)) {
            return 'nearest';
        }

        return $m;
    }

    public static function step(): float
    {
        $s = (float) SystemSetting::getValue('loan_rounding_step', 1);
        if ($s <= 0) {
            return 1.0;
        }

        return $s;
    }

    public static function roundAmount(float $amount): float
    {
        $amount = round($amount, 2);

        if (!self::enabled()) {
            return $amount;
        }

        $step = self::step();
        $method = self::method();

        $scaled = $amount / $step;
        if ($method === 'up') {
            $scaled = ceil($scaled);
        } elseif ($method === 'down') {
            $scaled = floor($scaled);
        } else {
            $scaled = round($scaled);
        }

        return round($scaled * $step, 2);
    }
}

