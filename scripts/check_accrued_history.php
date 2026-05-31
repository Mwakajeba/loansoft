<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AccruedPenalty;

$id = (int) ($argv[1] ?? 277);
$rows = AccruedPenalty::where('loan_schedule_id', $id)->orderBy('id')->get(['id', 'penalty_amount', 'accrual_date', 'reversed_at']);
echo "sched#{$id} accrued rows: " . $rows->count() . "\n";
foreach ($rows as $r) {
    echo "  #{$r->id} amt={$r->penalty_amount} date={$r->accrual_date} reversed=" . ($r->reversed_at ?? 'no') . "\n";
}
