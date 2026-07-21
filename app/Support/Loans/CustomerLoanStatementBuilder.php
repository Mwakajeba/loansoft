<?php

namespace App\Support\Loans;

use App\Models\Loan;
use Carbon\Carbon;

class CustomerLoanStatementBuilder
{
    public static function build(Loan $loan, string $asOfDate): array
    {
        $schedules = $loan->schedule->sortBy('due_date')->values();
        $asOf = Carbon::parse($asOfDate)->endOfDay();

        $totalPrincipal = round((float) ($loan->amount ?? 0), 2);
        $totalInterest = round((float) $schedules->sum('interest'), 2);
        $totalPI = round($totalPrincipal + $totalInterest, 2);

        $firstSchedule = $schedules->first();
        $monthlyInstalment = $firstSchedule
            ? round((float) $firstSchedule->principal + (float) $firstSchedule->interest, 2)
            : 0.0;

        $scheduleRows = [];
        $totals = [
            'principal' => 0.0,
            'interest' => 0.0,
            'instalment' => 0.0,
            'penalty' => 0.0,
            'amount_due' => 0.0,
            'paid' => 0.0,
            'outstanding_balance' => 0.0,
        ];

        foreach ($schedules as $index => $schedule) {
            $row = self::buildScheduleRow($schedule, $asOfDate, $index + 1);
            $scheduleRows[] = $row;

            $totals['principal'] += $row['principal'];
            $totals['interest'] += $row['interest'];
            $totals['instalment'] += $row['instalment'];
            $totals['penalty'] += $row['penalty'];
            $totals['amount_due'] += $row['amount_due'];
            $totals['paid'] += $row['paid'];
            $totals['outstanding_balance'] += (float) ($row['outstanding_balance'] ?? 0);
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 2);
        }

        $settlementPlan = $loan->buildSettlementPlan($asOfDate);

        return [
            'client_name' => $loan->customer->name ?? 'N/A',
            'loan_no' => $loan->loanNo,
            'product_name' => $loan->product->name ?? 'N/A',
            'branch_name' => $loan->branch->name ?? 'N/A',
            'as_of_date' => $asOfDate,
            'summary' => [
                'principal' => $totalPrincipal,
                'interest' => $totalInterest,
                'total_pi' => $totalPI,
                'tenure' => (int) ($loan->period ?? $schedules->count()),
                'monthly_instalment' => $monthlyInstalment,
                'disbursement_date' => $loan->disbursed_on,
            ],
            'fee_rows' => self::buildManagementFeeRows($loan, $asOfDate),
            'schedule_rows' => $scheduleRows,
            'totals' => $totals,
            'settlements' => [
                'outstanding_amount' => LoanReportMetrics::arrearsAmountAsOf($loan, $asOfDate),
                'loan_settlement_amount' => (float) ($settlementPlan['settle_amount'] ?? 0),
            ],
        ];
    }

    private static function buildManagementFeeRows(Loan $loan, string $asOfDate): array
    {
        $fees = LoanFeeMetrics::configuredFees($loan);
        if ($fees->isEmpty()) {
            $totalFees = LoanFeeMetrics::totalConfiguredFees($loan);
            if ($totalFees <= 0) {
                return [];
            }

            $totalPaid = LoanFeeMetrics::totalFeesPaid($loan, $asOfDate);
            $isPaid = $totalPaid >= $totalFees - 0.009;

            return [[
                'label' => 'Management Fee',
                'amount' => $isPaid ? $totalFees : null,
                'remarks' => $isPaid ? 'Paid' : 'Not Paid',
                'status' => $isPaid ? 'paid' : 'unpaid',
            ]];
        }

        $rows = [];
        $totalPaid = LoanFeeMetrics::totalFeesPaid($loan, $asOfDate);
        $remainingPaidPool = $totalPaid;

        foreach ($fees as $fee) {
            $feeAmount = round((float) ($fee->calculated_amount ?? 0), 2);
            if ($feeAmount <= 0) {
                continue;
            }

            $allocatedPaid = min($remainingPaidPool, $feeAmount);
            $remainingPaidPool = max(0.0, $remainingPaidPool - $allocatedPaid);
            $isPaid = $allocatedPaid >= $feeAmount - 0.009;

            $rows[] = [
                'label' => $fee->name ?: 'Management Fee',
                'amount' => $isPaid ? $feeAmount : null,
                'remarks' => $isPaid ? 'Paid' : 'Not Paid',
                'status' => $isPaid ? 'paid' : 'unpaid',
            ];
        }

        return $rows;
    }

    private static function buildScheduleRow($schedule, string $asOfDate, int $sn): array
    {
        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $dueDate = Carbon::parse($schedule->due_date);

        $principal = round((float) ($schedule->principal ?? 0), 2);
        $interest = round((float) ($schedule->interest ?? 0), 2);
        $instalment = round($principal + $interest, 2);
        $penalty = round((float) ($schedule->penalty_amount ?? 0), 2);
        $amountDue = round($instalment + $penalty, 2);
        $paid = self::schedulePaidAsOf($schedule, $asOfDate);
        $remaining = max(0.0, round($amountDue - min($paid, $amountDue), 2));

        $rowClass = 'future';
        $displayOutstanding = null;
        $daysInArrears = 0;

        if ($remaining <= Loan::OUTSTANDING_CLOSURE_THRESHOLD) {
            $rowClass = 'paid';
        } elseif ($dueDate->lt($asOf)) {
            $rowClass = 'overdue';
            $displayOutstanding = $remaining;
            $daysInArrears = LoanReportMetrics::scheduleDaysInArrearsAsOf($schedule, $asOfDate);
        } else {
            $rowClass = 'future';
            $displayOutstanding = $instalment;
        }

        return [
            'sn' => $sn,
            'date' => $schedule->due_date,
            'principal' => $principal,
            'interest' => $interest,
            'instalment' => $instalment,
            'penalty' => $penalty,
            'amount_due' => $amountDue,
            'paid' => round($paid, 2),
            'outstanding_balance' => $displayOutstanding,
            'days_in_arrears' => $daysInArrears,
            'remarks' => self::scheduleRemarks($rowClass, $paid, $amountDue),
            'row_class' => $rowClass,
        ];
    }

    private static function schedulePaidAsOf($schedule, string $asOfDate): float
    {
        $asOf = Carbon::parse($asOfDate)->endOfDay();
        $paid = 0.0;

        foreach ($schedule->repayments as $repayment) {
            if (Carbon::parse($repayment->payment_date)->gt($asOf)) {
                continue;
            }

            $paid += (float) $repayment->principal
                + (float) $repayment->interest
                + (float) ($repayment->fee_amount ?? 0)
                + (float) ($repayment->penalt_amount ?? 0);
        }

        return $paid;
    }

    private static function scheduleRemarks(string $rowClass, float $paid, float $amountDue): string
    {
        if ($rowClass === 'paid') {
            return '';
        }

        if ($rowClass === 'overdue' && $paid > 0 && $paid < $amountDue) {
            return 'Partial';
        }

        if ($rowClass === 'overdue') {
            return 'Overdue';
        }

        return '';
    }
}
