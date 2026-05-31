<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Support\Accounting\GlReportQuery;
use App\Support\Accounting\GlTransactionReportFilter;
use Illuminate\Support\Facades\DB;

$user = User::first();
if (!$user) {
    echo "No user found\n";
    exit(1);
}

$companyId = $user->company_id;
$start = now()->startOfYear()->format('Y-m-d');
$end = now()->format('Y-m-d');

$chartIds = GlReportQuery::bankChartAccountIds($user, $companyId, 'all', 'all');

$obQ = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
    ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
    ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
    ->where('account_class_groups.company_id', $companyId)
    ->whereIn('gl_transactions.chart_account_id', $chartIds);
GlReportQuery::applyOpeningBefore($obQ, $start);
$opening = (float) ($obQ->selectRaw('
    COALESCE(SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END), 0) -
    COALESCE(SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END), 0) as ob
')->value('ob') ?? 0);

$periodQ = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
    ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
    ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
    ->where('account_class_groups.company_id', $companyId)
    ->whereIn('gl_transactions.chart_account_id', $chartIds);
GlReportQuery::applyDateRange($periodQ, $start, $end);
$period = $periodQ->selectRaw('
    COALESCE(SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END), 0) as dr,
    COALESCE(SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END), 0) as cr
')->first();

$closing = $opening + (float) $period->dr - (float) $period->cr;

$orphanReceipts = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
    ->whereIn('transaction_type', ['receipt', 'receipt_reversal'])
    ->whereNotExists(function ($sub) {
        $sub->selectRaw('1')->from('receipts')->whereColumn('receipts.id', 'gl_transactions.transaction_id')->whereNull('receipts.deleted_at');
    })->count();

$orphanPayments = DB::table('gl_transactions')
    ->where('transaction_type', 'payment')
    ->whereNotExists(function ($sub) {
        $sub->selectRaw('1')->from('payments')->whereColumn('payments.id', 'gl_transactions.transaction_id');
    })->count();

echo json_encode([
    'period' => [$start, $end],
    'bank_chart_accounts' => count($chartIds),
    'cash_book' => [
        'opening' => round($opening, 2),
        'debit' => round((float) $period->dr, 2),
        'credit' => round((float) $period->cr, 2),
        'closing' => round($closing, 2),
    ],
    'orphan_gl_rows_excluded_by_filter' => [
        'receipts' => $orphanReceipts,
        'payments' => $orphanPayments,
    ],
], JSON_PRETTY_PRINT) . PHP_EOL;
