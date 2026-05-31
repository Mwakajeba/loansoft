<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AccruedPenalty;
use App\Models\LoanSchedule;
use App\Services\PenaltyAccrualService;

$ids = array_slice($argv, 1) ?: [392, 393, 394, 395, 396, 55];
$svc = PenaltyAccrualService::forDate();

foreach ($ids as $id) {
    $s = LoanSchedule::with(['loan.product', 'repayments'])->find($id);
    if (!$s) {
        echo "sched#{$id} not found\n";
        continue;
    }
    $loan = $s->loan;
    $penalty = $svc->resolvePenaltyForLoan($loan);
    $sum = (float) AccruedPenalty::where('loan_schedule_id', $id)->whereNull('reversed_at')->sum('penalty_amount');
    $expected = 0;
    if ($penalty && !$svc->isWithinGracePeriod($loan, $s)) {
        $base = $svc->calculatePenaltyBase($loan, $s, $penalty->deduction_type);
        $days = $svc->daysOverdueAfterGrace($loan, $s);
        $expected = $svc->calculatePenaltyAmount($base, $penalty, $days);
    }
    echo sprintf(
        "sched#%d loan#%d (%s) schedule.penalty=%.2f accrued=%.2f expected=%.2f\n",
        $id,
        $loan->id,
        $loan->loanNo ?? '',
        (float) $s->penalty_amount,
        $sum,
        $expected
    );
}
