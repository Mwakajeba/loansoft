<?php

namespace App\Support\Loans;

use App\Models\Fee;
use App\Models\LoanProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OpeningBalanceReleaseFeeResolver
{
    public static function feeColumnKey(int $feeId): string
    {
        return 'fee_'.$feeId;
    }

    /**
     * Active product fees charged on release date.
     *
     * @return Collection<int, Fee>
     */
    public static function releaseFeesForProduct(LoanProduct $product): Collection
    {
        $feeIds = is_array($product->fees_ids)
            ? $product->fees_ids
            : (json_decode($product->fees_ids ?? '[]', true) ?: []);

        if (! is_array($feeIds) || empty($feeIds)) {
            return collect();
        }

        $releaseIds = DB::table('fees')
            ->whereIn('id', $feeIds)
            ->where('deduction_criteria', 'charge_fee_on_release_date')
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if (empty($releaseIds)) {
            return collect();
        }

        return Fee::query()
            ->whereIn('id', $releaseIds)
            ->orderBy('id')
            ->get();
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
