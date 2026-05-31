<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Accounting\Reports\CashBookReportController;
use App\Http\Controllers\Accounting\Reports\GeneralLedgerReportController;
use App\Models\User;
use App\Support\Accounting\GlReportQuery;
use App\Support\Accounting\GlTransactionReportFilter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = User::first();
if (!$user) {
    echo "No user\n";
    exit(1);
}

Auth::login($user);
$companyId = $user->company_id;
$start = now()->startOfYear()->format('Y-m-d');
$end = now()->format('Y-m-d');

$issues = [];
$checks = [];

$cashCtrl = app(CashBookReportController::class);
$ref = new ReflectionMethod($cashCtrl, 'getCashBookData');
$ref->setAccessible(true);
$cashData = $ref->invoke($cashCtrl, $start, $end, 'all', 'all');

$ob = $cashData['opening_balance'];
$dr = $cashData['total_receipts'];
$cr = $cashData['total_payments'];
$closing = $cashData['final_balance'];
$calcClosing = round($ob + $dr - $cr, 2);

if (abs($calcClosing - $closing) > 0.01) {
    $issues[] = "Cash Book: opening + debit - credit != final_balance ({$calcClosing} vs {$closing})";
} else {
    $checks[] = 'Cash Book: opening + movement = closing OK';
}

$expected = $ob;
$rowOk = true;
foreach ($cashData['transactions'] as $i => $tx) {
    $expected = round($expected + $tx['debit'] - $tx['credit'], 2);
    if (abs($expected - $tx['balance']) > 0.01) {
        $issues[] = "Cash Book row #{$i}: balance mismatch (expected {$expected}, got {$tx['balance']})";
        $rowOk = false;
        break;
    }
}
if ($rowOk) {
    $checks[] = 'Cash Book: per-row running balances OK';
}

$chartIds = GlReportQuery::bankChartAccountIds($user, $companyId, 'all', 'all');
$glClosingQ = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
    ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
    ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
    ->where('account_class_groups.company_id', $companyId)
    ->whereIn('gl_transactions.chart_account_id', $chartIds);
$glClosing = (float) $glClosingQ->selectRaw('
    COALESCE(SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END), 0) -
    COALESCE(SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END), 0) as bal
')->value('bal');

if (abs($glClosing - $closing) > 0.01) {
    $issues[] = "Cash Book closing ({$closing}) != all-time GL bank balance ({$glClosing})";
} else {
    $checks[] = 'Cash Book closing matches all-time GL bank accounts OK';
}

$glCtrl = app(GeneralLedgerReportController::class);
$glRef = new ReflectionMethod($glCtrl, 'getGeneralLedgerData');
$glRef->setAccessible(true);
$glData = $glRef->invoke($glCtrl, $start, $end, 'accrual', null, 'all', true, 'account');

$glOk = true;
$byAccount = collect($glData['transactions'])->groupBy('chart_account_id');
foreach ($byAccount as $accountId => $rows) {
    $opening = $glData['opening_balances']->get($accountId);
    $openBal = $opening
        ? GlReportQuery::netBalance((float) $opening->total_debit, (float) $opening->total_credit)
        : 0.0;

    $running = $openBal;
    foreach ($rows as $row) {
        $running += GlReportQuery::signedMovement($row->nature, (float) $row->amount);
        if (abs(round($running, 2) - (float) $row->running_balance) > 0.01) {
            $issues[] = "GL account {$accountId}: running balance mismatch on tx {$row->id}";
            $glOk = false;
            break 2;
        }
    }
}
if ($glOk) {
    $checks[] = 'General Ledger: per-account running balances OK';
}

$unbalanced = DB::select("
    SELECT transaction_type, transaction_id,
           SUM(CASE WHEN nature='debit' THEN amount ELSE 0 END) as dr,
           SUM(CASE WHEN nature='credit' THEN amount ELSE 0 END) as cr
    FROM gl_transactions
    GROUP BY transaction_type, transaction_id
    HAVING ABS(dr - cr) > 0.01
    LIMIT 5
");
if (count($unbalanced) > 0) {
    foreach ($unbalanced as $u) {
        $issues[] = "Unbalanced voucher {$u->transaction_type}-{$u->transaction_id}: dr={$u->dr} cr={$u->cr}";
    }
} else {
    $checks[] = 'All GL vouchers balanced OK';
}

$branches = $user->branches()->where('branches.company_id', $companyId)->get();
$normalized = GlReportQuery::normalizeBranchId($user, 'all', $branches);
if ($branches->count() <= 1 && $normalized !== 'all') {
    $exportData = $ref->invoke($cashCtrl, $start, $end, 'all', 'all');
    $screenData = $exportData;
    $checks[] = "Branch normalized to {$normalized} for single-branch company";
}

echo "=== GL Reports Audit ({$start} to {$end}) ===\n\n";
foreach ($checks as $c) {
    echo "[OK] {$c}\n";
}
if (!empty($issues)) {
    echo "\nISSUES:\n";
    foreach ($issues as $i) {
        echo "[!!] {$i}\n";
    }
} else {
    echo "\nNo issues found.\n";
}

echo "\nCash Book: opening={$ob}, debit={$dr}, credit={$cr}, closing={$closing}, txs=" . count($cashData['transactions']) . "\n";
echo "GL: txs={$glData['summary']['transaction_count']}, accounts={$glData['summary']['account_count']}\n";

exit(count($issues) > 0 ? 1 : 0);
