<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Support\Loans\LoanReportRowBuilder;

Loan::syncActiveLoansEligibleForCompletion();
$loan = Loan::with(['customer', 'branch', 'group', 'loanOfficer', 'schedule.repayments'])
    ->where('status', 'active')
    ->first();

if (!$loan) {
    echo "No active loan\n";
    exit(0);
}

$start = now()->startOfMonth()->toDateString();
$end = now()->toDateString();

echo "Loan: {$loan->loanNo}\n";
echo "Expected vs Collected: " . json_encode(LoanReportRowBuilder::expectedVsCollectedRow($loan, $start, $end)) . "\n";
echo "Aging: " . json_encode(LoanReportRowBuilder::agingRow($loan, $end)) . "\n";
echo "Arrears: " . json_encode(LoanReportRowBuilder::arrearsRow($loan, $end)) . "\n";
echo "Portfolio: " . json_encode(LoanReportRowBuilder::portfolioRow($loan, $end)) . "\n";
echo "Outstanding: " . json_encode(LoanReportRowBuilder::outstandingRow($loan, $end)) . "\n";
echo "OK\n";
