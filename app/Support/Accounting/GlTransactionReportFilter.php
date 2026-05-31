<?php

namespace App\Support\Accounting;

use Illuminate\Database\Query\Builder;

/**
 * Excludes GL rows tied to reversed/deleted loan repayment receipts or soft-deleted repayments.
 */
class GlTransactionReportFilter
{
    public const RECEIPT_TRANSACTION_TYPES = ['receipt', 'receipt_reversal'];

    public const REPAYMENT_TRANSACTION_TYPES = [
        'Settle Interest',
        'Settle Principal',
        'journal repayment',
    ];

    public const PAYMENT_TRANSACTION_TYPES = ['payment'];

    public static function apply(Builder $query, string $glTable = 'gl_transactions'): Builder
    {
        return $query->whereNot(function (Builder $exclude) use ($glTable) {
            $exclude->where(function (Builder $q) use ($glTable) {
                $q->whereIn("{$glTable}.transaction_type", self::RECEIPT_TRANSACTION_TYPES)
                    ->whereNotExists(function (Builder $sub) use ($glTable) {
                        $sub->selectRaw('1')
                            ->from('receipts')
                            ->whereColumn('receipts.id', "{$glTable}.transaction_id")
                            ->whereNull('receipts.deleted_at');
                    });
            })->orWhere(function (Builder $q) use ($glTable) {
                $q->whereIn("{$glTable}.transaction_type", self::REPAYMENT_TRANSACTION_TYPES)
                    ->whereNotExists(function (Builder $sub) use ($glTable) {
                        $sub->selectRaw('1')
                            ->from('repayments')
                            ->whereColumn('repayments.id', "{$glTable}.transaction_id")
                            ->whereNull('repayments.deleted_at');
                    });
            })->orWhere(function (Builder $q) use ($glTable) {
                $q->whereIn("{$glTable}.transaction_type", self::PAYMENT_TRANSACTION_TYPES)
                    ->whereNotExists(function (Builder $sub) use ($glTable) {
                        $sub->selectRaw('1')
                            ->from('payments')
                            ->whereColumn('payments.id', "{$glTable}.transaction_id");
                    });
            });
        });
    }
}
