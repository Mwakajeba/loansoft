<?php

namespace App\Support\Loans;

/**
 * IFRS-style aging buckets with provision rates (matches Excel template).
 */
class LoanAgingBuckets
{
    public const BUCKETS = [
        ['key' => 'bucket_current', 'label' => '0-5 CURRENT', 'min' => 0, 'max' => 5, 'rate' => 1],
        ['key' => 'bucket_esm', 'label' => '6-30 ESPECIALLY MENTIONED', 'min' => 6, 'max' => 30, 'rate' => 5],
        ['key' => 'bucket_substandard', 'label' => '31-60 SUBSTANDARD', 'min' => 31, 'max' => 60, 'rate' => 25],
        ['key' => 'bucket_doubtful', 'label' => '61-90 DOUBTFUL', 'min' => 61, 'max' => 90, 'rate' => 50],
        ['key' => 'bucket_loss', 'label' => 'MORE 91 LOSS', 'min' => 91, 'max' => PHP_INT_MAX, 'rate' => 100],
    ];

    /**
     * Allocate the relevant exposure into a single aging bucket.
     */
    public static function allocate(float $allocationAmount, int $daysInArrears): array
    {
        $result = [
            'bucket_current' => 0.0,
            'bucket_esm' => 0.0,
            'bucket_substandard' => 0.0,
            'bucket_doubtful' => 0.0,
            'bucket_loss' => 0.0,
            'provision_rate' => 0.0,
            'provision_amount' => 0.0,
        ];

        if ($allocationAmount <= 0) {
            return $result;
        }

        $daysInArrears = max(0, $daysInArrears);
        $rate = 0.0;

        foreach (self::BUCKETS as $bucket) {
            if ($daysInArrears < $bucket['min'] || $daysInArrears > $bucket['max']) {
                continue;
            }

            $result[$bucket['key']] = round($allocationAmount, 2);
            $rate = (float) $bucket['rate'];
            break;
        }

        $result['provision_rate'] = $rate;
        $result['provision_amount'] = round($allocationAmount * $rate / 100, 2);

        return $result;
    }
}
