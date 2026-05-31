<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'GD loaded: ' . (extension_loaded('gd') ? 'yes' : 'no') . PHP_EOL;

$rows = [
    ['group' => '2025-05-01', 'customer_name' => 'Test', 'loan_officer' => 'Officer', 'loan_product' => 'Product',
     'loan_account_no' => 'SF-1', 'disbursement_date' => '2025-05-01', 'maturity_date' => '2026-05-01',
     'amount_disbursed' => 1000, 'interest' => 100, 'total_amount' => 1100, 'principal_paid' => 500,
     'interest_paid' => 50, 'penalties_paid' => 0, 'outstanding_principal' => 500, 'outstanding_interest' => 50,
     'amount_overdue' => 0, 'days_in_arrears' => 0, 'loan_status' => 'active'],
];

$pdf = \PDF::loadView('loans.reports.portfolio_tracking_pdf', [
    'rows' => $rows,
    'fromDate' => '2026-05-01',
    'toDate' => '2026-05-25',
    'groupBy' => 'day',
    'company' => \App\Models\Company::first(),
])->setPaper('A3', 'landscape');

$output = $pdf->output();
echo 'PDF bytes: ' . strlen($output) . PHP_EOL;
