<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Receipt GL balance audit ===\n\n";

$unbalanced = DB::table('gl_transactions')
    ->select('transaction_id')
    ->selectRaw("SUM(CASE WHEN nature = 'debit' THEN amount ELSE 0 END) as debit_total")
    ->selectRaw("SUM(CASE WHEN nature = 'credit' THEN amount ELSE 0 END) as credit_total")
    ->whereIn('transaction_type', ['receipt', 'receipt_reversal'])
    ->groupBy('transaction_id')
    ->havingRaw('ABS(debit_total - credit_total) > 0.02')
    ->get();

echo 'Unbalanced receipt/reversal groups: ' . $unbalanced->count() . "\n";
foreach ($unbalanced->take(15) as $row) {
    $receipt = DB::table('receipts')->where('id', $row->transaction_id)->first();
    $status = $receipt ? ($receipt->deleted_at ? 'soft-deleted' : 'active') : 'orphan txn_id';
    echo sprintf(
        "  Receipt #%s [%s] debit=%.2f credit=%.2f diff=%.2f\n",
        $row->transaction_id,
        $status,
        $row->debit_total,
        $row->credit_total,
        $row->debit_total - $row->credit_total
    );
}

echo "\n=== Receipt amount vs bank debit (active receipts) ===\n\n";

$mismatch = DB::select("
    SELECT r.id, r.amount, r.deleted_at,
        COALESCE(SUM(CASE WHEN g.nature = 'debit' AND g.transaction_type = 'receipt' THEN g.amount END), 0) as bank_debit
    FROM receipts r
    LEFT JOIN gl_transactions g ON g.transaction_id = r.id AND g.transaction_type IN ('receipt', 'receipt_reversal')
    WHERE r.deleted_at IS NULL
    GROUP BY r.id, r.amount, r.deleted_at
    HAVING ABS(r.amount - bank_debit) > 0.02
    LIMIT 20
");

echo 'Amount vs debit mismatches: ' . count($mismatch) . "\n";
foreach ($mismatch as $m) {
    echo sprintf("  Receipt #%d amount=%.2f bank_debit=%.2f\n", $m->id, $m->amount, $m->bank_debit);
}

echo "\n=== Orphan receipt GL (no active receipt) ===\n";
$orphan = DB::table('gl_transactions as g')
    ->leftJoin('receipts as r', 'r.id', '=', 'g.transaction_id')
    ->whereIn('g.transaction_type', ['receipt', 'receipt_reversal'])
    ->where(function ($q) {
        $q->whereNull('r.id')->orWhereNotNull('r.deleted_at');
    })
    ->count();
echo "GL rows on missing/deleted receipts: {$orphan}\n";

echo "\n=== All GL groups (by type + transaction_id) unbalanced ===\n";
$allUnbalanced = DB::table('gl_transactions')
    ->select('transaction_type', 'transaction_id')
    ->selectRaw("SUM(CASE WHEN nature = 'debit' THEN amount ELSE 0 END) as debit_total")
    ->selectRaw("SUM(CASE WHEN nature = 'credit' THEN amount ELSE 0 END) as credit_total")
    ->groupBy('transaction_type', 'transaction_id')
    ->havingRaw('ABS(debit_total - credit_total) > 0.02')
    ->get();
echo 'Unbalanced groups (all types): ' . $allUnbalanced->count() . "\n";
foreach ($allUnbalanced->take(20) as $row) {
    echo sprintf(
        "  %s #%s debit=%.2f credit=%.2f diff=%.2f\n",
        $row->transaction_type,
        $row->transaction_id,
        $row->debit_total,
        $row->credit_total,
        $row->debit_total - $row->credit_total
    );
}

echo "\nDone.\n";
