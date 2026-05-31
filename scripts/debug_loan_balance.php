<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$loan = App\Models\Loan::with('schedule.repayments')->find((int) ($argv[1] ?? 1));
$b = $loan->getOutstandingBalanceBreakdown();
$scheduleDue = $loan->schedule->sum(fn ($s) => ($s->principal ?? 0) + ($s->interest ?? 0) + ($s->fee_amount ?? 0));
$paidAmount = $loan->schedule->sum(fn ($s) => $s->repayments->sum('amount'));
$paidComponents = $loan->schedule->sum(fn ($s) => $s->repayments->sum(fn ($r) => $r->principal + $r->interest + ($r->fee_amount ?? 0) + ($r->penalt_amount ?? 0)));

echo json_encode([
    'loan_id' => $loan->id,
    'amount_total' => $loan->amount_total,
    'schedule_due' => $scheduleDue,
    'paid_amount' => $paidAmount,
    'paid_components' => $paidComponents,
    'old_outstanding_due_minus_paid' => $scheduleDue - $paidAmount,
    'old_outstanding_due_minus_components' => $scheduleDue - $paidComponents,
    'breakdown' => $b,
    'arrears_amount' => $loan->arrears_amount,
    'days_in_arrears' => $loan->days_in_arrears,
], JSON_PRETTY_PRINT) . PHP_EOL;
