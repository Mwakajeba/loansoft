<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Models\AccruedPenalty;
use App\Models\Penalty;
use Carbon\Carbon;
use Vinkla\Hashids\Facades\Hashids;

$key = $argv[1] ?? 'wpR';
$decoded = Hashids::decode($key);
$loan = !empty($decoded) ? Loan::with(['product', 'schedule.repayments'])->find($decoded[0]) : Loan::with(['product', 'schedule.repayments'])->find($key);

if (!$loan || !$loan->product) {
    echo "Loan not found\n";
    exit(1);
}

$today = Carbon::today();
$product = $loan->product;
$penalty = $product->penalty;

echo "=== Loan #{$loan->id} {$loan->loanNo} ===\n";
echo "Product grace_period (days): " . ($product->grace_period ?? 0) . "\n";

if ($penalty) {
    echo "\n=== Penalty config (accounting/penalties) ID {$penalty->id} ===\n";
    echo "name: {$penalty->name}\n";
    echo "type: {$penalty->penalty_type} amount: {$penalty->amount}\n";
    echo "charge_frequency: {$penalty->charge_frequency}\n";
    echo "frequency_cycle: " . ($penalty->frequency_cycle ?? 'monthly') . "\n";
    echo "deduction_type: {$penalty->deduction_type}\n";
    echo "penalty_limit_days: " . ($penalty->penalty_limit_days ?? 'null') . "\n";
    echo "status: {$penalty->status}\n";
} else {
    echo "NO penalty linked to product (penalty_ids: " . json_encode($product->penalty_ids) . ")\n";
}

echo "\n=== Schedules (overdue) ===\n";
foreach ($loan->schedule->sortBy('due_date') as $s) {
    if ($s->status === 'restructured') {
        continue;
    }
    $due = Carbon::parse($s->due_date);
    $graceEnd = $s->end_grace_date
        ? Carbon::parse($s->end_grace_date)
        : $due->copy()->addDays($product->grace_period ?? 0);
    $inGrace = $today->lte($graceEnd);
    $penaltyAmt = (float) $s->penalty_amount;
    $accruedCount = AccruedPenalty::where('loan_schedule_id', $s->id)->whereNull('reversed_at')->count();
    $firstAccrual = AccruedPenalty::where('loan_schedule_id', $s->id)->whereNull('reversed_at')->orderBy('accrual_date')->first();

    if ($due->lt($today) && (float) $s->remaining_amount > 0) {
        echo sprintf(
            "Sched#%s due=%s grace_end=%s in_grace_today=%s penalty_on_sched=%.2f accrued_rows=%d first_accrual=%s\n",
            $s->id,
            $due->toDateString(),
            $graceEnd->toDateString(),
            $inGrace ? 'YES' : 'NO',
            $penaltyAmt,
            $accruedCount,
            $firstAccrual?->accrual_date?->toDateString() ?? '-'
        );
        if ($firstAccrual) {
            $expectedStart = $graceEnd->copy()->addDay()->toDateString();
            $actual = $firstAccrual->accrual_date->toDateString();
            $ok = Carbon::parse($actual)->gte($graceEnd->copy()->addDay()) ? 'OK' : 'CHECK';
            echo "  -> first penalty should be on/after {$expectedStart}, got {$actual} [{$ok}]\n";
        } elseif ($inGrace) {
            echo "  -> no penalty yet (still in grace) OK\n";
        } else {
            echo "  -> overdue past grace but no accrual rows — run AccruePenaltyJob?\n";
        }
    }
}

echo "\n=== Schedule 392 paid vs due (penalty job logic) ===\n";
$s392 = $loan->schedule->firstWhere('id', 392);
if ($s392) {
    $paid = $s392->repayments->sum(fn ($r) => ($r->principal ?? 0) + ($r->interest ?? 0) + ($r->fee_amount ?? 0) + ($r->penalt_amount ?? 0));
    $int = $s392->accrued_interest ?? $s392->interest ?? 0;
    $due = ($s392->principal ?? 0) + $int + ($s392->fee_amount ?? 0) + ($s392->penalty_amount ?? 0);
    $unpaidP = max(0, $s392->principal - $s392->repayments->sum('principal'));
    $unpaidI = max(0, $int - $s392->repayments->sum('interest'));
    echo "paid={$paid} due={$due} remaining={$s392->remaining_amount} unpaidPI=" . ($unpaidP + $unpaidI) . "\n";
    if ($penalty && $penalty->penalty_type === 'percentage') {
        $base = $unpaidP + $unpaidI;
        $oneTime = round($base * $penalty->amount / 100, 2);
        echo "expected one_time penalty (1% of base): {$oneTime}\n";
    }
}

echo "\n=== Recent accrued_penalties (loan) ===\n";
foreach (AccruedPenalty::where('loan_id', $loan->id)->whereNull('reversed_at')->orderBy('accrual_date')->take(10)->get() as $ap) {
    echo sprintf(
        "id=%s sched=%s date=%s amount=%.2f days_overdue=%s type=%s rate=%s\n",
        $ap->id,
        $ap->loan_schedule_id,
        $ap->accrual_date->toDateString(),
        $ap->penalty_amount,
        $ap->days_overdue,
        $ap->penalty_type,
        $ap->penalty_rate
    );
}
