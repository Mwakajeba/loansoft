<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\LoanReportController;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

$user = User::first();
Auth::login($user);

$ctrl = app(LoanReportController::class);
$fromDate = '2026-03-01';
$toDate = '2026-05-24';
$branchId = 1;
$asOfDate = $toDate;

$issues = [];
$checks = [];

$portfolioRef = new ReflectionMethod($ctrl, 'getPortfolioData');
$portfolioRef->setAccessible(true);
$portfolio = $portfolioRef->invoke($ctrl, $asOfDate, $branchId, null, null, 'active_completed');

$trackingRef = new ReflectionMethod($ctrl, 'buildPortfolioTrackingData');
$trackingRef->setAccessible(true);
$tracking = $trackingRef->invoke($ctrl, $fromDate, $toDate, $branchId, null, null, 'day');

$perfRef = new ReflectionMethod($ctrl, 'getPerformanceData');
$perfRef->setAccessible(true);
$performance = $perfRef->invoke($ctrl, $fromDate, $toDate, $branchId, null, null);

// --- Portfolio internal consistency ---
$ps = $portfolio['summary'];
$sumOutstanding = collect($portfolio['loans'])->sum('outstanding_amount');
$sumPaid = collect($portfolio['loans'])->sum('total_paid');
$sumDisbursed = collect($portfolio['loans'])->sum('disbursed_amount');

if (abs($sumOutstanding - $ps['total_outstanding']) > 0.02) {
    $issues[] = "Portfolio: row outstanding sum ({$sumOutstanding}) != summary ({$ps['total_outstanding']})";
} else {
    $checks[] = 'Portfolio: summary totals match loan rows';
}

$parCalc = $ps['total_outstanding'] > 0
    ? (collect($portfolio['loans'])->where('is_in_arrears', true)->sum('outstanding_amount') / $ps['total_outstanding']) * 100
    : 0;
if (abs($parCalc - $ps['par_ratio']) > 0.02) {
    $issues[] = "Portfolio: PAR ratio mismatch (calc {$parCalc} vs {$ps['par_ratio']})";
} else {
    $checks[] = 'Portfolio: PAR ratio calculation OK';
}

// Compare portfolio loan outstanding vs Loan model breakdown
$portfolioMismatches = 0;
foreach ($portfolio['loans'] as $row) {
    $loan = Loan::with(['schedule.repayments'])->find($row['loan_id']);
    if (!$loan) {
        continue;
    }
    $breakdown = $loan->getOutstandingBalanceBreakdown();
    $modelTotal = $breakdown['total_balance'];
    $reportTotal = $row['outstanding_amount'];
    if (abs($modelTotal - $reportTotal) > 1.0) {
        $portfolioMismatches++;
        if ($portfolioMismatches <= 3) {
            $issues[] = "Portfolio loan #{$row['loan_id']}: outstanding report={$reportTotal} vs model={$modelTotal}";
        }
    }
}
if ($portfolioMismatches === 0) {
    $checks[] = 'Portfolio: outstanding aligns with Loan::getOutstandingBalanceBreakdown()';
} else {
    $issues[] = "Portfolio: {$portfolioMismatches} loans with outstanding mismatch vs canonical model";
}

// --- Portfolio Tracking ---
$trackingLoans = collect($tracking)->filter(fn ($r) => empty($r['is_summary'] ?? false) && ($r['loan_account_no'] ?? '') !== '');
$trackingCount = $trackingLoans->count();
$trackingDisbursed = $trackingLoans->sum('amount_disbursed');

$expectedTrackingCount = Loan::whereIn('branch_id', $user->branches()->pluck('branches.id'))
    ->whereNotNull('disbursed_on')
    ->whereDate('disbursed_on', '<=', $toDate)
    ->where(function ($query) use ($fromDate, $toDate) {
        $query->whereBetween('disbursed_on', [$fromDate, $toDate])
            ->orWhere(function ($existing) use ($fromDate) {
                $existing->where('disbursed_on', '<', $fromDate)
                    ->whereIn('status', ['active', 'completed', 'defaulted']);
            });
    })
    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
    ->count();

if ($trackingCount !== $expectedTrackingCount) {
    $issues[] = "Tracking: loan count {$trackingCount} != expected {$expectedTrackingCount}";
} else {
    $checks[] = "Tracking: {$trackingCount} loans in portfolio for period (matches DB)";
}

$trackingMismatches = 0;
foreach ($trackingLoans as $row) {
    $loan = Loan::with(['schedule.repayments', 'product', 'customer', 'loanOfficer'])->where('loanNo', $row['loan_account_no'])->first();
    if (!$loan) {
        $loan = Loan::with(['schedule.repayments'])->where('id', $row['loan_account_no'])->first();
    }
    if (!$loan) {
        continue;
    }
    $breakdown = $loan->getOutstandingBalanceBreakdown();
    $expectedPrincipal = $breakdown['outstanding_principal'];
    $expectedInterest = $breakdown['outstanding_interest'];
    if (abs($expectedPrincipal - $row['outstanding_principal']) > 1.0 || abs($expectedInterest - $row['outstanding_interest']) > 1.0) {
        $trackingMismatches++;
        if ($trackingMismatches <= 3) {
            $issues[] = "Tracking loan {$loan->id}: principal report={$row['outstanding_principal']} model={$expectedPrincipal}; interest report={$row['outstanding_interest']} model={$expectedInterest}";
        }
    }
    $expectedArrears = (int) ($loan->days_in_arrears ?? 0);
    if ((int) $row['days_in_arrears'] !== $expectedArrears) {
        $trackingMismatches++;
        if ($trackingMismatches <= 5) {
            $issues[] = "Tracking loan {$loan->id}: days_in_arrears report={$row['days_in_arrears']} model={$expectedArrears}";
        }
    }
    $expectedOverdue = (float) ($loan->arrears_amount ?? 0);
    if (abs($expectedOverdue - (float) $row['amount_overdue']) > 1.0) {
        $trackingMismatches++;
        if ($trackingMismatches <= 5) {
            $issues[] = "Tracking loan {$loan->id}: amount_overdue report={$row['amount_overdue']} model={$expectedOverdue}";
        }
    }
}
if ($trackingMismatches === 0) {
    $checks[] = 'Tracking: outstanding/arrears align with Loan model';
} else {
    $issues[] = "Tracking: {$trackingMismatches} field mismatches vs blog canonical model";
}

// --- Performance ---
$perf = $performance['summary'];
$perfLoans = collect($performance['loans']);
$negativeOutstanding = $perfLoans->where('outstanding_amount', '<', 0)->count();
if ($negativeOutstanding > 0) {
    $issues[] = "Performance: {$negativeOutstanding} active loans with negative outstanding";
} else {
    $checks[] = 'Performance: no negative outstanding balances';
}

$criticalCount = $perfLoans->where('performance_grade', 'Critical')->count();
$gradeSum = $perf['excellent_loans'] + $perf['good_loans'] + $perf['fair_loans'] + $perf['poor_loans'];
if ($criticalCount > 0 && !isset($perf['critical_loans'])) {
    $issues[] = "Performance: {$criticalCount} Critical-grade loans not included in summary grade counts (sum grades={$gradeSum}, total loans={$perf['total_loans']})";
} elseif ($gradeSum + $criticalCount !== $perf['total_loans']) {
    $issues[] = "Performance: grade counts ({$gradeSum}+critical {$criticalCount}) != total loans ({$perf['total_loans']})";
} else {
    $checks[] = 'Performance: grade distribution covers all loans';
}

$perfMismatches = 0;
foreach ($perfLoans as $row) {
    $loan = Loan::with(['schedule.repayments'])->find($row['loan_id']);
    if (!$loan || $loan->status !== 'active') {
        continue;
    }
    $breakdown = $loan->getOutstandingBalanceBreakdown();
    if (abs($breakdown['total_balance'] - max(0, $row['outstanding_amount'])) > 1.0) {
        $perfMismatches++;
        if ($perfMismatches <= 3) {
            $issues[] = "Performance loan #{$row['loan_id']}: outstanding report={$row['outstanding_amount']} vs model={$breakdown['total_balance']}";
        }
    }
}
if ($perfMismatches === 0) {
    $checks[] = 'Performance: outstanding roughly aligns with Loan model (may differ on penalties/fees inclusion)';
} else {
    $issues[] = "Performance: {$perfMismatches} loans with outstanding mismatch";
}

// Cross-report: active loans disbursed in period should appear in tracking; portfolio includes them if active/completed
$trackingIds = Loan::where('branch_id', $branchId)
    ->whereBetween('disbursed_on', [$fromDate, $toDate])
    ->pluck('id');
$portfolioIds = collect($portfolio['loans'])->pluck('loan_id');
$checks[] = 'Cross-report: tracking=' . $trackingCount . ' loans, portfolio(active+completed)=' . $portfolioIds->count() . ', performance(active)=' . $perf['total_loans'];

echo "=== Loan Reports Audit (branch {$branchId}, {$fromDate} to {$toDate}) ===\n\n";
foreach ($checks as $c) {
    echo "[OK] {$c}\n";
}
if ($issues) {
    echo "\nISSUES:\n";
    foreach ($issues as $i) {
        echo "[!!] {$i}\n";
    }
} else {
    echo "\nNo issues found.\n";
}

echo "\nPortfolio summary: loans={$ps['total_loans']}, outstanding={$ps['total_outstanding']}, PAR={$ps['par_ratio']}%\n";
echo "Tracking: loans={$trackingCount}, disbursed={$trackingDisbursed}\n";
echo "Performance: active={$perf['total_loans']}, period_collections={$perf['period_collections']}, arrears={$perf['loans_in_arrears']}\n";

exit(count($issues) > 0 ? 1 : 0);
