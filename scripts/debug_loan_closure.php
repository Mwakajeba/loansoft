<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Support\Loans\LoanReportMetrics;

$threshold = 20.0;
$active = Loan::where('status', 'active')->with('schedule.repayments')->get();
$shouldComplete = [];

foreach ($active as $loan) {
    $bal = $loan->getOutstandingBalanceBreakdown()['total_balance'];
    if ($bal < $threshold) {
        $shouldComplete[] = [
            'id' => $loan->id,
            'loanNo' => $loan->loanNo,
            'balance' => $bal,
            'status' => $loan->status,
        ];
    }
}

echo 'Active loans with outstanding < ' . $threshold . ': ' . count($shouldComplete) . PHP_EOL;
echo json_encode(array_slice($shouldComplete, 0, 15), JSON_PRETTY_PRINT) . PHP_EOL;
