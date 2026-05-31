<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = User::first();
Auth::login($user);

$from = '2026-03-01';
$to = '2026-11-24';
$branchId = 1;

$userBranchIds = $user->branches()->pluck('branches.id')->toArray();

echo "User branches: " . json_encode($userBranchIds) . PHP_EOL;

$allInBranch = Loan::where('branch_id', $branchId)->count();
echo "All loans branch {$branchId}: {$allInBranch}" . PHP_EOL;

$withDisbursed = Loan::where('branch_id', $branchId)->whereNotNull('disbursed_on')->count();
echo "With disbursed_on: {$withDisbursed}" . PHP_EOL;

$inRange = Loan::where('branch_id', $branchId)
    ->whereBetween('disbursed_on', [$from, $to])
    ->count();
echo "Disbursed between {$from} and {$to}: {$inRange}" . PHP_EOL;

$inRangeUserBranches = Loan::whereIn('branch_id', $userBranchIds)
    ->whereBetween('disbursed_on', [$from, $to])
    ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
    ->count();
echo "Disbursed ONLY in range: {$inRangeUserBranches}" . PHP_EOL;

$portfolioInPeriod = Loan::whereIn('branch_id', $userBranchIds)
    ->whereNotNull('disbursed_on')
    ->whereDate('disbursed_on', '<=', $to)
    ->where(function ($query) use ($from, $to) {
        $query->whereBetween('disbursed_on', [$from, $to])
            ->orWhere(function ($existing) use ($from) {
                $existing->where('disbursed_on', '<', $from)
                    ->whereIn('status', ['active', 'completed', 'defaulted']);
            });
    })
    ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
    ->count();
echo "Portfolio tracking (new query): {$portfolioInPeriod}" . PHP_EOL;

$ctrl = app(App\Http\Controllers\LoanReportController::class);
$ref = new ReflectionMethod($ctrl, 'buildPortfolioTrackingData');
$ref->setAccessible(true);
$rows = $ref->invoke($ctrl, $from, $to, $branchId, null, null, 'day');
echo "Controller rows: " . count($rows) . PHP_EOL;

$minMax = Loan::where('branch_id', $branchId)
    ->whereNotNull('disbursed_on')
    ->selectRaw('MIN(disbursed_on) as min_d, MAX(disbursed_on) as max_d')
    ->first();
echo "Disbursement date range branch 1: min={$minMax->min_d}, max={$minMax->max_d}" . PHP_EOL;

$sample = Loan::where('branch_id', $branchId)
    ->select('id', 'disbursed_on', 'status', 'amount', 'branch_id')
    ->orderBy('disbursed_on', 'desc')
    ->limit(10)
    ->get();
echo "Recent loans:\n" . json_encode($sample, JSON_PRETTY_PRINT) . PHP_EOL;

// Check if disbursed_on is datetime vs date issue
$inRangeCarbon = Loan::where('branch_id', $branchId)
    ->whereBetween('disbursed_on', [
        \Carbon\Carbon::parse($from)->startOfDay()->toDateString(),
        \Carbon\Carbon::parse($to)->endOfDay()->toDateString(),
    ])
    ->count();
echo "With carbon date strings: {$inRangeCarbon}" . PHP_EOL;

// Alternative date columns
$cols = DB::select("SHOW COLUMNS FROM loans LIKE '%date%'");
echo "Date columns on loans: " . json_encode($cols, JSON_PRETTY_PRINT) . PHP_EOL;

$createdInRange = Loan::where('branch_id', $branchId)
    ->whereBetween('created_at', [\Carbon\Carbon::parse($from)->startOfDay(), \Carbon\Carbon::parse($to)->endOfDay()])
    ->count();
echo "Created in range: {$createdInRange}" . PHP_EOL;
