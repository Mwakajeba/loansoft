<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Support\Loans\LoanFeeMetrics;
use App\Support\Loans\LoanReportMetrics;

$loan = Loan::with(['product', 'repayments', 'receipts.receiptItems.fee', 'schedule.repayments'])
    ->whereHas('receipts', fn ($q) => $q->where('reference_type', 'loan'))
    ->first();

if (!$loan) {
    echo "No loan with fee receipts found\n";
    exit(0);
}

echo "Loan: {$loan->loanNo}\n";
echo "Fees from repayments: " . LoanFeeMetrics::feesPaidFromReceipts($loan) . " (receipts) + " . LoanFeeMetrics::feesPaidFromRepayments($loan) . " (repayments)\n";
echo "Total fees paid: " . LoanFeeMetrics::totalFeesPaid($loan) . "\n";
echo "Configured fees total: " . LoanFeeMetrics::totalConfiguredFees($loan) . "\n";
echo "Outstanding configured: " . LoanFeeMetrics::outstandingConfiguredFees($loan) . "\n";
$paid = LoanReportMetrics::paidBreakdown($loan);
echo "Report paid fees: {$paid['fees']}\n";
