<?php

namespace App\Support\Loans;

use App\Models\Loan;
use Carbon\Carbon;

/**
 * Shared row builders for loan reports (web, PDF, Excel).
 */
class LoanReportRowBuilder
{
    public static function customerGender(?object $customer): string
    {
        if (!$customer) {
            return '';
        }
        $sex = $customer->sex ?? $customer->gender ?? '';

        return match (strtolower((string) $sex)) {
            'm', 'male' => 'Male',
            'f', 'female' => 'Female',
            default => $sex !== '' ? (string) $sex : '',
        };
    }

    public static function customerAgeCategory(?object $customer): string
    {
        if (!$customer || empty($customer->dob)) {
            return '';
        }

        $age = Carbon::parse($customer->dob)->age;

        return $age <= 35 ? 'Up to 35Yrs' : 'Above 35Yrs';
    }

    public static function loanSubsector(Loan $loan): string
    {
        return (string) ($loan->sector ?? $loan->subsector ?? '');
    }

    public static function loanTenure(Loan $loan): string
    {
        $period = (int) ($loan->period ?? 0);
        if ($period <= 0) {
            return '';
        }

        $unit = strtolower((string) ($loan->period_unit ?? $loan->interest_cycle ?? 'month'));
        if (str_contains($unit, 'month')) {
            $unit = 'month';
        } elseif (str_contains($unit, 'week')) {
            $unit = 'week';
        } elseif (str_contains($unit, 'year')) {
            $unit = 'year';
        } elseif (str_contains($unit, 'day')) {
            $unit = 'day';
        } else {
            $unit = rtrim($unit, 's');
        }

        return $period . ' ' . $unit . ($period === 1 ? '' : 's');
    }

    public static function identity(Loan $loan): array
    {
        $customer = $loan->customer;

        return [
            'customer' => $customer->name ?? 'N/A',
            'customer_no' => $customer->customerNo ?? $customer->customer_no ?? 'N/A',
            'phone' => $customer->phone1 ?? $customer->phone ?? 'N/A',
            'loan_no' => $loan->loanNo ?? 'N/A',
            'branch' => $loan->branch->name ?? 'N/A',
            'group' => $loan->group->name ?? 'Individual',
            'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
            'disbursed_date' => $loan->disbursed_on
                ? Carbon::parse($loan->disbursed_on)->format('d-m-Y')
                : 'N/A',
            'disbursed_date_iso' => $loan->disbursed_on
                ? Carbon::parse($loan->disbursed_on)->format('Y-m-d')
                : 'N/A',
            'maturity_date' => $loan->last_repayment_date
                ? Carbon::parse($loan->last_repayment_date)->format('Y-m-d')
                : 'N/A',
            'expires' => $loan->last_repayment_date
                ? Carbon::parse($loan->last_repayment_date)->format('Y-m-d')
                : 'N/A',
            'loan_amount' => round((float) ($loan->amount ?? 0), 2),
            'gender' => self::customerGender($customer),
            'age_category' => self::customerAgeCategory($customer),
            'subsector' => self::loanSubsector($loan),
            'tenure' => self::loanTenure($loan),
        ];
    }

    public static function scheduleTotals(Loan $loan): array
    {
        $totalInterest = 0.0;
        $totalFees = 0.0;
        $totalPenalty = 0.0;

        foreach ($loan->schedule as $schedule) {
            $totalInterest += (float) ($schedule->interest ?? 0);
            $totalFees += (float) ($schedule->fee_amount ?? 0);
            $totalPenalty += (float) ($schedule->penalty_amount ?? 0);
        }

        $principal = (float) ($loan->amount ?? 0);

        return [
            'total_interest' => round($totalInterest, 2),
            'total_fees' => round($totalFees, 2),
            'total_penalties' => round($totalPenalty, 2),
            'total_principal_interest' => round($principal + $totalInterest, 2),
        ];
    }

    public static function expectedVsCollectedRow(Loan $loan, string $startDate, string $endDate): ?array
    {
        $startBound = Carbon::parse($startDate)->startOfDay();
        $endBound = Carbon::parse($endDate)->endOfDay();

        $schedulesInPeriod = $loan->schedule
            ->filter(function ($schedule) use ($startBound, $endBound) {
                if (($schedule->status ?? '') === 'restructured') {
                    return false;
                }
                $dueDate = Carbon::parse($schedule->due_date);

                return $dueDate->between($startBound, $endBound);
            })
            ->sortBy(fn ($s) => Carbon::parse($s->due_date)->timestamp)
            ->values();

        $arrearsBefore = 0.0;
        $feesAsOfBefore = Carbon::parse($startDate)->subDay()->format('Y-m-d');
        $outstandingFees = LoanReportMetrics::outstandingFeesAsOf($loan, $feesAsOfBefore);

        foreach ($loan->schedule as $schedule) {
            if (($schedule->status ?? '') === 'restructured') {
                continue;
            }
            if (Carbon::parse($schedule->due_date)->startOfDay()->gte($startBound)) {
                continue;
            }
            $schedule->setRelation('loan', $loan);
            $remaining = LoanReportMetrics::scheduleRemainingAsOf($schedule, $feesAsOfBefore);
            if ($remaining <= 0) {
                continue;
            }
            $arrearsBefore += $remaining;
        }

        $dueInstalment = 0.0;
        $accruedPenalties = 0.0;

        foreach ($schedulesInPeriod as $schedule) {
            $dueInstalment += (float) ($schedule->principal ?? 0)
                + (float) ($schedule->interest ?? 0)
                + (float) ($schedule->fee_amount ?? 0);
            $accruedPenalties += (float) ($schedule->penalty_amount ?? 0);
        }

        $collectedTotal = 0.0;
        foreach ($loan->schedule as $schedule) {
            foreach ($schedule->repayments as $repayment) {
                $paymentDate = Carbon::parse($repayment->payment_date);
                if ($paymentDate->between($startBound, $endBound)) {
                    $collectedTotal += (float) ($repayment->principal ?? 0)
                        + (float) ($repayment->interest ?? 0)
                        + (float) ($repayment->fee_amount ?? 0)
                        + (float) ($repayment->penalt_amount ?? 0);
                }
            }
        }
        $collectedTotal += LoanFeeMetrics::feesPaidFromReceipts($loan, $endDate, $startDate);

        $dueInstalment = round($dueInstalment, 2);
        $accruedPenalties = round($accruedPenalties, 2);
        $arrearsBefore = round($arrearsBefore, 2);
        $outstandingFees = round($outstandingFees, 2);
        $collectedTotal = round($collectedTotal, 2);

        $totalInstalmentDue = round(
            $arrearsBefore + $outstandingFees + $dueInstalment + $accruedPenalties,
            2
        );

        if ($totalInstalmentDue <= 0 && $dueInstalment <= 0 && $arrearsBefore <= 0) {
            return null;
        }

        $instalmentDueDates = $schedulesInPeriod->map(
            fn ($s) => Carbon::parse($s->due_date)->format('d-m-Y')
        )->implode(', ');

        $identity = self::identity($loan);

        return array_merge($identity, [
            'instalment_due_dates' => $instalmentDueDates ?: '—',
            'outstanding_fees' => $outstandingFees,
            'arrears_before_period' => $arrearsBefore,
            'due_instalment' => $dueInstalment,
            'accrued_penalties' => $accruedPenalties,
            'total_instalment_due' => $totalInstalmentDue,
            'amount_paid' => $collectedTotal,
            'balance_due' => round($collectedTotal - $totalInstalmentDue, 2),
        ]);
    }

    public static function agingRow(Loan $loan, string $asOfDate): ?array
    {
        if ($loan->status !== Loan::STATUS_ACTIVE) {
            return null;
        }

        $metricsDate = LoanReportMetrics::metricsAsOfDate($asOfDate);
        $outstanding = LoanReportMetrics::outstandingBreakdownAsOf($loan, $metricsDate);
        $principalOutstanding = (float) $outstanding['outstanding_principal'];

        if ($principalOutstanding <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
            return null;
        }

        $daysInArrears = LoanReportMetrics::daysInArrearsAsOf($loan, $metricsDate);
        $buckets = LoanAgingBuckets::allocate($principalOutstanding, $daysInArrears);
        $identity = self::identity($loan);

        return array_merge($identity, [
            'disbursed_date' => $identity['disbursed_date_iso'],
            'outstanding_principal' => $principalOutstanding,
            'days_in_arrears' => $daysInArrears,
            'bucket_current' => $buckets['bucket_current'],
            'bucket_esm' => $buckets['bucket_esm'],
            'bucket_substandard' => $buckets['bucket_substandard'],
            'bucket_doubtful' => $buckets['bucket_doubtful'],
            'bucket_loss' => $buckets['bucket_loss'],
            'provision_rate' => $buckets['provision_rate'],
            'provision_amount' => $buckets['provision_amount'],
        ]);
    }

    public static function arrearsRow(Loan $loan, ?string $asOfDate = null): ?array
    {
        $asOf = $asOfDate ?? now()->format('Y-m-d');
        $metricsDate = LoanReportMetrics::metricsAsOfDate($asOf);
        $today = Carbon::parse($metricsDate)->endOfDay();

        $loanFees = 0.0;
        $penalties = 0.0;
        $instalmentInArrears = 0.0;
        $totalBalanceInArrears = 0.0;
        $daysInArrears = 0;
        $firstOverdueDate = null;
        $overdueCount = 0;

        foreach ($loan->schedule->sortBy('due_date') as $schedule) {
            if (($schedule->status ?? '') === 'restructured') {
                continue;
            }

            $dueDate = Carbon::parse($schedule->due_date);
            if ($dueDate->gt($today)) {
                continue;
            }

            $schedule->setRelation('loan', $loan);
            $remaining = LoanReportMetrics::scheduleRemainingAsOf($schedule, $metricsDate);
            if ($remaining <= 0.009) {
                continue;
            }

            $penaltyDue = (float) ($schedule->penalty_amount ?? 0);
            $penaltyPaid = $schedule->repayments
                ? (float) $schedule->repayments
                    ->filter(fn ($r) => Carbon::parse($r->payment_date)->lte($today))
                    ->sum('penalt_amount')
                : 0.0;
            $principalInterest = (float) ($schedule->principal ?? 0) + (float) ($schedule->interest ?? 0);
            $piPaid = $schedule->repayments
                ? (float) $schedule->repayments
                    ->filter(fn ($r) => Carbon::parse($r->payment_date)->lte($today))
                    ->sum('principal')
                    + (float) $schedule->repayments
                        ->filter(fn ($r) => Carbon::parse($r->payment_date)->lte($today))
                        ->sum('interest')
                : 0.0;

            $feeDue = (float) ($schedule->fee_amount ?? 0);
            $feePaidOnSchedule = $schedule->repayments
                ? (float) $schedule->repayments
                    ->filter(fn ($r) => Carbon::parse($r->payment_date)->lte($today))
                    ->sum('fee_amount')
                : 0.0;
            $loanFees += max(0.0, $feeDue - $feePaidOnSchedule);
            $penalties += max(0.0, $penaltyDue - $penaltyPaid);
            $instalmentInArrears += max(0.0, $principalInterest - $piPaid);
            $totalBalanceInArrears += $remaining;
            $overdueCount++;

            if (!$firstOverdueDate) {
                $firstOverdueDate = $dueDate;
                $daysInArrears = (int) round($firstOverdueDate->diffInDays(Carbon::parse($metricsDate)->startOfDay()));
            }
        }

        if ($totalBalanceInArrears <= 0) {
            return null;
        }

        if (LoanFeeMetrics::outstandingConfiguredFees($loan, $metricsDate) <= 0.009
            && LoanFeeMetrics::totalConfiguredFees($loan) > 0.009) {
            $loanFees = 0.0;
        }

        $identity = self::identity($loan);

        return array_merge($identity, [
            'loan_fees' => round($loanFees, 2),
            'penalties' => round($penalties, 2),
            'instalment_in_arrears' => round($instalmentInArrears, 2),
            'total_balance_in_arrears' => round($totalBalanceInArrears, 2),
            'days_in_arrears' => $daysInArrears,
            'first_overdue_date' => $firstOverdueDate ? $firstOverdueDate->format('d-m-Y') : 'N/A',
            'no_of_instalments' => $overdueCount,
            'arrears_severity' => self::arrearsSeverity($daysInArrears),
        ]);
    }

    public static function portfolioRow(Loan $loan, string $asOfDate): array
    {
        $metricsDate = LoanReportMetrics::metricsAsOfDate($asOfDate);
        $totals = LoanReportMetrics::contractTotalsAsOf($loan, $metricsDate);
        $outstanding = $totals['outstanding'];
        $identity = self::identity($loan);
        $daysInArrears = LoanReportMetrics::daysInArrearsAsOf($loan, $metricsDate);
        $status = LoanReportMetrics::effectiveReportStatus($loan, $outstanding['total_balance']);

        return array_merge($identity, [
            'status' => $status,
            'disbursed_amount' => round((float) ($loan->amount ?? 0), 2),
            'management_fees_balance' => (float) ($outstanding['outstanding_fees'] ?? 0),
            'management_fees_paid' => (float) ($totals['paid']['fees'] ?? 0),
            'outstanding_principal' => (float) ($outstanding['outstanding_principal'] ?? 0),
            'outstanding_interest' => (float) ($outstanding['outstanding_interest'] ?? 0),
            'accrued_penalties' => (float) ($outstanding['outstanding_penalty'] ?? 0),
            'outstanding_balance' => (float) ($outstanding['total_balance'] ?? 0),
            'total_paid' => (float) $totals['total_paid'],
            'repayment_rate' => round((float) $totals['repayment_rate'], 2),
            'days_in_arrears' => $daysInArrears,
            'is_in_arrears' => $daysInArrears > 0,
        ]);
    }

    public static function outstandingRow(Loan $loan, string $asOfDate): ?array
    {
        $metricsDate = LoanReportMetrics::metricsAsOfDate($asOfDate);
        $totals = LoanReportMetrics::contractTotalsAsOf($loan, $metricsDate);
        $paid = $totals['paid'];
        $outstanding = $totals['outstanding'];
        $scheduleTotals = self::scheduleTotals($loan);
        $identity = self::identity($loan);

        if ((float) $outstanding['total_balance'] <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
            return null;
        }

        return array_merge($identity, [
            'disbursed_amount' => round((float) ($loan->amount ?? 0), 2),
            'total_interest' => $scheduleTotals['total_interest'],
            'total_principal_interest' => $scheduleTotals['total_principal_interest'],
            'expected_fees' => $scheduleTotals['total_fees'],
            'total_penalties' => $scheduleTotals['total_penalties'],
            'principal_paid' => (float) $paid['principal'],
            'interest_paid' => (float) $paid['interest'],
            'fees_paid' => (float) $paid['fees'],
            'penalty_paid' => (float) $paid['penalties'],
            'outstanding_principal' => (float) $outstanding['outstanding_principal'],
            'outstanding_interest' => (float) $outstanding['outstanding_interest'],
            'outstanding_fees' => (float) $outstanding['outstanding_fees'],
            'outstanding_penalty' => (float) $outstanding['outstanding_penalty'],
            'other_outstanding' => 0.0,
            'outstanding_balance' => (float) $outstanding['total_balance'],
        ]);
    }

    /**
     * Compact loan list: customer, group, phone, amount, received, remaining, overdue, end date.
     */
    public static function summaryListRow(Loan $loan, string $asOfDate): ?array
    {
        $metricsDate = LoanReportMetrics::metricsAsOfDate($asOfDate);
        $totals = LoanReportMetrics::contractTotalsAsOf($loan, $metricsDate);
        $outstanding = (float) ($totals['outstanding']['total_balance'] ?? 0);

        if ($outstanding <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
            return null;
        }

        $identity = self::identity($loan);
        $endDate = $loan->last_repayment_date;
        if (! $endDate && $loan->schedule && $loan->schedule->isNotEmpty()) {
            $endDate = $loan->schedule->max('due_date');
        }

        return [
            'customer_name' => $identity['customer'],
            'group_name' => $identity['group'],
            'phone_number' => $identity['phone'],
            'loan_amount' => round((float) ($loan->amount ?? 0), 2),
            'total_received' => round((float) ($totals['total_paid'] ?? 0), 2),
            'remain_balance' => round($outstanding, 2),
            'overdue_amount' => LoanReportMetrics::arrearsAmountAsOf($loan, $metricsDate),
            'loan_end_date' => $endDate ? Carbon::parse($endDate)->format('d-m-Y') : 'N/A',
        ];
    }

    public static function arrearsSeverity(int $daysInArrears): string
    {
        if ($daysInArrears <= 30) {
            return 'Low';
        }
        if ($daysInArrears <= 60) {
            return 'Medium';
        }
        if ($daysInArrears <= 90) {
            return 'High';
        }

        return 'Critical';
    }
}
