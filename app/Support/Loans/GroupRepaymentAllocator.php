<?php

namespace App\Support\Loans;

use Illuminate\Support\Collection;

class GroupRepaymentAllocator
{
    /**
     * Allocate a payment to the selected schedule and each later schedule.
     *
     * @param  iterable<int, object>  $schedules
     * @param  array<int, string>  $paymentOrder
     * @return array{
     *     allocations: array<int, array{
     *         schedule_id: int,
     *         principal: float,
     *         interest: float,
     *         fee_amount: float,
     *         penalt_amount: float,
     *         total: float
     *     }>,
     *     cash_collateral: float
     * }
     */
    public static function allocate(iterable $schedules, float $amount, array $paymentOrder): array
    {
        $remaining = round(max(0, $amount), 2);
        $allocations = [];
        $orderedComponents = self::orderedComponents($paymentOrder);

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $repayments = $schedule->repayments instanceof Collection
                ? $schedule->repayments
                : collect($schedule->repayments ?? []);

            $balances = [
                'principal' => max(0, (float) $schedule->principal - (float) $repayments->sum('principal')),
                'interest' => max(0, (float) $schedule->interest - (float) $repayments->sum('interest')),
                'fee_amount' => max(0, (float) $schedule->fee_amount - (float) $repayments->sum('fee_amount')),
                'penalt_amount' => max(0, (float) $schedule->penalty_amount - (float) $repayments->sum('penalt_amount')),
            ];

            $allocation = [
                'schedule_id' => (int) $schedule->id,
                'principal' => 0.0,
                'interest' => 0.0,
                'fee_amount' => 0.0,
                'penalt_amount' => 0.0,
                'total' => 0.0,
            ];

            foreach ($orderedComponents as $component) {
                if ($remaining <= 0) {
                    break;
                }

                $applied = round(min($remaining, $balances[$component]), 2);
                $allocation[$component] = $applied;
                $allocation['total'] = round($allocation['total'] + $applied, 2);
                $remaining = round($remaining - $applied, 2);
            }

            if ($allocation['total'] > 0) {
                $allocations[] = $allocation;
            }
        }

        return [
            'allocations' => $allocations,
            'cash_collateral' => round($remaining, 2),
        ];
    }

    /**
     * @param  array<int, string>  $paymentOrder
     * @return array<int, string>
     */
    private static function orderedComponents(array $paymentOrder): array
    {
        $aliases = [
            'principal' => 'principal',
            'interest' => 'interest',
            'fee' => 'fee_amount',
            'fees' => 'fee_amount',
            'penalty' => 'penalt_amount',
            'penalties' => 'penalt_amount',
        ];

        $components = [];
        foreach ($paymentOrder as $item) {
            $component = $aliases[strtolower(trim((string) $item))] ?? null;
            if ($component && ! in_array($component, $components, true)) {
                $components[] = $component;
            }
        }

        foreach (['penalt_amount', 'fee_amount', 'interest', 'principal'] as $component) {
            if (! in_array($component, $components, true)) {
                $components[] = $component;
            }
        }

        return $components;
    }
}
