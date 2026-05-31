<?php

namespace App\Support\Loans;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class RepaymentReportBuilder
{
    public static function makeRepaymentRows(Collection $repayments): Collection
    {
        return $repayments->map(function ($repayment) {
            $paymentDate = Carbon::parse($repayment->payment_date)->toDateString();
            $principal = round((float) ($repayment->principal ?? 0), 2);
            $interest = round((float) ($repayment->interest ?? 0), 2);
            $feeAmount = round((float) ($repayment->fee_amount ?? 0), 2);
            $penaltyAmount = round((float) ($repayment->penalt_amount ?? 0), 2);

            return self::makeRow(
                data_get($repayment, 'loan'),
                [
                    'entry_type' => 'repayment',
                    'payment_date' => $paymentDate,
                    'payment_method' => data_get($repayment, 'chartAccount.account_name', 'N/A'),
                    'principal' => $principal,
                    'interest' => $interest,
                    'fee_amount' => $feeAmount,
                    'penalty_amount' => $penaltyAmount,
                ]
            );
        })->values();
    }

    public static function makeFeeReceiptRows(Collection $receipts, Collection $loansById): Collection
    {
        return $receipts->map(function ($receipt) use ($loansById) {
            $loanId = self::extractLoanIdFromReference($receipt->reference ?? null);
            if (!$loanId) {
                return null;
            }

            $loan = $loansById->get($loanId);
            if (!$loan) {
                return null;
            }

            $breakdown = self::receiptBreakdown($receipt, $loan);
            $receiptAmount = round((float) ($receipt->amount ?? 0), 2);

            if (
                $receiptAmount <= 0
                && $breakdown['principal'] <= 0
                && $breakdown['interest'] <= 0
                && $breakdown['fee_amount'] <= 0
                && $breakdown['penalty_amount'] <= 0
            ) {
                return null;
            }

            $paymentDate = Carbon::parse($receipt->date ?? $receipt->created_at)->toDateString();

            return self::makeRow(
                $loan,
                [
                    'entry_type' => 'fee_receipt',
                    'payment_date' => $paymentDate,
                    'payment_method' => data_get($receipt, 'bankAccount.chartAccount.account_name')
                        ?? data_get($receipt, 'bankAccount.name', 'N/A'),
                    'amount_paid' => $receiptAmount > 0
                        ? $receiptAmount
                        : round(
                            $breakdown['principal'] + $breakdown['interest'] + $breakdown['fee_amount'] + $breakdown['penalty_amount'],
                            2
                        ),
                    'principal' => $breakdown['principal'],
                    'interest' => $breakdown['interest'],
                    'fee_amount' => $breakdown['fee_amount'],
                    'penalty_amount' => $breakdown['penalty_amount'],
                ]
            );
        })->filter()->values();
    }

    public static function summarize(Collection $rows): array
    {
        $totalPrincipal = round((float) $rows->sum('principal'), 2);
        $totalInterest = round((float) $rows->sum('interest'), 2);
        $totalFees = round((float) $rows->sum('fee_amount'), 2);
        $totalPenalty = round((float) $rows->sum('penalty_amount'), 2);
        $totalPaid = round((float) $rows->sum('amount_paid'), 2);
        $transactionCount = $rows->count();

        return [
            'transaction_count' => $transactionCount,
            'repayment_count' => $transactionCount,
            'total_principal' => $totalPrincipal,
            'total_interest' => $totalInterest,
            'total_fees' => $totalFees,
            'total_penalty' => $totalPenalty,
            'total_paid' => $totalPaid,
            'average_paid' => $transactionCount > 0 ? round($totalPaid / $transactionCount, 2) : 0.0,
        ];
    }

    public static function monthlyGroups(Collection $rows, string $startDate, string $endDate): Collection
    {
        $sortedRows = self::sortRows($rows);
        $groups = collect();
        $cursor = Carbon::parse($startDate)->startOfMonth();
        $lastMonth = Carbon::parse($endDate)->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $monthKey = $cursor->format('Y-m');
            $monthRows = $sortedRows
                ->filter(fn ($row) => ($row->month_key ?? null) === $monthKey)
                ->values();

            $groups->push((object) [
                'month_key' => $monthKey,
                'month_label' => $cursor->format('F Y'),
                'rows' => $monthRows,
                'summary' => self::summarize($monthRows),
            ]);

            $cursor->addMonth();
        }

        return $groups;
    }

    public static function exportRows(Collection $monthlyGroups, array $grandSummary): array
    {
        $rows = [];

        foreach ($monthlyGroups as $group) {
            $rows[] = [
                $group->month_label,
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ];

            if ($group->rows->isEmpty()) {
                $rows[] = [
                    'No payments recorded',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ];
            } else {
                foreach ($group->rows as $row) {
                    $rows[] = self::exportableRow($row);
                }
            }

            $rows[] = self::summaryExportRow($group->month_label . ' Total', $group->summary);
            $rows[] = array_fill(0, 14, '');
        }

        $rows[] = self::summaryExportRow('Grand Total', $grandSummary);

        return $rows;
    }

    public static function exportHeadings(): array
    {
        return [
            'Payment Date',
            'Amount Paid',
            'Payment Method',
            'Loan Officer',
            'Customer Name',
            'Loan No',
            'Loan Product',
            'Principal',
            'Interest',
            'Fee Amount',
            'Penalty Amount',
            'Balance',
            'Branch',
            'Group Name',
        ];
    }

    public static function sortRows(Collection $rows): Collection
    {
        return $rows->sortBy(function ($row) {
            return sprintf(
                '%s|%s|%s|%s',
                $row->payment_date ?? '',
                $row->loan_no ?? '',
                $row->entry_type ?? '',
                $row->payment_method ?? ''
            );
        })->values();
    }

    public static function loanReferenceValues(Collection $loanIds): array
    {
        $references = [];

        foreach ($loanIds as $loanId) {
            $references[] = $loanId;
            $references[] = (string) $loanId;
            $references[] = 'LOAN-' . $loanId;
        }

        return array_values(array_unique($references, SORT_REGULAR));
    }

    private static function makeRow($loan, array $attributes): object
    {
        $principal = round((float) ($attributes['principal'] ?? 0), 2);
        $interest = round((float) ($attributes['interest'] ?? 0), 2);
        $feeAmount = round((float) ($attributes['fee_amount'] ?? 0), 2);
        $penaltyAmount = round((float) ($attributes['penalty_amount'] ?? 0), 2);
        $paymentDate = $attributes['payment_date'];

        return (object) [
            'entry_type' => $attributes['entry_type'] ?? 'repayment',
            'payment_date' => $paymentDate,
            'month_key' => Carbon::parse($paymentDate)->format('Y-m'),
            'amount_paid' => round(
                (float) ($attributes['amount_paid'] ?? ($principal + $interest + $feeAmount + $penaltyAmount)),
                2
            ),
            'payment_method' => $attributes['payment_method'] ?? 'N/A',
            'loan_officer_name' => data_get($loan, 'loanOfficer.name', 'N/A'),
            'customer_name' => data_get($loan, 'customer.name', 'N/A'),
            'loan_no' => data_get($loan, 'loanNo', 'N/A'),
            'loan_product' => data_get($loan, 'product.name', 'N/A'),
            'principal' => $principal,
            'interest' => $interest,
            'fee_amount' => $feeAmount,
            'penalty_amount' => $penaltyAmount,
            'balance' => round((float) data_get($loan, 'balance', 0), 2),
            'branch_name' => data_get($loan, 'branch.name', 'N/A'),
            'group_name' => data_get($loan, 'group.name', 'N/A'),
        ];
    }

    private static function extractLoanIdFromReference($reference): ?int
    {
        if (is_numeric($reference)) {
            return (int) $reference;
        }

        if (is_string($reference) && preg_match('/^LOAN-(\d+)$/i', $reference, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private static function exportableRow($row): array
    {
        return [
            $row->payment_date ? Carbon::parse($row->payment_date)->format('Y-m-d') : '',
            self::formatNumber($row->amount_paid ?? 0),
            $row->payment_method ?? 'N/A',
            $row->loan_officer_name ?? 'N/A',
            $row->customer_name ?? 'N/A',
            $row->loan_no ?? 'N/A',
            $row->loan_product ?? 'N/A',
            self::formatNumber($row->principal ?? 0),
            self::formatNumber($row->interest ?? 0),
            self::formatNumber($row->fee_amount ?? 0),
            self::formatNumber($row->penalty_amount ?? 0),
            self::formatNumber($row->balance ?? 0),
            $row->branch_name ?? 'N/A',
            $row->group_name ?? 'N/A',
        ];
    }

    private static function summaryExportRow(string $label, array $summary): array
    {
        return [
            $label,
            self::formatNumber($summary['total_paid'] ?? 0),
            '',
            '',
            '',
            '',
            '',
            self::formatNumber($summary['total_principal'] ?? 0),
            self::formatNumber($summary['total_interest'] ?? 0),
            self::formatNumber($summary['total_fees'] ?? 0),
            self::formatNumber($summary['total_penalty'] ?? 0),
            '',
            '',
            '',
        ];
    }

    private static function formatNumber($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private static function configuredReceiptBreakdownForLoan($receipt, $loan): array
    {
        static $configuredCache = [];

        $loanKey = (int) data_get($loan, 'id', 0);
        if (!array_key_exists($loanKey, $configuredCache)) {
            $configuredCache[$loanKey] = [
                'principal_account_id' => self::asIntOrNull(data_get($loan, 'report_principal_account_id')),
                'interest_account_ids' => collect(data_get($loan, 'report_interest_account_ids', []))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
                'fee_ids' => collect(data_get($loan, 'report_receipt_fee_ids', []))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'fee_chart_account_ids' => collect(data_get($loan, 'report_receipt_chart_account_ids', []))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
                'penalty_chart_account_ids' => collect(data_get($loan, 'report_penalty_chart_account_ids', []))
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all(),
            ];
        }

        $config = $configuredCache[$loanKey];
        $breakdown = [
            'principal' => 0.0,
            'interest' => 0.0,
            'fee_amount' => 0.0,
            'penalty_amount' => 0.0,
        ];

        foreach (collect($receipt->receiptItems ?? [])->values() as $item) {
            $amount = round((float) ($item->amount ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $itemFeeId = $item->fee_id ? (int) $item->fee_id : null;
            $itemChartAccountId = $item->chart_account_id ? (int) $item->chart_account_id : null;

            if ($itemFeeId && in_array($itemFeeId, $config['fee_ids'], true)) {
                $breakdown['fee_amount'] += $amount;
                continue;
            }

            if ($itemChartAccountId && in_array($itemChartAccountId, $config['penalty_chart_account_ids'], true)) {
                $breakdown['penalty_amount'] += $amount;
                continue;
            }

            if ($itemChartAccountId && in_array($itemChartAccountId, $config['fee_chart_account_ids'], true)) {
                $breakdown['fee_amount'] += $amount;
                continue;
            }

            if ($itemChartAccountId && in_array($itemChartAccountId, $config['interest_account_ids'], true)) {
                $breakdown['interest'] += $amount;
                continue;
            }

            if ($itemChartAccountId && $config['principal_account_id'] && $itemChartAccountId === $config['principal_account_id']) {
                $breakdown['principal'] += $amount;
                continue;
            }

            // Keep entire receipt represented in the report even when item account is not explicitly mapped.
            $breakdown['fee_amount'] += $amount;
        }

        return [
            'principal' => round($breakdown['principal'], 2),
            'interest' => round($breakdown['interest'], 2),
            'fee_amount' => round($breakdown['fee_amount'], 2),
            'penalty_amount' => round($breakdown['penalty_amount'], 2),
        ];
    }

    private static function receiptBreakdown($receipt, $loan): array
    {
        $itemsBreakdown = self::configuredReceiptBreakdownForLoan($receipt, $loan);
        $repaymentBreakdown = self::breakdownFromRepayments($receipt, $loan);
        $receiptAmount = round((float) ($receipt->amount ?? 0), 2);

        $combined = [
            // Prefer receipt-item classification (GL aligned); fallback to repayment allocation when needed.
            'principal' => $itemsBreakdown['principal'] > 0 ? $itemsBreakdown['principal'] : $repaymentBreakdown['principal'],
            'interest' => $itemsBreakdown['interest'] > 0 ? $itemsBreakdown['interest'] : $repaymentBreakdown['interest'],
            'fee_amount' => $itemsBreakdown['fee_amount'] > 0 ? $itemsBreakdown['fee_amount'] : $repaymentBreakdown['fee_amount'],
            'penalty_amount' => $itemsBreakdown['penalty_amount'] > 0 ? $itemsBreakdown['penalty_amount'] : $repaymentBreakdown['penalty_amount'],
        ];

        foreach ($combined as $key => $value) {
            $combined[$key] = round((float) $value, 2);
        }

        $combinedTotal = round($combined['principal'] + $combined['interest'] + $combined['fee_amount'] + $combined['penalty_amount'], 2);
        if ($receiptAmount > 0 && abs($combinedTotal - $receiptAmount) > 0.02) {
            // Keep row totals in sync with voucher total to avoid reconciliation drift.
            $combined['fee_amount'] = round($combined['fee_amount'] + ($receiptAmount - $combinedTotal), 2);
        }

        return $combined;
    }

    private static function breakdownFromRepayments($receipt, $loan): array
    {
        $repayments = collect(data_get($receipt, 'repayments', []));
        if ($repayments->isEmpty()) {
            return [
                'principal' => 0.0,
                'interest' => 0.0,
                'fee_amount' => 0.0,
                'penalty_amount' => 0.0,
            ];
        }

        $loanId = (int) data_get($loan, 'id', 0);
        if ($loanId > 0) {
            $repayments = $repayments->filter(function ($repayment) use ($loanId) {
                $repaymentLoanId = data_get($repayment, 'loan_id');

                // Keep backward compatibility for lightweight objects that don't carry loan_id.
                if ($repaymentLoanId === null) {
                    return true;
                }

                return (int) $repaymentLoanId === $loanId;
            })->values();
        }

        if ($repayments->isEmpty()) {
            return [
                'principal' => 0.0,
                'interest' => 0.0,
                'fee_amount' => 0.0,
                'penalty_amount' => 0.0,
            ];
        }

        return [
            'principal' => round((float) $repayments->sum('principal'), 2),
            'interest' => round((float) $repayments->sum('interest'), 2),
            'fee_amount' => round((float) $repayments->sum('fee_amount'), 2),
            'penalty_amount' => round((float) $repayments->sum('penalt_amount'), 2),
        ];
    }

    private static function asIntOrNull($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
