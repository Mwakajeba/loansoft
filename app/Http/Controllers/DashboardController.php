<?php

namespace App\Http\Controllers;

use App\Models\ArrearsClassification;
use App\Models\Complain;
use App\Models\Loan;
use App\Services\LoanPenaltyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Endpoint for monthly collections (expected, collected, arrears) for current year
     */
    public function monthlyCollections(Request $request)
    {
        $year = now()->year;
        $company = auth()->user()->company;
        $user = auth()->user();

        // Get branch filter from request
        $selectedBranchId = $request->get('branch_id');

        // Get user's assigned branches
        $userBranchIds = $user->branches()->where('company_id', $company->id)->pluck('branches.id')->toArray();

        // If no assigned branches, use all company branches
        if (empty($userBranchIds)) {
            $userBranchIds = \App\Models\Branch::where('company_id', $company->id)->pluck('id')->toArray();
        }

        $scheduleForMonth = function ($m) use ($year, $company, $selectedBranchId, $userBranchIds) {
            $q = \App\Models\LoanSchedule::query()
                ->whereYear('loan_schedules.due_date', $year)
                ->whereMonth('loan_schedules.due_date', $m)
                ->join('loans', 'loan_schedules.loan_id', '=', 'loans.id')
                ->join('branches', 'loans.branch_id', '=', 'branches.id')
                ->where('branches.company_id', $company->id);

            if ($selectedBranchId) {
                $q->where('loans.branch_id', $selectedBranchId);
            } elseif (! empty($userBranchIds)) {
                $q->whereIn('loans.branch_id', $userBranchIds);
            }

            return $q;
        };

        $months = [];
        $expected = [];
        $collected = [];
        $arrears = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = date('M', mktime(0, 0, 0, $m, 1));

            $exp = (clone $scheduleForMonth($m))->sum(DB::raw('loan_schedules.principal + loan_schedules.interest'));
            $expected[] = $exp;

            $repayments = DB::table('repayments')
                ->join('loan_schedules', 'repayments.loan_schedule_id', '=', 'loan_schedules.id')
                ->join('loans', 'loan_schedules.loan_id', '=', 'loans.id')
                ->join('branches', 'loans.branch_id', '=', 'branches.id')
                ->whereNull('repayments.deleted_at')
                ->where('branches.company_id', $company->id)
                ->whereYear('loan_schedules.due_date', $year)
                ->whereMonth('loan_schedules.due_date', $m);

            if ($selectedBranchId) {
                $repayments->where('loans.branch_id', $selectedBranchId);
            } elseif (! empty($userBranchIds)) {
                $repayments->whereIn('loans.branch_id', $userBranchIds);
            }

            $collectedSum = (float) $repayments->sum(DB::raw('repayments.principal + repayments.interest'));
            $collected[] = $collectedSum;
            $arrears[] = max(0, $exp - $collectedSum);
        }

        return response()->json([
            'months' => $months,
            'expected' => $expected,
            'collected' => $collected,
            'arrears' => $arrears,
        ]);
    }
    /**
     * Endpoint for delinquency loan buckets (current year)
     */
    public function delinquencyLoanBuckets(Request $request)
    {
        $year = now()->year;
        $company = auth()->user()->company;
        $user = auth()->user();
        
        // Get branch filter from request
        $selectedBranchId = $request->get('branch_id');
        
        // Get user's assigned branches
        $userBranchIds = $user->branches()->where('company_id', $company->id)->pluck('branches.id')->toArray();
        
        // If no assigned branches, use all company branches
        if (empty($userBranchIds)) {
            $userBranchIds = \App\Models\Branch::where('company_id', $company->id)->pluck('id')->toArray();
        }
        
        // Define buckets (days overdue)
        $buckets = [
            '1-30 days' => [1, 30],
            '31-60 days' => [31, 60],
            '61-90 days' => [61, 90],
            '91-180 days' => [91, 180],
            '181-360 days' => [181, 360],
            '361+ days' => [361, 10000],
        ];
        $labels = [];
        $values = [];
        foreach ($buckets as $label => [$min, $max]) {
            $query = \App\Models\Loan::whereYear('disbursed_on', $year)
                ->whereHas('branch', function($q) use ($company) {
                    $q->where('company_id', $company->id);
                })
                ->where('status', 'active');
            
            // Apply branch filter
            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            } else {
                // If no specific branch selected, filter by user's assigned branches
                if (!empty($userBranchIds)) {
                    $query->whereIn('branch_id', $userBranchIds);
                }
            }
            
            $count = $query->whereHas('schedule', function($q) use ($min, $max) {
                    $q->whereRaw('DATEDIFF(CURDATE(), due_date) BETWEEN ? AND ?', [$min, $max]);
                })
                ->count();
            $labels[] = $label;
            $values[] = $count;
        }
        return response()->json([
            'labels' => $labels,
            'values' => $values
        ]);
    }
    /**
     * Endpoint for loan product disbursement data (current year)
     */
    public function loanProductDisbursement(Request $request)
    {
        $year = now()->year;
        $company = auth()->user()->company;
        $user = auth()->user();
        
        // Get branch filter from request
        $selectedBranchId = $request->get('branch_id');
        
        // Get user's assigned branches
        $userBranchIds = $user->branches()->where('company_id', $company->id)->pluck('branches.id')->toArray();
        
        // If no assigned branches, use all company branches
        if (empty($userBranchIds)) {
            $userBranchIds = \App\Models\Branch::where('company_id', $company->id)->pluck('id')->toArray();
        }
        
        $products = \App\Models\LoanProduct::all();

        $productNames = [];
        $amounts = [];
        foreach ($products as $product) {
            $query = \App\Models\Loan::where('product_id', $product->id)
                ->whereYear('disbursed_on', $year)
                ->whereHas('branch', function($q) use ($company) {
                    $q->where('company_id', $company->id);
                });
            
            // Apply branch filter
            if ($selectedBranchId) {
                $query->where('branch_id', $selectedBranchId);
            } else {
                // If no specific branch selected, filter by user's assigned branches
                if (!empty($userBranchIds)) {
                    $query->whereIn('branch_id', $userBranchIds);
                }
            }
            
            $total = $query->sum('amount');
            $productNames[] = $product->name;
            $amounts[] = $total;
        }
        return response()->json([
            'products' => $productNames,
            'amounts' => $amounts
        ]);
    }
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            // Redirect to login or show an error
            return redirect()->route('login')->with('error', 'Please login to access the dashboard.');
        }
        $company = $user->company;
        
        // Branch filter: empty / missing = all branches user can see (not a single default branch)
        $branchParam = $request->input('branch_id');
        $selectedBranchId = ($branchParam === null || $branchParam === '') ? null : (int) $branchParam;

        // Get available branches for the filter - only user's assigned branches
        $branches = $user->branches()->where('company_id', $company->id)->get();

        // Get user's assigned branch IDs for filtering
        $userBranchIds = $branches->pluck('id')->toArray();
        if (empty($userBranchIds)) {
            $userBranchIds = \App\Models\Branch::where('company_id', $company->id)->pluck('id')->toArray();
        }
        
        // Get comprehensive financial report data
        $financialReportData = $this->getFinancialReportData($selectedBranchId, $userBranchIds);

        $loans_status_stats = ['active', 'written_off', 'defaulted', 'completed', 'complete_topup'];
        // Loan statistics for Total Loan Amount (only active and completed)
        $loansForTotalAmount = \App\Models\Loan::whereHas('branch', function($query) use ($company) {
            $query->where('company_id', $company->id);
        })->when($selectedBranchId, function($query) use ($selectedBranchId) {
            return $query->where('branch_id', $selectedBranchId);
        }, function($query) use ($userBranchIds) {
            return $query->whereIn('branch_id', $userBranchIds);
        })->whereIn('status', ['active', 'completed'])->get();
        
        // All loans for other calculations (include completed so repaid totals match full portfolio)
        $loans = \App\Models\Loan::with(['schedule.repayments'])
            ->whereHas('branch', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })->when($selectedBranchId, function ($query) use ($selectedBranchId) {
                return $query->where('branch_id', $selectedBranchId);
            }, function ($query) use ($userBranchIds) {
                return $query->whereIn('branch_id', $userBranchIds);
            })->whereIn('status', $loans_status_stats)->get();
        
        // Loans for detailed interest calculations (same statuses as report)
        $loansForInterest = \App\Models\Loan::with(['customer', 'branch', 'loanOfficer', 'schedule.repayments'])
            ->whereHas('branch', function($query) use ($company) {
                $query->where('company_id', $company->id);
            })->when($selectedBranchId, function($query) use ($selectedBranchId) {
                return $query->where('branch_id', $selectedBranchId);
            }, function($query) use ($userBranchIds) {
                return $query->whereIn('branch_id', $userBranchIds);
            })->whereIn('status', ['active', 'written_off', 'defaulted'])->get();

        $totalLoanAmount = $loansForTotalAmount->sum('amount_total');
        $totalPrincipal = $loans->sum('amount');
        $totalInterest = $loans->sum('interest_amount');

        // Repaid principal/interest: sum ALL repayments for filtered loans (incl. completed),
        // not only active/defaulted — otherwise completed branch loans show 0 repaid.
        $repaidPrincipal = 0;
        $repaidInterest = 0;
        foreach ($loans as $loan) {
            if (!$loan->schedule || $loan->schedule->isEmpty()) {
                continue;
            }
            foreach ($loan->schedule as $schedule) {
                $repaidPrincipal += (float) $schedule->repayments->sum('principal');
                $repaidInterest += (float) $schedule->repayments->sum('interest');
            }
        }

        $outstandingPrincipal = 0;
        $outstandingInterest = 0;
        
        // Detailed interest breakdown
        $accruedInterest = 0;
        $notDueInterest = 0;
        $paidInterest = 0;
        $outstandingInterestDetailed = 0;
        
        $currentDate = \Carbon\Carbon::now();
        $currentMonth = $currentDate->format('Y-m');
        
        foreach ($loansForInterest as $loan) {
            $loanAccruedInterest = 0;
            $loanNotDueInterest = 0;
            $loanOutstandingInterest = 0;
            $loanPaidInterest = 0;
            
            if ($loan->schedule && $loan->schedule->count() > 0) {
                foreach ($loan->schedule as $schedule) {
                    $principalPaid = $schedule->repayments->sum('principal');
                    $interestPaid = $schedule->repayments->sum('interest');
                    $outstandingPrincipal += max(0, $schedule->principal - $principalPaid);
                    $outstandingInterest += max(0, $schedule->interest - $interestPaid);
                    
                    // Calculate detailed interest breakdown per schedule
                    $scheduleDate = \Carbon\Carbon::parse($schedule->due_date);
                    $scheduleMonth = $scheduleDate->format('Y-m');
                    $scheduleInterest = $schedule->interest ?? 0;
                    
                    if ($scheduleMonth <= $currentMonth) {
                        // Interest is due up to this month - what's not paid is outstanding
                        $loanOutstandingInterest += max(0, $scheduleInterest - $interestPaid);
                    } else {
                        // Interest is not yet due
                        $loanNotDueInterest += $scheduleInterest;
                    }
                    
                    $loanPaidInterest += $interestPaid;
                }
            } else {
                // Fallback to simple calculation if no schedule
                $loanOutstandingInterest = max(0, ($loan->interest_amount ?? 0) - $loanPaidInterest);
                $loanNotDueInterest = 0;
                $loanAccruedInterest = 0;
            }
            
            // Calculate accrued interest for this loan (interest earned but not yet due)
            $loanStartDate = \Carbon\Carbon::parse($loan->disbursed_on);
            $monthsElapsed = $loanStartDate->diffInMonths($currentDate);
            $totalLoanMonths = $loan->period ?? 1;
            
            if ($monthsElapsed > 0 && $monthsElapsed < $totalLoanMonths) {
                // Calculate proportional interest earned but not yet due for this loan
                $loanAccruedInterest = ($loanNotDueInterest * $monthsElapsed) / $totalLoanMonths;
            }
            
            // Add this loan's amounts to totals
            $accruedInterest += $loanAccruedInterest;
            $notDueInterest += $loanNotDueInterest;
            $outstandingInterestDetailed += $loanOutstandingInterest;
            $paidInterest += $loanPaidInterest;
        }

        // Loan officer-specific portfolio & arrears (for logged-in user)
        $officerLoansQuery = \App\Models\Loan::with(['schedule.repayments'])
            ->where('loan_officer_id', $user->id)
            ->whereHas('branch', function($query) use ($company) {
                $query->where('company_id', $company->id);
            });

        // Apply same branch filter logic
        if ($selectedBranchId) {
            $officerLoansQuery->where('branch_id', $selectedBranchId);
        } elseif (!empty($userBranchIds)) {
            $officerLoansQuery->whereIn('branch_id', $userBranchIds);
        }

        $officerLoans = $officerLoansQuery->get();

        $officerTotalPortfolio = 0;
        $officerTotalArrears = 0;

        foreach ($officerLoans as $loan) {
            // Total outstanding (principal + interest + fees + penalties) for this loan
            $officerTotalPortfolio += $loan->getTotalOutstandingAmount();
            // Total arrears (overdue part only) for this loan
            $officerTotalArrears += $loan->arrears_amount;
        }

        $penaltyBalance = LoanPenaltyService::getTotalPenaltyBalance($selectedBranchId);

        // Get previous year comparative data
        $previousYearData = $this->getPreviousYearData($selectedBranchId, $userBranchIds);

        // Get complaints count (pending complaints for current branch/company)
        $complaintsQuery = Complain::whereHas('branch', function($q) use ($company) {
            $q->where('company_id', $company->id);
        });
        
        if ($selectedBranchId) {
            $complaintsQuery->where('branch_id', $selectedBranchId);
        } elseif (!empty($userBranchIds)) {
            $complaintsQuery->whereIn('branch_id', $userBranchIds);
        }
        
        $pendingComplaintsCount = (clone $complaintsQuery)->where('status', 'pending')->count();
        $totalComplaintsCount = $complaintsQuery->count();

        // Active-loan KPIs (dashboard stat cards)
        $activeLoansForKpi = Loan::with(['schedule.repayments', 'repayments'])
            ->whereHas('branch', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->when($selectedBranchId, function ($query) use ($selectedBranchId) {
                return $query->where('branch_id', $selectedBranchId);
            }, function ($query) use ($userBranchIds) {
                return $query->whereIn('branch_id', $userBranchIds);
            })
            ->where('status', 'active')
            ->get();

        $principalDisbursed = (float) $activeLoansForKpi->sum('amount');
        $interestExpected = 0.0;
        $principalCollected = 0.0;
        $interestCollected = 0.0;
        $outstandingPrincipalActive = 0.0;
        $outstandingInterestActive = 0.0;

        foreach ($activeLoansForKpi as $loan) {
            if ($loan->schedule && $loan->schedule->isNotEmpty()) {
                foreach ($loan->schedule as $schedule) {
                    $interestExpected += (float) ($schedule->interest ?? 0);
                    $principalCollected += (float) $schedule->repayments->sum('principal');
                    $interestCollected += (float) $schedule->repayments->sum('interest');
                    $prPaid = (float) $schedule->repayments->sum('principal');
                    $intPaid = (float) $schedule->repayments->sum('interest');
                    $outstandingPrincipalActive += max(0, (float) ($schedule->principal ?? 0) - $prPaid);
                    $outstandingInterestActive += max(0, (float) ($schedule->interest ?? 0) - $intPaid);
                }
            } else {
                $interestExpected += (float) ($loan->interest_amount ?? 0);
                $principalCollected += (float) $loan->total_principal_paid;
                $interestCollected += (float) $loan->total_interest_paid;
                $outstandingPrincipalActive += max(0, (float) ($loan->amount ?? 0) - (float) $loan->total_principal_paid);
                $outstandingInterestActive += max(0, (float) ($loan->interest_amount ?? 0) - (float) $loan->total_interest_paid);
            }
        }

        $totalLoansExpected = $principalDisbursed + $interestExpected;
        $totalOutstanding = $outstandingPrincipalActive + $outstandingInterestActive + (float) $penaltyBalance;

        $arrearsBucketStats = $this->buildArrearsBucketLoanCounts($activeLoansForKpi);

        return view('dashboard', compact(
            'financialReportData',
            'penaltyBalance',
            'previousYearData',
            'totalLoanAmount',
            'totalPrincipal',
            'totalInterest',
            'repaidPrincipal',
            'repaidInterest',
            'outstandingPrincipal',
            'outstandingInterest',
            'accruedInterest',
            'notDueInterest',
            'paidInterest',
            'outstandingInterestDetailed',
            'officerTotalPortfolio',
            'officerTotalArrears',
            'branches',
            'selectedBranchId',
            'pendingComplaintsCount',
            'totalComplaintsCount',
            'principalDisbursed',
            'interestExpected',
            'totalLoansExpected',
            'principalCollected',
            'interestCollected',
            'outstandingPrincipalActive',
            'outstandingInterestActive',
            'totalOutstanding',
            'arrearsBucketStats'
        ));
    }

    private function buildArrearsBucketLoanCounts($activeLoans): array
    {
        $classifications = ArrearsClassification::query()
            ->forCompany()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($classifications->isEmpty()) {
            return [];
        }

        $stats = [];
        foreach ($classifications as $c) {
            $stats[$c->id] = [
                'bucket_label' => $c->bucket_label,
                'status' => $c->status,
                'days_from' => (int) $c->days_from,
                'days_to' => $c->days_to !== null ? (int) $c->days_to : null,
                'provision_percentage' => (float) $c->provision_percentage,
                'loan_count' => 0,
                'provision_amount' => 0.0,
                'arrears_principal' => 0.0,
                'arrears_interest' => 0.0,
                'arrears_total' => 0.0,
            ];
        }

        foreach ($activeLoans as $loan) {
            $dpd = (int) $loan->days_in_arrears;
            $arrearsSplit = $this->loanArrearsPrincipalInterestSplit($loan);
            foreach ($classifications as $c) {
                if ($this->arrearsDpdMatchesBucket(
                    $dpd,
                    (int) $c->days_from,
                    $c->days_to !== null ? (int) $c->days_to : null
                )) {
                    $stats[$c->id]['loan_count']++;
                    $rate = (float) $c->provision_percentage / 100.0;
                    $stats[$c->id]['provision_amount'] += $arrearsSplit['principal'] * $rate;
                    $stats[$c->id]['arrears_principal'] += $arrearsSplit['principal'];
                    $stats[$c->id]['arrears_interest'] += $arrearsSplit['interest'];
                    $stats[$c->id]['arrears_total'] += $arrearsSplit['total'];
                    break;
                }
            }
        }

        return $classifications->map(fn ($c) => $stats[$c->id])->values()->all();
    }

    private function arrearsDpdMatchesBucket(int $dpd, int $daysFrom, ?int $daysTo): bool
    {
        if ($daysTo === null) {
            return $dpd >= $daysFrom;
        }

        return $dpd >= $daysFrom && $dpd <= $daysTo;
    }

    private function loanArrearsPrincipalInterestSplit(Loan $loan): array
    {
        if ($loan->status === 'restructured') {
            return ['principal' => 0.0, 'interest' => 0.0, 'total' => 0.0];
        }

        $today = Carbon::now();
        $principal = 0.0;
        $interest = 0.0;

        foreach ($loan->schedule ?? [] as $scheduleItem) {
            if (($scheduleItem->status ?? null) === 'restructured') {
                continue;
            }

            $dueDate = Carbon::parse($scheduleItem->due_date);
            if (! $dueDate->lt($today) || (float) $scheduleItem->remaining_amount <= 0) {
                continue;
            }

            $prPaid = (float) $scheduleItem->repayments->sum('principal');
            $intPaid = (float) $scheduleItem->repayments->sum('interest');
            $principal += max(0.0, (float) ($scheduleItem->principal ?? 0) - $prPaid);
            $interest += max(0.0, (float) ($scheduleItem->interest ?? 0) - $intPaid);
        }

        return [
            'principal' => $principal,
            'interest' => $interest,
            'total' => (float) $loan->arrears_amount,
        ];
    }

    private function getFinancialReportData($selectedBranchId = null, $userBranchIds = [])
    {
        $company = auth()->user()->company;
        
        // Get all chart accounts with their balances grouped by account class
        $query = DB::table('gl_transactions')
            ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->join('account_class', 'account_class_groups.class_id', '=', 'account_class.id')
            ->where('account_class_groups.company_id', $company->id);
        
        // Apply branch filter
        if ($selectedBranchId) {
            $query->where('gl_transactions.branch_id', $selectedBranchId);
        } else {
            // If no specific branch selected, filter by user's assigned branches
            $query->whereIn('gl_transactions.branch_id', $userBranchIds);
        }
        
        $chartAccountsData = $query
            ->select(
                'chart_accounts.id as account_id',
                'chart_accounts.account_name as account',
                'account_class.name as class_name',
                'account_class_groups.name as group_name',
                DB::raw('SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END) as debit_total'),
                DB::raw('SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END) as credit_total')
            )
            ->groupBy('chart_accounts.id', 'chart_accounts.account_name', 'account_class.name', 'account_class_groups.name')
            ->get();
            
        // Group by account class and calculate balances
        $chartAccountsAssets = [];
        $chartAccountsLiabilities = [];
        $chartAccountsEquitys = [];
        $chartAccountsRevenues = [];
        $chartAccountsExpense = [];
        
        foreach ($chartAccountsData as $account) {
            // Calculate balance based on account class
            $balance = 0;
            
            // Categorize based on account class
            switch (strtolower($account->class_name)) {
                case 'assets':
                    $balance = $account->debit_total - $account->credit_total; // Assets: debit increases
                    $chartAccountsAssets[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'liabilities':
                    $balance = $account->credit_total - $account->debit_total; // Liabilities: credit increases
                    $chartAccountsLiabilities[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'equity':
                    $balance = $account->credit_total - $account->debit_total; // Equity: credit increases
                    $chartAccountsEquitys[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'income':
                case 'revenue':
                    $balance = $account->credit_total - $account->debit_total; // Revenue: credit increases
                    $chartAccountsRevenues[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'expenses':
                case 'expense':
                    $balance = $account->debit_total - $account->credit_total; // Expenses: debit increases
                    $chartAccountsExpense[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
            }
        }
        
        // Calculate profit/loss
        $sumRevenue = collect($chartAccountsRevenues)->flatten(1)->sum('sum');
        $sumExpense = collect($chartAccountsExpense)->flatten(1)->sum('sum');
        $profitLoss = $sumRevenue - $sumExpense;
        
        return [
            'chartAccountsAssets' => $chartAccountsAssets,
            'chartAccountsLiabilities' => $chartAccountsLiabilities,
            'chartAccountsEquitys' => $chartAccountsEquitys,
            'chartAccountsRevenues' => $chartAccountsRevenues,
            'chartAccountsExpense' => $chartAccountsExpense,
            'profitLoss' => $profitLoss
        ];
    }
    
    private function getPreviousYearData($selectedBranchId = null, $userBranchIds = [])
    {
        $company = auth()->user()->company;
        $currentYear = date('Y');
        $previousYear = $currentYear - 1;
        
        // Get previous year financial data by account
        $query = DB::table('gl_transactions')
            ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->join('account_class', 'account_class_groups.class_id', '=', 'account_class.id')
            ->where('account_class_groups.company_id', $company->id)
            ->whereYear('gl_transactions.date', $previousYear);
        
        // Apply branch filter
        if ($selectedBranchId) {
            $query->where('gl_transactions.branch_id', $selectedBranchId);
        } else {
            // If no specific branch selected, filter by user's assigned branches
            $query->whereIn('gl_transactions.branch_id', $userBranchIds);
        }
        
        $previousYearData = $query
            ->select(
                'chart_accounts.id as account_id',
                'chart_accounts.account_name as account',
                'account_class.name as class_name',
                'account_class_groups.name as group_name',
                DB::raw('SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END) as debit_total'),
                DB::raw('SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END) as credit_total')
            )
            ->groupBy('chart_accounts.id', 'chart_accounts.account_name', 'account_class.name', 'account_class_groups.name')
            ->get();
            
        // Group by account class and calculate balances
        $previousYearAssets = [];
        $previousYearLiabilities = [];
        $previousYearEquitys = [];
        $previousYearRevenues = [];
        $previousYearExpense = [];
        
        foreach ($previousYearData as $account) {
            // Calculate balance based on account class
            $balance = 0;
            
            // Categorize based on account class
            switch (strtolower($account->class_name)) {
                case 'assets':
                    $balance = $account->debit_total - $account->credit_total; // Assets: debit increases
                    $previousYearAssets[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'liabilities':
                    $balance = $account->credit_total - $account->debit_total; // Liabilities: credit increases
                    $previousYearLiabilities[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'equity':
                    $balance = $account->credit_total - $account->debit_total; // Equity: credit increases
                    $previousYearEquitys[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'income':
                case 'revenue':
                    $balance = $account->credit_total - $account->debit_total; // Revenue: credit increases
                    $previousYearRevenues[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
                case 'expenses':
                case 'expense':
                    $balance = $account->debit_total - $account->credit_total; // Expenses: debit increases
                    $previousYearExpense[$account->group_name][] = [
                        'account_id' => $account->account_id,
                        'account' => $account->account,
                        'sum' => $balance
                    ];
                    break;
            }
        }
        
        // Calculate previous year profit/loss
        $sumRevenue = collect($previousYearRevenues)->flatten(1)->sum('sum');
        $sumExpense = collect($previousYearExpense)->flatten(1)->sum('sum');
        $previousYearProfitLoss = $sumRevenue - $sumExpense;
        
        return [
            'year' => $previousYear,
            'chartAccountsAssets' => $previousYearAssets,
            'chartAccountsLiabilities' => $previousYearLiabilities,
            'chartAccountsEquitys' => $previousYearEquitys,
            'chartAccountsRevenues' => $previousYearRevenues,
            'chartAccountsExpense' => $previousYearExpense,
            'profitLoss' => $previousYearProfitLoss
        ];
    }

    /**
     * Handle bulk SMS sending from dashboard
     */
    public function sendBulkSms(Request $request)
    {
        @set_time_limit(0);
        $request->validate([
            'branch_id' => 'required',
            'message_title' => 'required|string|max:100',
            'bulk_message_content' => 'required|string|max:500',
            'custom_title' => 'nullable|string|max:100',
        ]);

        $branchId = $request->branch_id;
        $title = $request->message_title;
        $customTitle = $request->custom_title;
        $messageContent = $request->bulk_message_content;

        // If 'Custom' is selected, use the custom title
        if ($title === 'Custom' && $customTitle) {
            $title = $customTitle;
        }

        // Accept either "all" or a valid branch id.
        if ($branchId !== 'all' && !\App\Models\Branch::whereKey($branchId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'The selected branch is invalid.',
            ], 422);
        }

        // Get customers for the selected branch or all branches
        $customersQuery = \App\Models\Customer::query();
        if ($branchId !== 'all') {
            $customersQuery->where('branch_id', $branchId);
        }
        $customers = $customersQuery
            ->whereNotNull('phone1')
            ->select('id', 'phone1')
            ->get();

        $valid = 0;
        $invalid = 0;
        $duplicates = 0;
        $failed = 0;
        $seenNumbers = [];
        $recipients = [];
        $responses = [];
        $smsBody = trim($messageContent);

        foreach ($customers as $customer) {
            $phone = preg_replace('/[^0-9+]/', '', $customer->phone1);
            if (empty($phone)) {
                $invalid++;
                continue;
            }
            if (isset($seenNumbers[$phone])) {
                $duplicates++;
                continue;
            }

            $seenNumbers[$phone] = true;
            $recipients[] = [
                'customer_id' => $customer->id,
                'phone_number' => $phone,
            ];
            $valid++;
        }

        // Send in chunks so very large sends (e.g. 2,000+) are stable.
        $sendChunkSize = 200;
        $logRows = [];
        foreach (array_chunk($recipients, $sendChunkSize) as $chunk) {
            $phones = array_column($chunk, 'phone_number');
            $smsResponse = \App\Helpers\SmsHelper::send(implode(',', $phones), $smsBody);
            $responses[] = $smsResponse;

            $wasSuccessful = is_array($smsResponse) ? (($smsResponse['success'] ?? false) === true) : false;
            if (!$wasSuccessful) {
                $failed += count($chunk);
            }

            $responseForStorage = is_array($smsResponse) || is_object($smsResponse)
                ? json_encode($smsResponse, JSON_UNESCAPED_UNICODE)
                : (string) $smsResponse;

            $now = now();
            foreach ($chunk as $recipient) {
                $logRows[] = [
                    'customer_id' => $recipient['customer_id'],
                    'phone_number' => $recipient['phone_number'],
                    'message' => $smsBody,
                    'response' => $responseForStorage,
                    'sent_by' => auth()->id(),
                    'sent_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Bulk insert logs to reduce DB load for large batches.
        foreach (array_chunk($logRows, 500) as $logChunk) {
            \DB::table('sms_logs')->insert($logChunk);
        }

        return response()->json([
            'success' => true,
            'message' => "Bulk SMS sent successfully!",
            'response' => [
                'message' => 'Message Submitted Successfully',
                'valid' => $valid,
                'invalid' => $invalid,
                'duplicates' => $duplicates,
                'failed' => $failed,
                'chunks' => count($responses),
                'details' => $responses
            ]
        ]);
    }
    private function resolveDashboardBranchContext(Request $request): array
    {
        $user = auth()->user();
        $company = $user->company;
        $branchParam = $request->input('branch_id');
        $selectedBranchId = ($branchParam === null || $branchParam === '') ? null : (int) $branchParam;
        $branches = $user->branches()->where('company_id', $company->id)->get();
        $userBranchIds = $branches->pluck('id')->toArray();
        if (empty($userBranchIds)) {
            $userBranchIds = \App\Models\Branch::where('company_id', $company->id)->pluck('id')->toArray();
        }

        return compact('user', 'company', 'selectedBranchId', 'userBranchIds', 'branches');
    }

    private function dashboardPrincipalLoansQuery($company, $selectedBranchId, $userBranchIds)
    {
        $statuses = ['active', 'written_off', 'defaulted', 'completed', 'complete_topup'];

        return \App\Models\Loan::with(['customer', 'product', 'branch', 'bankAccount'])
            ->whereHas('branch', function ($query) use ($company) {
                $query->where('company_id', $company->id);
            })
            ->when($selectedBranchId, function ($query) use ($selectedBranchId) {
                return $query->where('branch_id', $selectedBranchId);
            }, function ($query) use ($userBranchIds) {
                return $query->whereIn('branch_id', $userBranchIds);
            })
            ->whereIn('status', $statuses)
            ->orderByDesc('disbursed_on')
            ->orderByDesc('id');
    }

    /**
     * Loans that make up the dashboard "Total Principal" figure.
     */
    public function principalLoans(Request $request)
    {
        ['company' => $company, 'selectedBranchId' => $selectedBranchId, 'userBranchIds' => $userBranchIds, 'branches' => $branches] = $this->resolveDashboardBranchContext($request);

        $loans = $this->dashboardPrincipalLoansQuery($company, $selectedBranchId, $userBranchIds)->get();

        $totalPrincipal = $loans->sum('amount');
        $totalInterest = $loans->sum('interest_amount');
        $branchName = $selectedBranchId
            ? ($branches->firstWhere('id', $selectedBranchId)?->name ?? 'Selected branch')
            : 'All branches';

        return view('dashboard.principal-loans', compact(
            'loans',
            'totalPrincipal',
            'totalInterest',
            'selectedBranchId',
            'branchName',
            'branches'
        ));
    }
}
