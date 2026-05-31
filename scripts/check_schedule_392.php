<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\LoanSchedule::with('repayments')->find(392);
echo json_encode([
    'principal' => $s->principal,
    'interest' => $s->interest,
    'accrued_interest' => $s->accrued_interest,
    'fee_amount' => $s->fee_amount,
    'penalty_amount' => $s->penalty_amount,
    'remaining_amount' => $s->remaining_amount,
    'end_grace_date' => $s->end_grace_date,
], JSON_PRETTY_PRINT) . "\n";
