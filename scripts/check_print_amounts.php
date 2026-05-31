<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Models\Receipt;
use App\Models\Repayment;
use Illuminate\Support\Facades\DB;

$id = (int) ($argv[1] ?? 78);
$receipt = Receipt::with(['receiptItems', 'repayments', 'bankAccount', 'loan.customer'])->find($id);
if (!$receipt) {
    echo "Receipt #{$id} not found\n";
    exit(1);
}

echo "=== Receipt #{$receipt->id} ===\n";
echo "amount: {$receipt->amount}\n";
echo "reference: {$receipt->reference} ({$receipt->reference_type})\n";
echo "reference_number: " . ($receipt->reference_number ?? 'null') . "\n";
echo "items sum: " . $receipt->receiptItems->sum('amount') . "\n";
echo "repayments count: " . $receipt->repayments->count() . "\n";
$repaySum = $receipt->repayments->sum(fn ($r) => $r->amount_paid);
echo "repayments amount_paid sum: {$repaySum}\n";

$gl = DB::table('gl_transactions')
    ->where('transaction_id', $receipt->id)
    ->whereIn('transaction_type', ['receipt', 'receipt_reversal'])
    ->selectRaw("nature, SUM(amount) as s")
    ->groupBy('nature')
    ->pluck('s', 'nature');
echo "GL debit: " . ($gl['debit'] ?? 0) . " credit: " . ($gl['credit'] ?? 0) . "\n";

$loanKey = $argv[2] ?? null;
if ($loanKey === null && $receipt->reference_type === 'loan_repayment') {
    $loanKey = $receipt->reference;
}
$loan = null;
if ($loanKey) {
  if (is_numeric($loanKey)) {
    $loan = Loan::find($loanKey);
  } else {
    $decoded = \Vinkla\Hashids\Facades\Hashids::decode($loanKey);
    $loan = !empty($decoded) ? Loan::find($decoded[0]) : null;
  }
}
if ($loan) {
    echo "\n=== Loan {$loan->loan_no} (id {$loan->id}) recent repayments ===\n";
    foreach ($loan->repayments()->with('receipt')->latest('id')->take(5)->get() as $rp) {
        echo sprintf(
            "Repay#%d schedule=%s paid=%.2f receipt#%s receipt_amt=%.2f\n",
            $rp->id,
            $rp->loan_schedule_id,
            $rp->amount_paid,
            $rp->receipt_id ?? 'null',
            $rp->receipt?->amount ?? 0
        );
    }
}
