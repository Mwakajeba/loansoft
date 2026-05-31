<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DailyInterestAccrual;
use App\Models\GlTransaction;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Support\Loans\InterestAccrualMethod;

echo "=== INTEREST ACCRUAL VERIFICATION ===\n\n";

$issues = 0;

$dailyProducts = \App\Models\LoanProduct::whereIn('penalt_deduction_criteria', [
    InterestAccrualMethod::DAILY,
    InterestAccrualMethod::DAILY_LEGACY,
])->count();
$expectedProducts = \App\Models\LoanProduct::whereIn('penalt_deduction_criteria', [
    InterestAccrualMethod::AS_EXPECTED,
    InterestAccrualMethod::AS_EXPECTED_LEGACY,
])->count();

echo "Products: Daily={$dailyProducts}, As Expected={$expectedProducts}\n\n";

// Active loans sample by method
foreach ([true, false] as $isDaily) {
    $label = $isDaily ? 'DAILY' : 'AS EXPECTED';
    echo "--- {$label} loans (active, max 5) ---\n";

    $loans = Loan::where('status', 'active')
        ->whereHas('product', function ($q) use ($isDaily) {
            if ($isDaily) {
                $q->whereIn('penalt_deduction_criteria', [InterestAccrualMethod::DAILY, InterestAccrualMethod::DAILY_LEGACY]);
            } else {
                $q->whereIn('penalt_deduction_criteria', [InterestAccrualMethod::AS_EXPECTED, InterestAccrualMethod::AS_EXPECTED_LEGACY]);
            }
        })
        ->with(['product', 'schedule.repayments'])
        ->limit(5)
        ->get();

    foreach ($loans as $loan) {
        $loan->schedule->each(fn ($s) => $s->setRelation('loan', $loan));
        $schedIssues = 0;
        foreach ($loan->schedule->sortBy('due_date') as $s) {
            $due = (float) $s->balance_interest_component;
            if ($isDaily) {
                if ($due > (float) $s->interest + 0.05 && (float) $s->accrued_interest <= 0) {
                    $schedIssues++;
                }
            } else {
                if ((float) $s->interest > 0 && abs($due - (float) $s->interest) > 0.05 && abs((float) $s->accrued_interest - (float) $s->interest) > 0.05) {
                    $schedIssues++;
                }
            }
        }

        $todayAccrual = DailyInterestAccrual::where('loan_id', $loan->id)
            ->whereDate('accrual_date', today())
            ->first();
        $glOk = true;
        if ($isDaily && $todayAccrual) {
            $glOk = GlTransaction::where('transaction_id', $todayAccrual->id)
                ->where('transaction_type', 'DailyInterestAccrual')
                ->count() >= 2;
        }

        $matureDup = false;
        if ($isDaily) {
            $matureDup = GlTransaction::where('customer_id', $loan->customer_id)
                ->where('transaction_type', 'Mature Interest')
                ->whereIn('transaction_id', $loan->schedule->pluck('id'))
                ->exists();
        }

        $status = ($schedIssues === 0 && $glOk && !$matureDup) ? 'OK' : 'WARN';
        if ($status === 'WARN') {
            $issues++;
        }
        echo "  {$status} {$loan->loanNo}: sched_warn={$schedIssues}";
        if ($isDaily) {
            echo " today_accrual=" . ($todayAccrual ? $todayAccrual->daily_interest_amount : 'none');
            echo " gl_ok=" . ($glOk ? 'yes' : 'no');
            echo " mature_dup=" . ($matureDup ? 'YES' : 'no');
        }
        echo "\n";
    }
    echo "\n";
}

echo $issues ? "=> {$issues} loan(s) with warnings\n" : "=> All sampled loans look consistent\n";
echo "\nDone.\n";
