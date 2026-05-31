<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use Vinkla\Hashids\Facades\Hashids;

$key = $argv[1] ?? 'wpR';
$decoded = Hashids::decode($key);
$loan = !empty($decoded) ? Loan::with('product')->find($decoded[0]) : Loan::with('product')->find($key);

if (!$loan || !$loan->product) {
    echo "Loan or product not found\n";
    exit(1);
}

$p = $loan->product;
echo "Loan #{$loan->id} product: {$p->name}\n";
echo "  amount {$loan->amount} in [" . $p->minimum_principal . ", " . $p->maximum_principal . "] "
    . ($p->isAmountWithinLimits((float) $loan->amount) ? 'OK' : 'FAIL') . "\n";
echo "  period {$loan->period} in [" . $p->minimum_period . ", " . $p->maximum_period . "] "
    . ($p->isPeriodWithinLimits((int) $loan->period) ? 'OK' : 'FAIL') . "\n";
echo "  interest {$loan->interest}% in [" . $p->minimum_interest_rate . ", " . $p->maximum_interest_rate . "] "
    . ($loan->interest >= $p->minimum_interest_rate && $loan->interest <= $p->maximum_interest_rate ? 'OK' : 'FAIL') . "\n";
echo "  interest_cycle loan={$loan->interest_cycle} product={$p->interest_cycle}\n";
echo "  interest_method product={$p->interest_method}\n";
echo "  grace_period={$p->grace_period} penalt_deduction={$p->penalt_deduction_criteria}\n";
echo "  repayment_order=" . (is_array($p->repayment_order) ? implode(',', $p->repayment_order) : $p->repayment_order) . "\n";
echo "  fees_ids=" . json_encode($p->fees_ids) . " penalty_ids=" . json_encode($p->penalty_ids) . "\n";
