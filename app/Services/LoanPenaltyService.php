<?php

namespace App\Services;

use App\Models\GlTransaction;
use App\Models\Loan;
use App\Models\Penalty;
use Illuminate\Support\Collection;

class LoanPenaltyService
{
    /**
     * All active penalty receivable chart account IDs.
     */
    public static function penaltyReceivableAccountIds(): array
    {
        return Penalty::query()
            ->where('status', 'active')
            ->whereNotNull('penalty_receivables_account_id')
            ->pluck('penalty_receivables_account_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Outstanding penalty total from active loan schedules (matches loan details).
     */
    public static function getTotalPenaltyBalance(?int $branchId = null): float
    {
        $query = Loan::query()
            ->where('status', Loan::STATUS_ACTIVE);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $total = 0.0;
        foreach ($query->with(['schedule.repayments'])->get() as $loan) {
            $total += $loan->getOutstandingBalanceBreakdown()['outstanding_penalty'];
        }

        return round($total, 2);
    }

    /**
     * Net penalty balance from GL receivable accounts (debit − credit).
     */
    public static function getTotalPenaltyGlBalance(?int $branchId = null): float
    {
        $accountIds = self::penaltyReceivableAccountIds();
        if (empty($accountIds)) {
            return 0.0;
        }

        $query = GlTransaction::query()
            ->whereIn('chart_account_id', $accountIds);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $totals = $query->selectRaw('
                SUM(CASE WHEN nature = "debit" THEN amount ELSE 0 END) as total_debit,
                SUM(CASE WHEN nature = "credit" THEN amount ELSE 0 END) as total_credit
            ')
            ->first();

        $debit = (float) ($totals->total_debit ?? 0);
        $credit = (float) ($totals->total_credit ?? 0);

        return round($debit - $credit, 2);
    }

    /**
     * Per-customer outstanding penalties from active loan schedules.
     */
    public static function getCustomerPenaltyBalances(?int $branchId = null): Collection
    {
        $query = Loan::query()
            ->where('status', Loan::STATUS_ACTIVE)
            ->with(['customer', 'schedule.repayments']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $byCustomer = [];

        foreach ($query->get() as $loan) {
            $penalty = $loan->getOutstandingBalanceBreakdown()['outstanding_penalty'];
            if ($penalty <= 0) {
                continue;
            }

            $customerId = (int) $loan->customer_id;
            if (!isset($byCustomer[$customerId])) {
                $byCustomer[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $loan->customer->name ?? 'Unknown Customer',
                    'customer_phone' => $loan->customer->phone1 ?? '',
                    'penalty_balance' => 0.0,
                ];
            }

            $byCustomer[$customerId]['penalty_balance'] += $penalty;
        }

        return collect($byCustomer)
            ->map(function (array $row) {
                $row['penalty_balance'] = round($row['penalty_balance'], 2);

                return $row;
            })
            ->sortBy('customer_name')
            ->values();
    }

    /**
     * Per-customer net penalty balance from GL receivable accounts.
     */
    public static function getCustomerPenaltyGlBalances(?int $branchId = null): Collection
    {
        $accountIds = self::penaltyReceivableAccountIds();
        if (empty($accountIds)) {
            return collect();
        }

        $query = GlTransaction::query()
            ->with('customer')
            ->whereIn('chart_account_id', $accountIds)
            ->whereNotNull('customer_id');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get(['customer_id', 'nature', 'amount'])
            ->groupBy('customer_id')
            ->map(function ($customerTransactions, $customerId) {
                $totalDebit = $customerTransactions->where('nature', 'debit')->sum('amount');
                $totalCredit = $customerTransactions->where('nature', 'credit')->sum('amount');

                return [
                    'customer_id' => (int) $customerId,
                    'customer_name' => $customerTransactions->first()->customer->name ?? 'Unknown Customer',
                    'customer_phone' => $customerTransactions->first()->customer->phone1 ?? '',
                    'gl_balance' => round($totalDebit - $totalCredit, 2),
                ];
            })
            ->values();
    }

    /**
     * Merge schedule outstanding and GL balances for the customer penalty list.
     */
    public static function getCustomerPenaltyComparison(?int $branchId = null): Collection
    {
        $outstanding = self::getCustomerPenaltyBalances($branchId)->keyBy('customer_id');
        $gl = self::getCustomerPenaltyGlBalances($branchId)->keyBy('customer_id');

        $customerIds = $outstanding->keys()->merge($gl->keys())->unique();

        return $customerIds->map(function ($customerId) use ($outstanding, $gl) {
            $outRow = $outstanding->get($customerId);
            $glRow = $gl->get($customerId);
            $outAmount = (float) ($outRow['penalty_balance'] ?? 0);
            $glAmount = (float) ($glRow['gl_balance'] ?? 0);

            return [
                'customer_id' => (int) $customerId,
                'customer_name' => $outRow['customer_name'] ?? $glRow['customer_name'] ?? 'Unknown Customer',
                'customer_phone' => $outRow['customer_phone'] ?? $glRow['customer_phone'] ?? '',
                'penalty_balance' => round($outAmount, 2),
                'gl_balance' => round($glAmount, 2),
                'difference' => round($glAmount - $outAmount, 2),
            ];
        })
            ->filter(fn (array $row) => $row['penalty_balance'] > 0 || $row['gl_balance'] > 0)
            ->sortBy('customer_name')
            ->values();
    }
}
