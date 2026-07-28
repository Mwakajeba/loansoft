<?php

namespace App\Support\Loans;

use App\Models\Fee;
use App\Models\LoanProduct;
use Illuminate\Support\Collection;

class OpeningBalanceReleaseFeeResolver
{
    public static function feeColumnKey(int $feeId): string
    {
        return 'fee_'.$feeId;
    }

    /**
     * Parse fee id from an Excel header like fee_12 or fee_12_processing.
     */
    public static function feeIdFromColumnKey(string $header): ?int
    {
        if (preg_match('/^fee_(\d+)/i', trim($header), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * All active fees attached to the loan product (shown as Excel columns).
     *
     * @return Collection<int, Fee>
     */
    public static function productFeesForTemplate(LoanProduct $product): Collection
    {
        $feeIds = self::normalizeFeeIds($product->fees_ids);
        if (empty($feeIds)) {
            return collect();
        }

        $fees = Fee::query()
            ->whereIn('id', $feeIds)
            ->where(function ($q) {
                $q->whereRaw('LOWER(status) = ?', ['active']);
            })
            ->orderBy('id')
            ->get();

        // Fallback: if status filter yields nothing, still return attached fees.
        if ($fees->isEmpty()) {
            $fees = Fee::query()
                ->whereIn('id', $feeIds)
                ->orderBy('id')
                ->get();
        }

        return $fees;
    }

    /**
     * Fees deducted from cash when "deduct fees on release" is enabled.
     * Prefer charge_fee_on_release_date; if none, use all active product fees.
     *
     * @return Collection<int, Fee>
     */
    public static function releaseFeesForProduct(LoanProduct $product): Collection
    {
        $fees = self::productFeesForTemplate($product);
        if ($fees->isEmpty()) {
            return collect();
        }

        $onRelease = $fees->filter(
            fn (Fee $fee) => ($fee->deduction_criteria ?? '') === 'charge_fee_on_release_date'
        )->values();

        return $onRelease->isNotEmpty() ? $onRelease : $fees->values();
    }

    /**
     * @param  mixed  $feesIds
     * @return array<int, int>
     */
    public static function normalizeFeeIds($feesIds): array
    {
        if (is_string($feesIds)) {
            $decoded = json_decode($feesIds, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $feesIds = $decoded;
            } else {
                $feesIds = array_filter(array_map('trim', explode(',', $feesIds)));
            }
        }

        if (! is_array($feesIds) || empty($feesIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $feesIds))));
    }

    /**
     * Resolve release-fee amounts for one opening-balance row.
     *
     * - Custom fees: amount comes from Excel (required to fill; 0 stays 0).
     * - Fixed / percentage / range: Excel > 0 overrides; Excel 0/empty uses fee settings
     *   (fixed amount, or percentage/range of loan amount).
     *
     * @param  array<int, float>  $excelFeeAmountsById  fee_id => excel amount
     * @return array{
     *     total: float,
     *     custom_fee_amounts: array<int, float>,
     *     overrides: array<int, float>,
     *     breakdown: array<int, array{fee_id: int, name: string, fee_type: string, amount: float, source: string}>
     * }
     */
    public static function resolve(Collection $releaseFees, float $loanAmount, array $excelFeeAmountsById): array
    {
        $total = 0.0;
        $customFeeAmounts = [];
        $overrides = [];
        $breakdown = [];

        foreach ($releaseFees as $fee) {
            $feeId = (int) $fee->id;
            $excelAmount = round((float) ($excelFeeAmountsById[$feeId] ?? 0), 2);
            $source = 'settings';
            $amount = 0.0;

            if ($fee->isCustom()) {
                $amount = $excelAmount;
                $customFeeAmounts[$feeId] = $amount;
                $source = 'excel_custom';
            } elseif ($excelAmount > 0) {
                $amount = $excelAmount;
                $overrides[$feeId] = $amount;
                $source = 'excel_override';
            } else {
                $amount = $fee->monetaryAmountForPrincipal($loanAmount, $customFeeAmounts);
                $source = 'settings_'.$fee->fee_type;
            }

            $amount = round($amount, 2);
            $total += $amount;

            $breakdown[] = [
                'fee_id' => $feeId,
                'name' => (string) $fee->name,
                'fee_type' => (string) $fee->fee_type,
                'amount' => $amount,
                'source' => $source,
            ];
        }

        return [
            'total' => round($total, 2),
            'custom_fee_amounts' => $customFeeAmounts,
            'overrides' => $overrides,
            'breakdown' => $breakdown,
        ];
    }
}
