<?php

namespace App\Support\Loans;

use App\Models\Loan;
use Carbon\Carbon;

/**
 * Shared loan metrics for portfolio / tracking / performance reports.
 */
class LoanReportMetrics
{
    public static function eagerLoads(): array
    {
        return [
            'customer',
            'branch',
            'group',
            'loanOfficer',
            'product',
            'schedule.repayments',
            'repayments',
            'receipts.receiptItems.fee',
        ];
    }

    public static function paidBreakdown(Loan $loan): array
    {
        $principal = 0.0;
        $interest = 0.0;
        $penalties = 0.0;
        $fees = 0.0;

        if ($loan->schedule->isNotEmpty()) {
            foreach ($loan->schedule as $schedule) {
                foreach ($schedule->repayments as $repayment) {
                    $principal += (float) $repayment->principal;
                    $interest += (float) $repayment->interest;
                    $penalties += (float) ($repayment->penalt_amount ?? 0);
                    $fees += (float) ($repayment->fee_amount ?? 0);
                }
            }
        } else {
            foreach ($loan->repayments as $repayment) {
                $principal += (float) $repayment->principal;
                $interest += (float) $repayment->interest;
                $penalties += (float) ($repayment->penalt_amount ?? 0);
                $fees += (float) ($repayment->fee_amount ?? 0);
            }
        }

        $fees += LoanFeeMetrics::feesPaidFromReceipts($loan);

        return [
            'principal' => round($principal, 2),
            'interest' => round($interest, 2),
            'penalties' => round($penalties, 2),
            'fees' => round($fees, 2),
            'total' => round($principal + $interest + $penalties + $fees, 2),
        ];
    }

    public static function outstandingBreakdown(Loan $loan): array
    {
        return self::outstandingBreakdownAsOf($loan, now()->format('Y-m-d'));
    }

    public static function paidBreakdownAsOf(Loan $loan, string $asOfDate): array
    {
        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $principal = 0.0;
        $interest = 0.0;
        $penalties = 0.0;
        $fees = 0.0;

        if ($loan->schedule->isNotEmpty()) {
            foreach ($loan->schedule as $schedule) {
                foreach ($schedule->repayments as $repayment) {
                    if (Carbon::parse($repayment->payment_date)->gt($asOf)) {
                        continue;
                    }
                    $principal += (float) $repayment->principal;
                    $interest += (float) $repayment->interest;
                    $penalties += (float) ($repayment->penalt_amount ?? 0);
                    $fees += (float) ($repayment->fee_amount ?? 0);
                }
            }
        } else {
            foreach ($loan->repayments as $repayment) {
                if (Carbon::parse($repayment->payment_date)->gt($asOf)) {
                    continue;
                }
                $principal += (float) $repayment->principal;
                $interest += (float) $repayment->interest;
                $penalties += (float) ($repayment->penalt_amount ?? 0);
                $fees += (float) ($repayment->fee_amount ?? 0);
            }
        }

        $fees += LoanFeeMetrics::feesPaidFromReceipts($loan, $asOfDate);

        return [
            'principal' => round($principal, 2),
            'interest' => round($interest, 2),
            'penalties' => round($penalties, 2),
            'fees' => round($fees, 2),
            'total' => round($principal + $interest + $penalties + $fees, 2),
        ];
    }

    public static function outstandingFeesAsOf(Loan $loan, string $asOfDate): float
    {
        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $scheduleOutstanding = 0.0;

        foreach ($loan->schedule as $schedule) {
            $repayments = $schedule->repayments->filter(
                fn ($repayment) => Carbon::parse($repayment->payment_date)->lte($asOf)
            );
            $paidFees = (float) $repayments->sum('fee_amount');
            $scheduleOutstanding += max(0.0, (float) ($schedule->fee_amount ?? 0) - $paidFees);
        }

        $configuredOutstanding = LoanFeeMetrics::outstandingConfiguredFees($loan, $asOfDate);
        $scheduleExpected = (float) $loan->schedule->sum('fee_amount');
        $configuredTotal = LoanFeeMetrics::totalConfiguredFees($loan);

        if ($configuredTotal > $scheduleExpected + 0.009) {
            return round($scheduleOutstanding + $configuredOutstanding, 2);
        }

        return round(max($scheduleOutstanding, $configuredOutstanding), 2);
    }

    public static function outstandingBreakdownAsOf(Loan $loan, string $asOfDate): array
    {
        if (self::shouldZeroBalancesAsOf($loan, $asOfDate)) {
            return [
                'outstanding_principal' => 0.0,
                'outstanding_interest' => 0.0,
                'outstanding_penalty' => 0.0,
                'outstanding_fees' => 0.0,
                'total_balance' => 0.0,
            ];
        }

        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $schedules = $loan->schedule;

        $principalPaid = 0.0;
        foreach ($schedules as $schedule) {
            foreach ($schedule->repayments as $repayment) {
                if (Carbon::parse($repayment->payment_date)->lte($asOf)) {
                    $principalPaid += (float) $repayment->principal;
                }
            }
        }

        $outstandingPrincipal = max(0.0, (float) ($loan->amount ?? 0) - $principalPaid);
        $outstandingInterest = 0.0;
        $outstandingPenalty = 0.0;
        $outstandingFees = 0.0;

        foreach ($schedules as $schedule) {
            $repayments = $schedule->repayments->filter(
                fn ($repayment) => Carbon::parse($repayment->payment_date)->lte($asOf)
            );
            $paidInterest = (float) $repayments->sum('interest');
            $paidPenalty = (float) $repayments->sum('penalt_amount');
            $paidFees = (float) $repayments->sum('fee_amount');
            $outstandingInterest += max(0.0, (float) ($schedule->interest ?? 0) - $paidInterest);
            $outstandingPenalty += max(0.0, (float) ($schedule->penalty_amount ?? 0) - $paidPenalty);
            $outstandingFees += max(0.0, (float) ($schedule->fee_amount ?? 0) - $paidFees);
        }

        $outstandingPrincipal = round($outstandingPrincipal, 2);
        $outstandingInterest = round($outstandingInterest, 2);
        $outstandingPenalty = round($outstandingPenalty, 2);
        $outstandingFees = self::outstandingFeesAsOf($loan, $asOfDate);

        return [
            'outstanding_principal' => $outstandingPrincipal,
            'outstanding_interest' => $outstandingInterest,
            'outstanding_penalty' => $outstandingPenalty,
            'outstanding_fees' => $outstandingFees,
            'total_balance' => round(
                $outstandingPrincipal + $outstandingInterest + $outstandingPenalty + $outstandingFees,
                2
            ),
        ];
    }

    public static function contractTotalsAsOf(Loan $loan, string $asOfDate): array
    {
        $paid = self::paidBreakdownAsOf($loan, $asOfDate);
        $outstanding = self::outstandingBreakdownAsOf($loan, $asOfDate);
        $totalOutstanding = (float) $outstanding['total_balance'];
        $totalDue = round($paid['total'] + $totalOutstanding, 2);

        return [
            'total_due' => $totalDue,
            'total_paid' => $paid['total'],
            'total_outstanding' => $totalOutstanding,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'repayment_rate' => $totalDue > 0 ? ($paid['total'] / $totalDue) * 100 : 0,
        ];
    }

    public static function contractTotals(Loan $loan): array
    {
        $paid = self::paidBreakdown($loan);
        $outstanding = self::outstandingBreakdown($loan);
        $totalOutstanding = (float) $outstanding['total_balance'];
        $totalDue = round($paid['total'] + $totalOutstanding, 2);

        return [
            'total_due' => $totalDue,
            'total_paid' => $paid['total'],
            'total_outstanding' => $totalOutstanding,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'repayment_rate' => $totalDue > 0 ? ($paid['total'] / $totalDue) * 100 : 0,
        ];
    }

    public static function daysInArrearsAsOf(Loan $loan, string $asOfDate): int
    {
        if (self::shouldZeroBalancesAsOf($loan, $asOfDate)) {
            return 0;
        }

        $asOf = Carbon::parse($asOfDate)->startOfDay();
        $firstOverdueDate = null;

        foreach ($loan->schedule->sortBy('due_date') as $scheduleItem) {
            if (($scheduleItem->status ?? null) === 'restructured') {
                continue;
            }

            $dueDate = Carbon::parse($scheduleItem->due_date)->startOfDay();
            $remaining = self::scheduleRemainingAsOf($scheduleItem, $asOfDate);

            if ($dueDate->lt($asOf) && $remaining > 0.009) {
                $firstOverdueDate = $dueDate;
                break;
            }
        }

        return $firstOverdueDate ? (int) round($firstOverdueDate->diffInDays($asOf)) : 0;
    }

    public static function arrearsAmountAsOf(Loan $loan, string $asOfDate): float
    {
        if (self::shouldZeroBalancesAsOf($loan, $asOfDate)) {
            return 0.0;
        }

        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $total = 0.0;

        foreach ($loan->schedule->sortBy('due_date') as $scheduleItem) {
            if (($scheduleItem->status ?? null) === 'restructured') {
                continue;
            }

            if (Carbon::parse($scheduleItem->due_date)->gt($asOf)) {
                continue;
            }

            $total += self::scheduleRemainingAsOf($scheduleItem, $asOfDate);
        }

        return round($total, 2);
    }

    public static function scheduleRemainingAsOf($scheduleItem, string $asOfDate): float
    {
        if (in_array(($scheduleItem->status ?? null), ['paid', 'cancelled', 'restructured'], true)) {
            return 0.0;
        }

        if ($scheduleItem->relationLoaded('loan') && $scheduleItem->loan
            && in_array($scheduleItem->loan->status, [Loan::STATUS_COMPLETE, Loan::STATUS_RESTRUCTURED], true)) {
            return 0.0;
        }

        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $dueAmount = (float) ($scheduleItem->principal ?? 0)
            + (float) ($scheduleItem->interest ?? 0)
            + (float) ($scheduleItem->fee_amount ?? 0)
            + (float) ($scheduleItem->penalty_amount ?? 0);

        $paid = 0.0;
        foreach ($scheduleItem->repayments as $repayment) {
            if (Carbon::parse($repayment->payment_date)->gt($asOf)) {
                continue;
            }
            $paid += (float) $repayment->principal
                + (float) $repayment->interest
                + (float) ($repayment->fee_amount ?? 0)
                + (float) ($repayment->penalt_amount ?? 0);
        }

        return max(0.0, round($dueAmount - $paid, 2));
    }

    public static function metricsAsOfDate(string $toDate): string
    {
        $to = Carbon::parse($toDate)->endOfDay();
        $today = now()->endOfDay();

        return $to->gt($today) ? now()->format('Y-m-d') : $toDate;
    }

    public static function effectiveReportStatus(Loan $loan, ?float $outstanding = null): string
    {
        if (in_array($loan->status, [Loan::STATUS_COMPLETE, Loan::STATUS_RESTRUCTURED], true)) {
            return $loan->status;
        }

        $outstanding = $outstanding ?? self::outstandingBreakdown($loan)['total_balance'];

        if ($outstanding < Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
            return Loan::STATUS_COMPLETE;
        }

        return $loan->status ?? Loan::STATUS_ACTIVE;
    }

    public static function isEffectivelyComplete(Loan $loan, ?float $outstanding = null): bool
    {
        return self::effectiveReportStatus($loan, $outstanding) === Loan::STATUS_COMPLETE;
    }

    public static function settlementBalanceAsOf(Loan $loan, string $asOfDate): float
    {
        if (in_array($loan->status, [Loan::STATUS_COMPLETE, Loan::STATUS_RESTRUCTURED], true)) {
            return 0.0;
        }

        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $settlementBalance = 0.0;

        foreach ($loan->schedule->sortBy('due_date') as $schedule) {
            if (in_array(($schedule->status ?? null), ['restructured', 'paid', 'cancelled'], true)) {
                continue;
            }

            if (!$schedule->relationLoaded('loan')) {
                $schedule->setRelation('loan', $loan);
            }

            $repayments = $schedule->repayments->filter(
                fn ($repayment) => Carbon::parse($repayment->payment_date)->lte($asOf)
            );

            $remainingPrincipal = max(0.0, (float) ($schedule->principal ?? 0) - (float) $repayments->sum('principal'));
            $remainingInterest = max(0.0, (float) ($schedule->balance_interest_component ?? 0) - (float) $repayments->sum('interest'));
            $remainingFees = max(0.0, (float) ($schedule->fee_amount ?? 0) - (float) $repayments->sum('fee_amount'));
            $remainingPenalty = max(0.0, (float) ($schedule->penalty_amount ?? 0) - (float) $repayments->sum('penalt_amount'));
            $remainingTotal = round($remainingPrincipal + $remainingInterest + $remainingFees + $remainingPenalty, 2);

            if ($remainingTotal <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
                continue;
            }

            if (Carbon::parse($schedule->due_date)->endOfDay()->lte($asOf)) {
                $settlementBalance += $remainingTotal;
            } else {
                $settlementBalance += $remainingPrincipal;
            }
        }

        return round($settlementBalance, 2);
    }

    private static function shouldZeroBalancesAsOf(Loan $loan, string $asOfDate): bool
    {
        if (in_array($loan->status, [Loan::STATUS_COMPLETE, Loan::STATUS_RESTRUCTURED], true)) {
            return true;
        }

        return self::settlementBalanceAsOf($loan, $asOfDate) <= Loan::OUTSTANDING_CLOSURE_THRESHOLD;
    }
}
