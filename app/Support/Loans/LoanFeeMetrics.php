<?php

namespace App\Support\Loans;

use App\Models\Fee;
use App\Models\GlTransaction;
use App\Models\Loan;
use App\Services\LoanDisbursementGlService;
use Carbon\Carbon;

/**
 * Fee payments via schedule repayments AND loan fee receipts (fees-receipt page).
 */
class LoanFeeMetrics
{
    public static function configuredFees(Loan $loan)
    {
        if (!$loan->relationLoaded('product') && $loan->product_id) {
            $loan->load('product');
        }

        if (!$loan->product || empty($loan->product->fees_ids)) {
            return collect();
        }

        $feeIds = is_array($loan->product->fees_ids)
            ? $loan->product->fees_ids
            : json_decode($loan->product->fees_ids, true);

        if (!is_array($feeIds) || empty($feeIds)) {
            return collect();
        }

        return Fee::whereIn('id', $feeIds)->get()->map(function ($fee) use ($loan) {
            $fee->calculated_amount = $fee->monetaryAmountForPrincipal(
                (float) $loan->amount,
                $loan->custom_fee_amounts
            );

            return $fee;
        });
    }

    public static function totalConfiguredFees(Loan $loan): float
    {
        return round((float) self::configuredFees($loan)->sum('calculated_amount'), 2);
    }

    public static function feesPaidFromRepayments(Loan $loan, ?string $asOfDate = null): float
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : null;
        $total = 0.0;

        $repayments = $loan->relationLoaded('repayments')
            ? $loan->repayments
            : $loan->repayments()->get();

        foreach ($repayments as $repayment) {
            if ($asOf && Carbon::parse($repayment->payment_date)->gt($asOf)) {
                continue;
            }
            $total += (float) ($repayment->fee_amount ?? 0);
        }

        return round($total, 2);
    }

    public static function feesPaidFromReceipts(Loan $loan, ?string $asOfDate = null, ?string $fromDate = null): float
    {
        $configured = self::configuredFees($loan);
        if ($configured->isEmpty()) {
            return 0.0;
        }

        $configuredFeeIds = $configured->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $configuredChartAccountIds = $configured->pluck('chart_account_id')->filter()->map(fn ($id) => (int) $id)->all();

        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : null;
        $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : null;
        $total = 0.0;

        $receipts = $loan->relationLoaded('receipts')
            ? $loan->receipts
            : $loan->receipts()->with('receiptItems.fee')->get();

        foreach ($receipts as $receipt) {
            $receiptDate = Carbon::parse($receipt->date ?? $receipt->created_at);
            if ($from && $receiptDate->lt($from)) {
                continue;
            }
            if ($asOf && $receiptDate->gt($asOf)) {
                continue;
            }

            $items = $receipt->relationLoaded('receiptItems')
                ? $receipt->receiptItems
                : $receipt->receiptItems()->with('fee')->get();

            foreach ($items as $item) {
                $itemFeeId = $item->fee_id ? (int) $item->fee_id : null;
                $itemChartAccountId = $item->chart_account_id ? (int) $item->chart_account_id : null;

                $isConfiguredFeePayment = ($itemFeeId && in_array($itemFeeId, $configuredFeeIds, true))
                    || (!$itemFeeId && $itemChartAccountId && in_array($itemChartAccountId, $configuredChartAccountIds, true));

                if ($isConfiguredFeePayment) {
                    $total += (float) $item->amount;
                }
            }
        }

        return round($total, 2);
    }

    /**
     * Release-date fees collected by netting them from loan cash disbursement.
     */
    public static function feesPaidOnRelease(Loan $loan, ?string $asOfDate = null, ?string $fromDate = null): float
    {
        $releaseFees = self::configuredFees($loan)
            ->filter(fn ($fee) => ($fee->deduction_criteria ?? '') === 'charge_fee_on_release_date'
                && strtolower((string) ($fee->status ?? 'active')) === 'active'
                && $fee->chart_account_id)
            ->values();

        if ($releaseFees->isEmpty()) {
            return 0.0;
        }

        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : null;
        $from = $fromDate ? Carbon::parse($fromDate)->startOfDay() : null;
        $chartAccountIds = $releaseFees
            ->pluck('chart_account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $posted = (float) GlTransaction::query()
            ->where('transaction_id', $loan->id)
            ->where('transaction_type', LoanDisbursementGlService::TRANSACTION_TYPE)
            ->where('nature', 'credit')
            ->whereIn('chart_account_id', $chartAccountIds)
            ->when($asOf, fn ($query) => $query->where('date', '<=', $asOf->toDateString()))
            ->when($from, fn ($query) => $query->where('date', '>=', $from->toDateString()))
            ->sum('amount');

        $expected = (float) $releaseFees->sum('calculated_amount');

        // Never count unrelated/excess credits to a shared fee account as payment.
        return round(min($posted, $expected), 2);
    }

    public static function totalFeesPaid(Loan $loan, ?string $asOfDate = null): float
    {
        return round(
            self::feesPaidFromRepayments($loan, $asOfDate)
                + self::feesPaidFromReceipts($loan, $asOfDate)
                + self::feesPaidOnRelease($loan, $asOfDate),
            2
        );
    }

    public static function outstandingConfiguredFees(Loan $loan, ?string $asOfDate = null): float
    {
        return round(max(0.0, self::totalConfiguredFees($loan) - self::totalFeesPaid($loan, $asOfDate)), 2);
    }
}
