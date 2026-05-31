<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use Carbon\Carbon;
use Vinkla\Hashids\Facades\Hashids;

$key = $argv[1] ?? 'wpR';
$decoded = Hashids::decode($key);
$loan = !empty($decoded) ? Loan::with('schedule.repayments')->find($decoded[0]) : Loan::find($key);

if (!$loan) {
    echo "Loan not found\n";
    exit(1);
}

$today = Carbon::now();
echo "Loan #{$loan->id} {$loan->loanNo} status={$loan->status}\n";
echo "Model arrears_amount: {$loan->arrears_amount}\n";
echo "Model days_in_arrears: {$loan->days_in_arrears}\n\n";

$manualArrears = 0;
$firstOverdue = null;
foreach ($loan->schedule->sortBy('due_date') as $s) {
    if ($s->status === 'restructured') {
        continue;
    }
    $due = Carbon::parse($s->due_date);
    $rem = (float) $s->remaining_amount;
    $overdue = $due->lt($today) && $rem > 0;
    if ($overdue) {
        $manualArrears += $rem;
        if (!$firstOverdue) {
            $firstOverdue = $due;
        }
    }
    echo sprintf(
        "Sched#%s due=%s status=%s remaining=%.2f overdue=%s days_past=%s\n",
        $s->id,
        $due->toDateString(),
        $s->status,
        $rem,
        $overdue ? 'YES' : 'no',
        $overdue ? $due->diffInDays($today) : '-'
    );
}

echo "\nManual total arrears: {$manualArrears}\n";
echo 'Manual days (first overdue): ' . ($firstOverdue ? round($firstOverdue->diffInDays($today)) : 0) . "\n";
