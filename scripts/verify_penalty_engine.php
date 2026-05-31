<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AccruedPenalty;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Penalty;
use App\Services\PenaltyAccrualService;
use Carbon\Carbon;
use Vinkla\Hashids\Facades\Hashids;

echo "=== PENALTY ENGINE VERIFICATION ===\n\n";

// --- 1) Loan wpR post-fix ---
$key = $argv[1] ?? 'wpR';
$decoded = Hashids::decode($key);
$loan = !empty($decoded) ? Loan::with(['product', 'schedule.repayments'])->find($decoded[0]) : null;

if ($loan) {
    $penalty = PenaltyAccrualService::forDate()->resolvePenaltyForLoan($loan);
    $svc = PenaltyAccrualService::forDate();
    echo "--- Loan {$loan->loanNo} (#{$loan->id}) ---\n";
    echo "Product grace: " . ($loan->product->grace_period ?? 0) . " days\n";
    if ($penalty) {
        echo "Penalty: {$penalty->name} | {$penalty->penalty_type} {$penalty->amount} | {$penalty->charge_frequency} | {$penalty->deduction_type}\n";
    }

    $issues = 0;
    foreach ($loan->schedule->sortBy('due_date') as $s) {
        if ($s->status === 'restructured') {
            continue;
        }
        $due = Carbon::parse($s->due_date)->startOfDay();
        if ($due->gte(Carbon::today()) || (float) $s->remaining_amount <= 0) {
            continue;
        }
        if (!$penalty) {
            continue;
        }

        $graceEnd = $svc->getGraceEndDate($loan, $s);
        $base = $svc->calculatePenaltyBase($loan, $s, $penalty->deduction_type);
        $days = $svc->daysOverdueAfterGrace($loan, $s);
        $expected = $svc->calculatePenaltyAmount($base, $penalty, $days);
        $accrued = (float) AccruedPenalty::where('loan_schedule_id', $s->id)->whereNull('reversed_at')->sum('penalty_amount');
        $schedPen = (float) $s->penalty_amount;

        $ok = abs($accrued - $expected) <= 0.02 && abs($schedPen - $accrued) <= 0.02;
        if (!$ok) {
            $issues++;
            echo "  FAIL sched#{$s->id}: expected={$expected} accrued={$accrued} schedule.penalty={$schedPen} base={$base} grace_end={$graceEnd->toDateString()}\n";
        } else {
            echo "  OK   sched#{$s->id}: penalty={$expected} (base={$base}, days_od={$days})\n";
        }
    }
    echo $issues ? "  => {$issues} schedule issue(s)\n\n" : "  => All overdue schedules match settings\n\n";
}

// --- 2) Matrix: all setting combinations (dry calculation) ---
echo "--- Calculation matrix (no DB writes) ---\n";
$base = 100000.0;
$days = 10;

$matrix = [
    ['percentage', 'one_time', 'monthly', 5.0],
    ['percentage', 'daily', 'monthly', 5.0],
    ['percentage', 'daily', 'daily', 0.5],
    ['fixed', 'one_time', 'monthly', 5000.0],
    ['fixed', 'daily', 'monthly', 3000.0],
    ['fixed', 'daily', 'weekly', 700.0],
];

foreach ($matrix as [$type, $freq, $cycle, $amount]) {
    $p = new Penalty([
        'penalty_type' => $type,
        'charge_frequency' => $freq,
        'frequency_cycle' => $cycle,
        'amount' => $amount,
        'deduction_type' => 'over_due_principal_and_interest',
        'status' => 'active',
    ]);
    $svc = PenaltyAccrualService::forDate();
    $amt = $svc->calculatePenaltyAmount($base, $p, $days);
    echo sprintf(
        "  %s | %s | cycle=%s | rate/amt=%s => TZS %s\n",
        $type,
        $freq,
        $cycle,
        number_format($amount, 2),
        number_format($amt, 2)
    );
}

// --- 3) Active loans with penalty config issues ---
echo "\n--- Active loans penalty config audit ---\n";
$configIssues = 0;
$loans = Loan::where('status', 'active')->with('product')->get();
foreach ($loans as $l) {
    $svc = PenaltyAccrualService::forDate();
    $p = $svc->resolvePenaltyForLoan($l);
    if (!$p) {
        if (!empty($l->product?->penalty_ids)) {
            $configIssues++;
            echo "  WARN loan {$l->loanNo}: penalty_ids set but no active penalty\n";
        }
        continue;
    }
    if (!$p->penalty_receivables_account_id || !$p->penalty_income_account_id) {
        $configIssues++;
        echo "  FAIL loan {$l->loanNo}: penalty #{$p->id} missing GL accounts\n";
    }
}
echo $configIssues ? "  => {$configIssues} config issue(s)\n" : "  => All active loans with penalties have valid GL\n";

// --- 4) Orphan / mismatch accrued vs schedule ---
echo "\n--- Accrued vs schedule.penalty_amount (active loans) ---\n";
$mismatches = 0;
$schedules = LoanSchedule::whereHas('loan', fn ($q) => $q->where('status', 'active'))
    ->where('penalty_amount', '>', 0)
    ->get(['id', 'loan_id', 'penalty_amount']);

foreach ($schedules as $s) {
    $sum = (float) AccruedPenalty::where('loan_schedule_id', $s->id)->whereNull('reversed_at')->sum('penalty_amount');
    if (abs($sum - (float) $s->penalty_amount) > 0.05) {
        $mismatches++;
        echo "  MISMATCH sched#{$s->id}: accrued_sum={$sum} schedule={$s->penalty_amount}\n";
    }
}
echo $mismatches ? "  => {$mismatches} mismatch(es)\n" : "  => schedule.penalty_amount matches accrued_penalties\n";

// --- 5) Negative days_overdue in recent accruals ---
$negDays = AccruedPenalty::whereNull('reversed_at')->where('days_overdue', '<', 0)->count();
echo "\n--- days_overdue < 0 (non-reversed): {$negDays} " . ($negDays === 0 ? 'OK' : 'FAIL') . "\n";

echo "\nDone.\n";
