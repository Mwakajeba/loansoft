<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Support\Loans\LoanFeeMetrics;
use App\Support\Loans\LoanReportMetrics;
use App\Support\Loans\LoanReportRowBuilder;
use Vinkla\Hashids\Facades\Hashids;

$decoded = Hashids::decode('wpR');
$loanId = $decoded[0] ?? null;

if (!$loanId) {
    echo "Could not decode wpR\n";
    exit(1);
}

$loan = Loan::with(LoanReportMetrics::eagerLoads())->find($loanId);
if (!$loan) {
    echo "Loan not found: {$loanId}\n";
    exit(1);
}

echo "Loan: {$loan->loanNo} (id {$loan->id})\n";
echo "Receipts count: " . $loan->receipts->count() . "\n";
echo "Fees from receipts: " . LoanFeeMetrics::feesPaidFromReceipts($loan) . "\n";
echo "Fees from repayments: " . LoanFeeMetrics::feesPaidFromRepayments($loan) . "\n";
echo "Total fees paid (LoanFeeMetrics): " . LoanFeeMetrics::totalFeesPaid($loan) . "\n";

$paid = LoanReportMetrics::paidBreakdownAsOf($loan, now()->format('Y-m-d'));
echo "Report paidBreakdown fees: {$paid['fees']}\n";

$row = LoanReportRowBuilder::outstandingRow($loan, now()->format('Y-m-d'));
echo "Outstanding report fees_paid: " . ($row['fees_paid'] ?? 'null row') . "\n";
echo "Outstanding report outstanding_fees: " . ($row['outstanding_fees'] ?? 'n/a') . "\n";
