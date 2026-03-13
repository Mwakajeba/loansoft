<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Repayment;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class LoanAnalyticsController extends Controller
{
    /**
     * Display the analytics dashboard
     */
    public function index(Request $request)
    {
        try {
            Log::info('Analytics index page loaded', [
                'user_id' => auth()->id(),
                'params' => $request->all()
            ]);

            $user = auth()->user();
            if (!$user) {
                Log::warning('Analytics index: Unauthorized access attempt');
                return redirect()->route('login');
            }

            $company = $user->company;
            $branches = $user->branches()->where('company_id', $company->id)->get();
            $userBranchIds = $branches->pluck('id')->toArray();

            Log::info('Analytics index: User branches', [
                'branch_count' => count($branches),
                'branch_ids' => $userBranchIds
            ]);

            // Get date range from request or default to current quarter
            $dateRange = $request->get('range', 'quarterly');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            // Calculate date range based on selection
            $dates = $this->calculateDateRange($dateRange, $startDate, $endDate);
            $startDate = $dates['start'];
            $endDate = $dates['end'];
            $previousStartDate = $dates['previous_start'];
            $previousEndDate = $dates['previous_end'];

            // Get branch filter
            $selectedBranchId = $request->get('branch_id');

            Log::info('Analytics index: Date range calculated', [
                'range' => $dateRange,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            return view('analytics.loans.index', compact(
                'branches',
                'selectedBranchId',
                'dateRange',
                'startDate',
                'endDate',
                'previousStartDate',
                'previousEndDate',
                'userBranchIds'
            ));
        } catch (\Exception $e) {
            Log::error('Error in analytics index', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * API endpoint to get KPIs
     */
    public function getKPIs(Request $request)
    {
        $startTime = microtime(true);
        try {
            Log::info('getKPIs called', [
                'params' => $request->all(),
                'user_id' => auth()->id()
            ]);

            $user = auth()->user();
            if (!$user) {
                Log::warning('getKPIs: Unauthorized access attempt');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $company = $user->company;
            $branches = $user->branches()->where('company_id', $company->id)->get();
            $userBranchIds = $branches->pluck('id')->toArray();

            Log::info('getKPIs: User branches', [
                'branch_ids' => $userBranchIds,
                'branch_count' => count($userBranchIds)
            ]);

            if (empty($userBranchIds)) {
                Log::info('getKPIs: No branches found, returning empty KPIs');
                return response()->json([
                    'portfolio' => $this->getEmptyPortfolioKPIs(),
                    'profitability' => $this->getEmptyProfitabilityKPIs(),
                    'risk' => $this->getEmptyRiskKPIs(),
                    'repayment' => $this->getEmptyRepaymentKPIs(),
                ]);
            }

            $selectedBranchId = $request->get('branch_id');

            $dateRange = $request->get('range', 'quarterly');
            $dates = $this->calculateDateRange($dateRange, $request->get('start_date'), $request->get('end_date'));
            $startDate = $dates['start'];
            $endDate = $dates['end'];
            $previousStartDate = $dates['previous_start'];
            $previousEndDate = $dates['previous_end'];

            Log::info('getKPIs: Date range calculated', [
                'range' => $dateRange,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'previous_start' => $previousStartDate,
                'previous_end' => $previousEndDate
            ]);

            // Portfolio Growth KPIs
            Log::info('getKPIs: Fetching portfolio KPIs');
            $portfolioKPIs = $this->getPortfolioGrowthKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds);
            Log::info('getKPIs: Portfolio KPIs received', ['keys' => array_keys($portfolioKPIs)]);

            // Profitability KPIs
            Log::info('getKPIs: Fetching profitability KPIs');
            $profitabilityKPIs = $this->getProfitabilityKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds);
            Log::info('getKPIs: Profitability KPIs received', ['keys' => array_keys($profitabilityKPIs)]);

            // Risk & Portfolio Quality KPIs
            Log::info('getKPIs: Fetching risk KPIs');
            $riskKPIs = $this->getRiskKPIs($startDate, $endDate, $selectedBranchId, $userBranchIds);
            Log::info('getKPIs: Risk KPIs received', ['keys' => array_keys($riskKPIs)]);

            // Repayment Performance KPIs
            Log::info('getKPIs: Fetching repayment KPIs');
            $repaymentKPIs = $this->getRepaymentKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds);
            Log::info('getKPIs: Repayment KPIs received', ['keys' => array_keys($repaymentKPIs)]);

            $response = [
                'portfolio' => $portfolioKPIs,
                'profitability' => $profitabilityKPIs,
                'risk' => $riskKPIs,
                'repayment' => $repaymentKPIs,
            ];

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('getKPIs: Success', [
                'execution_time_ms' => $executionTime,
                'response_keys' => array_keys($response),
                'portfolio_total' => $portfolioKPIs['total_loan_portfolio']['value'] ?? 0
            ]);

            return response()->json($response);
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Error in getKPIs', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time_ms' => $executionTime
            ]);
            return response()->json([
                'error' => 'An error occurred while loading KPIs',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API endpoint to get chart data
     */
    public function getChartData(Request $request)
    {
        $startTime = microtime(true);
        try {
            $chartType = $request->get('chart_type');
            Log::info('getChartData called', [
                'chart_type' => $chartType,
                'params' => $request->all(),
                'user_id' => auth()->id()
            ]);

            $user = auth()->user();
            if (!$user) {
                Log::warning('getChartData: Unauthorized access attempt');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $company = $user->company;
            $branches = $user->branches()->where('company_id', $company->id)->get();
            $userBranchIds = $branches->pluck('id')->toArray();
            $selectedBranchId = $request->get('branch_id');

            $dateRange = $request->get('range', 'quarterly');
            $dates = $this->calculateDateRange($dateRange, $request->get('start_date'), $request->get('end_date'));
            $startDate = $dates['start'];
            $endDate = $dates['end'];

            Log::info('getChartData: Parameters', [
                'chart_type' => $chartType,
                'branch_ids' => $userBranchIds,
                'selected_branch' => $selectedBranchId,
                'date_range' => $dateRange,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            if (empty($userBranchIds)) {
                Log::info('getChartData: No branches found, returning empty data');
                return response()->json(['labels' => [], 'data' => []]);
            }

            if (empty($chartType)) {
                Log::warning('getChartData: Chart type is missing', ['request_params' => $request->all()]);
                return response()->json(['error' => 'Chart type is required'], 400);
            }

            $data = null;
            switch ($chartType) {
                case 'disbursement_trend':
                    $data = $this->getDisbursementTrend($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'portfolio_vs_repayments':
                    $data = $this->getPortfolioVsRepayments($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'par_aging':
                    $data = $this->getPARAging($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'product_performance':
                    $data = $this->getProductPerformance($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'customer_segmentation':
                    $data = $this->getCustomerSegmentation($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'top_customers':
                    $data = $this->getTopCustomers($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'branch_performance':
                    $data = $this->getBranchPerformance($startDate, $endDate, $userBranchIds);
                    break;
                case 'loan_status_distribution':
                    $data = $this->getLoanStatusDistribution($selectedBranchId, $userBranchIds);
                    break;
                case 'interest_income_trend':
                    $data = $this->getInterestIncomeTrend($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'repayment_rate_trend':
                    $data = $this->getRepaymentRateTrend($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                case 'sector_performance':
                    $data = $this->getSectorPerformance($startDate, $endDate, $selectedBranchId, $userBranchIds);
                    break;
                default:
                    Log::warning('getChartData: Invalid chart type', ['chart_type' => $chartType]);
                    return response()->json(['error' => 'Invalid chart type'], 400);
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('getChartData: Success', [
                'chart_type' => $chartType,
                'execution_time_ms' => $executionTime,
                'data_keys' => is_array($data) ? array_keys($data) : 'not_array',
                'labels_count' => isset($data['labels']) ? count($data['labels']) : 0,
                'data_count' => isset($data['data']) ? count($data['data']) : (is_array($data) ? count($data) : 0)
            ]);

            return response()->json($data);
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Error in getChartData', [
                'chart_type' => $request->get('chart_type'),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time_ms' => $executionTime
            ]);
            return response()->json(['error' => 'An error occurred while loading chart data'], 500);
        }
    }

    /**
     * API endpoint to get AI Insights
     */
    public function getAIInsights(Request $request)
    {
        $startTime = microtime(true);
        try {
            Log::info('getAIInsights called', [
                'params' => $request->all(),
                'user_id' => auth()->id()
            ]);

            $user = auth()->user();
            if (!$user) {
                Log::warning('getAIInsights: Unauthorized access attempt');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $company = $user->company;
            $branches = $user->branches()->where('company_id', $company->id)->get();
            $userBranchIds = $branches->pluck('id')->toArray();
            $selectedBranchId = $request->get('branch_id');

            $dateRange = $request->get('range', 'quarterly');
            $dates = $this->calculateDateRange($dateRange, $request->get('start_date'), $request->get('end_date'));
            $startDate = $dates['start'];
            $endDate = $dates['end'];
            $previousStartDate = $dates['previous_start'];
            $previousEndDate = $dates['previous_end'];

            Log::info('getAIInsights: Parameters', [
                'branch_ids' => $userBranchIds,
                'selected_branch' => $selectedBranchId,
                'date_range' => $dateRange,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            if (empty($userBranchIds)) {
                Log::info('getAIInsights: No branches found, returning empty insights');
                return response()->json([
                    'insights' => ['No data available for selected period.'],
                    'recommendations' => []
                ]);
            }

            $insights = $this->generateAIInsights($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('getAIInsights: Success', [
                'execution_time_ms' => $executionTime,
                'insights_count' => count($insights['insights'] ?? []),
                'recommendations_count' => count($insights['recommendations'] ?? [])
            ]);

            return response()->json($insights);
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Error in getAIInsights', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time_ms' => $executionTime
            ]);
            return response()->json([
                'insights' => ['An error occurred while generating insights.'],
                'recommendations' => []
            ], 500);
        }
    }

    /**
     * Calculate date range based on selection
     */
    private function calculateDateRange($range, $customStart = null, $customEnd = null)
    {
        $now = Carbon::now();

        if ($range === 'custom' && $customStart && $customEnd) {
            $start = Carbon::parse($customStart);
            $end = Carbon::parse($customEnd);
            $diff = $end->diffInDays($start);
            $previousStart = $start->copy()->subDays($diff + 1);
            $previousEnd = $start->copy()->subDay();
        } else {
            switch ($range) {
                case 'daily':
                    $start = $now->copy()->startOfDay();
                    $end = $now->copy()->endOfDay();
                    $previousStart = $start->copy()->subDay()->startOfDay();
                    $previousEnd = $start->copy()->subDay()->endOfDay();
                    break;
                case 'weekly':
                    $start = $now->copy()->startOfWeek();
                    $end = $now->copy()->endOfWeek();
                    $previousStart = $start->copy()->subWeek()->startOfWeek();
                    $previousEnd = $start->copy()->subWeek()->endOfWeek();
                    break;
                case 'monthly':
                    $start = $now->copy()->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                    $previousStart = $start->copy()->subMonth()->startOfMonth();
                    $previousEnd = $start->copy()->subMonth()->endOfMonth();
                    break;
                case 'quarterly':
                    $start = $now->copy()->startOfQuarter();
                    $end = $now->copy()->endOfQuarter();
                    $previousStart = $start->copy()->subQuarter()->startOfQuarter();
                    $previousEnd = $start->copy()->subQuarter()->endOfQuarter();
                    break;
                case 'yearly':
                    $start = $now->copy()->startOfYear();
                    $end = $now->copy()->endOfYear();
                    $previousStart = $start->copy()->subYear()->startOfYear();
                    $previousEnd = $start->copy()->subYear()->endOfYear();
                    break;
                default:
                    $start = $now->copy()->startOfQuarter();
                    $end = $now->copy()->endOfQuarter();
                    $previousStart = $start->copy()->subQuarter()->startOfQuarter();
                    $previousEnd = $start->copy()->subQuarter()->endOfQuarter();
            }
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'previous_start' => $previousStart->format('Y-m-d'),
            'previous_end' => $previousEnd->format('Y-m-d'),
        ];
    }

    /**
     * Get base loan query with filters
     */
    private function getBaseLoanQuery($selectedBranchId, $userBranchIds)
    {
        $query = Loan::query();

        if ($selectedBranchId) {
            $query->where('branch_id', $selectedBranchId);
        } else {
            $query->whereIn('branch_id', $userBranchIds);
        }

        return $query;
    }

    /**
     * Get Portfolio Growth KPIs - Optimized with single aggregated queries
     */
    private function getPortfolioGrowthKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds)
    {
        try {
            Log::debug('getPortfolioGrowthKPIs: Starting', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'branch_id' => $selectedBranchId
            ]);
            $branchCondition = $selectedBranchId
                ? ['loans.branch_id', '=', $selectedBranchId]
                : null;

            // Single query for active loans count
            $activeLoans = DB::table('loans')
                ->where('status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->count();

            // Total loan portfolio (outstanding balance) - single aggregated query
            $portfolioData = DB::table('loans')
                ->leftJoin('repayments', 'loans.id', '=', 'repayments.loan_id')
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    COALESCE(SUM(loans.amount), 0) as total_amount,
                    COALESCE(SUM(repayments.principal), 0) as total_principal_paid
                ')
                ->first();

            $totalLoanPortfolio = max(0, ($portfolioData->total_amount ?? 0) - ($portfolioData->total_principal_paid ?? 0));

            // Total disbursement in period - fresh query
            $totalDisbursement = (float) DB::table('loans')
                ->where('status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->whereBetween('disbursed_on', [$startDate, $endDate])
                ->sum('amount');

            // New loans in period - fresh query
            $newLoans = (int) DB::table('loans')
                ->where('status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->whereBetween('date_applied', [$startDate, $endDate])
                ->count();

            // Previous period active loans
            $previousActiveLoans = $activeLoans; // Active count is current state

            // Previous portfolio - single query
            $previousPortfolioData = DB::table('loans')
                ->leftJoin('repayments', function ($join) use ($previousEndDate) {
                    $join->on('loans.id', '=', 'repayments.loan_id')
                        ->whereDate('repayments.payment_date', '<=', $previousEndDate);
                })
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    COALESCE(SUM(DISTINCT loans.amount), 0) as total_amount,
                    COALESCE(SUM(repayments.principal), 0) as total_principal_paid
                ')
                ->first();

            $previousTotalPortfolio = max(0, ($previousPortfolioData->total_amount ?? 0) - ($previousPortfolioData->total_principal_paid ?? 0));

            // Previous disbursement - fresh query
            $previousTotalDisbursement = (float) DB::table('loans')
                ->where('status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->whereBetween('disbursed_on', [$previousStartDate, $previousEndDate])
                ->sum('amount');

            // Previous new loans - fresh query
            $previousNewLoans = (int) DB::table('loans')
                ->where('status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->whereBetween('date_applied', [$previousStartDate, $previousEndDate])
                ->count();

            $avgLoanSize = $newLoans > 0 ? $totalDisbursement / $newLoans : 0;
            $previousAvgLoanSize = $previousNewLoans > 0 ? $previousTotalDisbursement / $previousNewLoans : 0;

            Log::debug('getPortfolioGrowthKPIs: Data calculated', [
                'total_portfolio' => $totalLoanPortfolio,
                'total_disbursement' => $totalDisbursement,
                'active_loans' => $activeLoans,
                'new_loans' => $newLoans
            ]);

            return [
                'total_loan_portfolio' => [
                    'value' => $totalLoanPortfolio,
                    'previous' => $previousTotalPortfolio,
                    'change' => $previousTotalPortfolio > 0 ? (($totalLoanPortfolio - $previousTotalPortfolio) / $previousTotalPortfolio) * 100 : 0,
                ],
                'total_disbursement' => [
                    'value' => $totalDisbursement,
                    'previous' => $previousTotalDisbursement,
                    'change' => $previousTotalDisbursement > 0 ? (($totalDisbursement - $previousTotalDisbursement) / $previousTotalDisbursement) * 100 : 0,
                ],
                'active_loans' => [
                    'value' => $activeLoans,
                    'previous' => $previousActiveLoans,
                    'change' => $previousActiveLoans > 0 ? (($activeLoans - $previousActiveLoans) / $previousActiveLoans) * 100 : 0,
                ],
                'new_loans' => [
                    'value' => $newLoans,
                    'previous' => $previousNewLoans,
                    'change' => $previousNewLoans > 0 ? (($newLoans - $previousNewLoans) / $previousNewLoans) * 100 : 0,
                ],
                'avg_loan_size' => [
                    'value' => $avgLoanSize,
                    'previous' => $previousAvgLoanSize,
                    'change' => $previousAvgLoanSize > 0 ? (($avgLoanSize - $previousAvgLoanSize) / $previousAvgLoanSize) * 100 : 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error in getPortfolioGrowthKPIs: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return [
                'total_loan_portfolio' => ['value' => 0, 'previous' => 0, 'change' => 0],
                'total_disbursement' => ['value' => 0, 'previous' => 0, 'change' => 0],
                'active_loans' => ['value' => 0, 'previous' => 0, 'change' => 0],
                'new_loans' => ['value' => 0, 'previous' => 0, 'change' => 0],
                'avg_loan_size' => ['value' => 0, 'previous' => 0, 'change' => 0],
            ];
        }
    }

    /**
     * Get Profitability KPIs - Optimized with aggregated queries
     */
    private function getProfitabilityKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds)
    {
        try {
            // Get loan IDs for the branch filter - single query
            $loanIds = DB::table('loans')
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->pluck('id')
                ->toArray();

            if (empty($loanIds)) {
                return $this->getEmptyProfitabilityKPIs();
            }

            // Current period income - single aggregated query
            $currentIncome = DB::table('repayments')
                ->whereIn('loan_id', $loanIds)
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->selectRaw('
                    COALESCE(SUM(interest), 0) as interest_income,
                    COALESCE(SUM(fee_amount), 0) as fees_charges
                ')
                ->first();

            $interestIncome = (float) ($currentIncome->interest_income ?? 0);
            $feesCharges = (float) ($currentIncome->fees_charges ?? 0);

            // Previous period income - single aggregated query
            $previousIncome = DB::table('repayments')
                ->whereIn('loan_id', $loanIds)
                ->whereBetween('payment_date', [$previousStartDate, $previousEndDate])
                ->selectRaw('
                    COALESCE(SUM(interest), 0) as interest_income,
                    COALESCE(SUM(fee_amount), 0) as fees_charges
                ')
                ->first();

            $previousInterestIncome = (float) ($previousIncome->interest_income ?? 0);
            $previousFeesCharges = (float) ($previousIncome->fees_charges ?? 0);

            // Portfolio data for yield calculation - single aggregated query
            $portfolioData = DB::table('loans')
                ->leftJoin('repayments', 'loans.id', '=', 'repayments.loan_id')
                ->whereIn('loans.id', $loanIds)
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->selectRaw('
                    COUNT(DISTINCT loans.id) as loan_count,
                    COALESCE(SUM(DISTINCT loans.amount), 0) as total_amount,
                    COALESCE(SUM(repayments.principal), 0) as total_principal_paid,
                    COALESCE(SUM(DISTINCT loans.amount * loans.interest), 0) as weighted_interest
                ')
                ->first();

            $totalOutstanding = max(0, ($portfolioData->total_amount ?? 0) - ($portfolioData->total_principal_paid ?? 0));
            $loanCount = (int) ($portfolioData->loan_count ?? 0);
            $avgOutstanding = $loanCount > 0 ? $totalOutstanding / $loanCount : 0;
            $effectiveYield = $totalOutstanding > 0 ? ($interestIncome / $totalOutstanding) * 100 : 0;
            $avgInterestRate = $totalOutstanding > 0 ? ($portfolioData->weighted_interest ?? 0) / ($portfolioData->total_amount ?? 1) : 0;

            $netLoanRevenue = $interestIncome + $feesCharges;

            return [
                'interest_income' => [
                    'value' => $interestIncome,
                    'previous' => $previousInterestIncome,
                    'change' => $previousInterestIncome > 0 ? (($interestIncome - $previousInterestIncome) / $previousInterestIncome) * 100 : 0,
                ],
                'fees_charges' => [
                    'value' => $feesCharges,
                    'previous' => $previousFeesCharges,
                    'change' => $previousFeesCharges > 0 ? (($feesCharges - $previousFeesCharges) / $previousFeesCharges) * 100 : 0,
                ],
                'effective_yield' => [
                    'value' => $effectiveYield,
                    'previous' => 0,
                    'change' => 0,
                ],
                'avg_interest_rate' => [
                    'value' => $avgInterestRate,
                    'previous' => 0,
                    'change' => 0,
                ],
                'net_loan_revenue' => [
                    'value' => $netLoanRevenue,
                    'previous' => 0,
                    'change' => 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error in getProfitabilityKPIs: ' . $e->getMessage());
            return $this->getEmptyProfitabilityKPIs();
        }
    }

    /**
     * Get Risk & Portfolio Quality KPIs - Fully optimized with database aggregations
     */
    private function getRiskKPIs($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            // Get all loan data with outstanding balances and first overdue date in a single query
            $loansData = DB::table('loans')
                ->leftJoin('repayments', 'loans.id', '=', 'repayments.loan_id')
                ->leftJoin(DB::raw('(
                    SELECT loan_id, MIN(due_date) as first_overdue_date
                    FROM loan_schedules
                    WHERE due_date < "' . $today . '"
                    GROUP BY loan_id
                ) as overdue_schedules'), 'loans.id', '=', 'overdue_schedules.loan_id')
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    loans.id,
                    loans.amount,
                    loans.status,
                    COALESCE(SUM(repayments.principal), 0) as principal_paid,
                    overdue_schedules.first_overdue_date,
                    DATEDIFF("' . $today . '", overdue_schedules.first_overdue_date) as days_overdue
                ')
                ->groupBy('loans.id', 'loans.amount', 'loans.status', 'overdue_schedules.first_overdue_date')
                ->get();

            $totalPortfolio = 0;
            $par30Amount = 0;
            $par90Amount = 0;
            $nplAmount = 0;
            $defaultCount = 0;
            $totalLoans = $loansData->count();

            foreach ($loansData as $loan) {
                $outstandingBalance = max(0, ($loan->amount ?? 0) - ($loan->principal_paid ?? 0));
                $totalPortfolio += $outstandingBalance;

                $daysOverdue = (int) ($loan->days_overdue ?? 0);

                if ($daysOverdue > 30) {
                    $par30Amount += $outstandingBalance;
                }
                if ($daysOverdue > 90) {
                    $par90Amount += $outstandingBalance;
                    $nplAmount += $outstandingBalance;
                }
                if ($daysOverdue > 180 || $loan->status === Loan::STATUS_DEFAULTED) {
                    $defaultCount++;
                }
            }

            $par30Percent = $totalPortfolio > 0 ? ($par30Amount / $totalPortfolio) * 100 : 0;
            $par90Percent = $totalPortfolio > 0 ? ($par90Amount / $totalPortfolio) * 100 : 0;
            $nplPercent = $totalPortfolio > 0 ? ($nplAmount / $totalPortfolio) * 100 : 0;
            $defaultRate = $totalLoans > 0 ? ($defaultCount / $totalLoans) * 100 : 0;

            return [
                'par_30' => [
                    'value' => $par30Percent,
                    'amount' => $par30Amount,
                ],
                'par_90' => [
                    'value' => $par90Percent,
                    'amount' => $par90Amount,
                ],
                'npl' => [
                    'value' => $nplPercent,
                    'amount' => $nplAmount,
                ],
                'default_rate' => [
                    'value' => $defaultRate,
                    'count' => $defaultCount,
                ],
                'write_off_amount' => [
                    'value' => 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error in getRiskKPIs: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return $this->getEmptyRiskKPIs();
        }
    }

    /**
     * Get Repayment Performance KPIs - Optimized with database aggregations
     */
    private function getRepaymentKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            // Get loan IDs first
            $loanIds = DB::table('loans')
                ->where('status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('branch_id', $userBranchIds))
                ->pluck('id')
                ->toArray();

            if (empty($loanIds)) {
                return $this->getEmptyRepaymentKPIs();
            }

            // Current period - aggregated schedule data (due up to today)
            $scheduleData = DB::table('loan_schedules')
                ->whereIn('loan_id', $loanIds)
                ->whereDate('due_date', '<=', $today)
                ->selectRaw('
                    COALESCE(SUM(principal + COALESCE(accrued_interest, interest, 0) + COALESCE(fee_amount, 0)), 0) as total_due
                ')
                ->first();

            $totalDue = (float) ($scheduleData->total_due ?? 0);

            // Current period - aggregated repayment data (up to today)
            $repaymentData = DB::table('repayments')
                ->whereIn('loan_id', $loanIds)
                ->whereDate('payment_date', '<=', $today)
                ->selectRaw('
                    COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as total_paid
                ')
                ->first();

            $totalPaid = (float) ($repaymentData->total_paid ?? 0);

            // Overdue amount calculation - schedules with remaining balance
            $overdueData = DB::table('loan_schedules as ls')
                ->leftJoin(DB::raw('(
                    SELECT loan_schedule_id,
                           COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as paid
                    FROM repayments
                    GROUP BY loan_schedule_id
                ) as rep'), 'ls.id', '=', 'rep.loan_schedule_id')
                ->whereIn('ls.loan_id', $loanIds)
                ->whereDate('ls.due_date', '<', $today)
                ->selectRaw('
                    COALESCE(SUM(GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))), 0) as overdue_amount
                ')
                ->first();

            $overdueAmount = (float) ($overdueData->overdue_amount ?? 0);

            // Loans in arrears count - count loans with overdue schedules
            $loansInArrears = DB::table('loans')
                ->whereIn('loans.id', $loanIds)
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->whereExists(function ($sq) use ($today) {
                    $sq->select(DB::raw(1))
                        ->from('loan_schedules')
                        ->whereColumn('loan_schedules.loan_id', 'loans.id')
                        ->whereDate('loan_schedules.due_date', '<', $today);
                })
                ->count();

            // Average days in arrears - calculate from first overdue schedule
            $avgDaysData = DB::table('loans')
                ->join(DB::raw('(
                    SELECT loan_id, MIN(due_date) as first_overdue_date
                    FROM loan_schedules
                    WHERE due_date < "' . $today . '"
                    GROUP BY loan_id
                ) as overdue'), 'loans.id', '=', 'overdue.loan_id')
                ->whereIn('loans.id', $loanIds)
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->selectRaw('COALESCE(AVG(DATEDIFF("' . $today . '", overdue.first_overdue_date)), 0) as avg_days')
                ->first();

            $avgDaysInArrears = (float) ($avgDaysData->avg_days ?? 0);

            // Calculate rates
            $repaymentRate = $totalDue > 0 ? ($totalPaid / $totalDue) * 100 : 0;
            $collectionEfficiency = $repaymentRate; // Same calculation

            // Previous period calculations
            $previousScheduleData = DB::table('loan_schedules')
                ->whereIn('loan_id', $loanIds)
                ->whereDate('due_date', '<=', $previousEndDate)
                ->selectRaw('
                    COALESCE(SUM(principal + COALESCE(accrued_interest, interest, 0) + COALESCE(fee_amount, 0)), 0) as total_due
                ')
                ->first();

            $previousTotalDue = (float) ($previousScheduleData->total_due ?? 0);

            $previousRepaymentData = DB::table('repayments')
                ->whereIn('loan_id', $loanIds)
                ->whereDate('payment_date', '<=', $previousEndDate)
                ->selectRaw('
                    COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as total_paid
                ')
                ->first();

            $previousTotalPaid = (float) ($previousRepaymentData->total_paid ?? 0);
            $previousRepaymentRate = $previousTotalDue > 0 ? ($previousTotalPaid / $previousTotalDue) * 100 : 0;

            return [
                'repayment_rate' => [
                    'value' => $repaymentRate,
                    'previous' => $previousRepaymentRate,
                    'change' => $previousRepaymentRate > 0 ? (($repaymentRate - $previousRepaymentRate) / $previousRepaymentRate) * 100 : 0,
                ],
                'collection_efficiency' => [
                    'value' => $collectionEfficiency,
                    'previous' => $previousRepaymentRate,
                    'change' => $previousRepaymentRate > 0 ? (($collectionEfficiency - $previousRepaymentRate) / $previousRepaymentRate) * 100 : 0,
                ],
                'overdue_amount' => [
                    'value' => $overdueAmount,
                    'previous' => 0,
                    'change' => 0,
                ],
                'loans_in_arrears' => [
                    'value' => $loansInArrears,
                    'previous' => 0,
                    'change' => 0,
                ],
                'avg_days_in_arrears' => [
                    'value' => $avgDaysInArrears,
                    'previous' => 0,
                    'change' => 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error in getRepaymentKPIs: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            return $this->getEmptyRepaymentKPIs();
        }
    }

    /**
     * Get Disbursement Trend Chart Data
     */
    private function getDisbursementTrend($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $diff = $start->diffInDays($end);

        $labels = [];
        $data = [];

        if ($diff <= 31) {
            // Daily
            $current = $start->copy();
            while ($current->lte($end)) {
                $labels[] = $current->format('M d');
                $amount = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                    ->whereDate('disbursed_on', $current->format('Y-m-d'))
                    ->sum('amount');
                $data[] = $amount ?? 0;
                $current->addDay();
            }
        } elseif ($diff <= 93) {
            // Weekly
            $current = $start->copy()->startOfWeek();
            while ($current->lte($end)) {
                $weekEnd = $current->copy()->endOfWeek();
                if ($weekEnd->gt($end)) {
                    $weekEnd = $end->copy();
                }
                $labels[] = $current->format('M d') . ' - ' . $weekEnd->format('M d');
                $amount = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                    ->whereBetween('disbursed_on', [$current->format('Y-m-d'), $weekEnd->format('Y-m-d')])
                    ->sum('amount');
                $data[] = $amount ?? 0;
                $current->addWeek();
            }
        } else {
            // Monthly
            $current = $start->copy()->startOfMonth();
            while ($current->lte($end)) {
                $monthEnd = $current->copy()->endOfMonth();
                if ($monthEnd->gt($end)) {
                    $monthEnd = $end->copy();
                }
                $labels[] = $current->format('M Y');
                $amount = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                    ->whereBetween('disbursed_on', [$current->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                    ->sum('amount');
                $data[] = $amount ?? 0;
                $current->addMonth();
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Get Portfolio vs Repayments Chart Data - Optimized with database aggregations
     */
    private function getPortfolioVsRepayments($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            Log::debug('getPortfolioVsRepayments: Starting', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'branch_id' => $selectedBranchId
            ]);

            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $diff = $start->diffInDays($end);

            $labels = [];
            $outstandingData = [];
            $repaymentData = [];
            $portfolioData = [];

            // Get loan IDs once
            $baseQuery = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                ->where('status', Loan::STATUS_ACTIVE);

            if ($diff <= 31) {
                $current = $start->copy();
                while ($current->lte($end)) {
                    $labels[] = $current->format('M d');
                    $dateStr = $current->format('Y-m-d');

                    // Outstanding using single aggregated query
                    $outstandingQuery = DB::table('loans')
                        ->leftJoin('repayments', function ($join) use ($dateStr) {
                            $join->on('loans.id', '=', 'repayments.loan_id')
                                ->whereDate('repayments.payment_date', '<=', $dateStr);
                        })
                        ->where('loans.status', Loan::STATUS_ACTIVE)
                        ->whereDate('loans.disbursed_on', '<=', $dateStr)
                        ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                        ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                        ->selectRaw('COALESCE(SUM(loans.amount), 0) - COALESCE(SUM(repayments.principal), 0) as outstanding')
                        ->value('outstanding');

                    $outstanding = max(0, (float) ($outstandingQuery ?? 0));
                    $outstandingData[] = $outstanding;

                    // Repayments on this date - optimized
                    $repayments = DB::table('repayments')
                        ->join('loans', 'repayments.loan_id', '=', 'loans.id')
                        ->whereDate('repayments.payment_date', $dateStr)
                        ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                        ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                        ->sum(DB::raw('repayments.principal + repayments.interest + COALESCE(repayments.fee_amount, 0)'));

                    $repaymentData[] = (float) ($repayments ?? 0);
                    $portfolioData[] = $outstanding;

                    $current->addDay();
                }
            } else {
                $current = $start->copy()->startOfMonth();
                while ($current->lte($end)) {
                    $monthEnd = $current->copy()->endOfMonth();
                    if ($monthEnd->gt($end)) {
                        $monthEnd = $end->copy();
                    }
                    $labels[] = $current->format('M Y');
                    $monthEndStr = $monthEnd->format('Y-m-d');

                    // Outstanding using single aggregated query
                    $outstandingQuery = DB::table('loans')
                        ->leftJoin('repayments', function ($join) use ($monthEndStr) {
                            $join->on('loans.id', '=', 'repayments.loan_id')
                                ->whereDate('repayments.payment_date', '<=', $monthEndStr);
                        })
                        ->where('loans.status', Loan::STATUS_ACTIVE)
                        ->whereDate('loans.disbursed_on', '<=', $monthEndStr)
                        ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                        ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                        ->selectRaw('COALESCE(SUM(loans.amount), 0) - COALESCE(SUM(repayments.principal), 0) as outstanding')
                        ->value('outstanding');

                    $outstanding = max(0, (float) ($outstandingQuery ?? 0));
                    $outstandingData[] = $outstanding;

                    // Repayments in this period - optimized
                    $repayments = DB::table('repayments')
                        ->join('loans', 'repayments.loan_id', '=', 'loans.id')
                        ->whereBetween('repayments.payment_date', [$current->format('Y-m-d'), $monthEndStr])
                        ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                        ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                        ->sum(DB::raw('repayments.principal + repayments.interest + COALESCE(repayments.fee_amount, 0)'));

                    $repaymentData[] = (float) ($repayments ?? 0);
                    $portfolioData[] = $outstanding;

                    $current->addMonth();
                }
            }

            Log::debug('getPortfolioVsRepayments: Data calculated', [
                'labels_count' => count($labels),
                'outstanding_count' => count($outstandingData),
                'repayments_count' => count($repaymentData)
            ]);

            return [
                'labels' => $labels,
                'outstanding' => $outstandingData,
                'repayments' => $repaymentData,
                'portfolio' => $portfolioData,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getPortfolioVsRepayments', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'labels' => [],
                'outstanding' => [],
                'repayments' => [],
                'portfolio' => [],
            ];
        }
    }

    /**
     * Get PAR Aging Chart Data - Optimized with database aggregations
     */
    private function getPARAging($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            Log::debug('getPARAging: Starting', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'branch_id' => $selectedBranchId
            ]);

            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $diff = $start->diffInDays($end);

            $labels = [];
            $bucket0_30 = [];
            $bucket31_60 = [];
            $bucket61_90 = [];
            $bucket90_plus = [];
            $totalPar = [];

            // Get loan IDs once
            $loanIds = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                ->where('status', Loan::STATUS_ACTIVE)
                ->pluck('id')
                ->toArray();

            if (empty($loanIds)) {
                return [
                    'labels' => [],
                    'bucket_0_30' => [],
                    'bucket_31_60' => [],
                    'bucket_61_90' => [],
                    'bucket_90_plus' => [],
                    'total_par' => [],
                ];
            }

            if ($diff <= 31) {
                $current = $start->copy();
                while ($current->lte($end)) {
                    $labels[] = $current->format('M d');
                    $dateStr = $current->format('Y-m-d');

                    // Use database aggregation to calculate PAR buckets
                    $parData = DB::table('loan_schedules as ls')
                        ->leftJoin(DB::raw('(
                            SELECT loan_schedule_id,
                                   COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as paid
                            FROM repayments
                            WHERE payment_date <= "' . $dateStr . '"
                            GROUP BY loan_schedule_id
                        ) as rep'), 'ls.id', '=', 'rep.loan_schedule_id')
                        ->whereIn('ls.loan_id', $loanIds)
                        ->whereDate('ls.due_date', '<', $dateStr)
                        ->selectRaw('
                            SUM(CASE
                                WHEN DATEDIFF("' . $dateStr . '", ls.due_date) <= 30
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_0_30,
                            SUM(CASE
                                WHEN DATEDIFF("' . $dateStr . '", ls.due_date) > 30 AND DATEDIFF("' . $dateStr . '", ls.due_date) <= 60
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_31_60,
                            SUM(CASE
                                WHEN DATEDIFF("' . $dateStr . '", ls.due_date) > 60 AND DATEDIFF("' . $dateStr . '", ls.due_date) <= 90
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_61_90,
                            SUM(CASE
                                WHEN DATEDIFF("' . $dateStr . '", ls.due_date) > 90
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_90_plus
                        ')
                        ->first();

                    $par0_30 = (float) ($parData->par_0_30 ?? 0);
                    $par31_60 = (float) ($parData->par_31_60 ?? 0);
                    $par61_90 = (float) ($parData->par_61_90 ?? 0);
                    $par90_plus = (float) ($parData->par_90_plus ?? 0);

                    $bucket0_30[] = $par0_30;
                    $bucket31_60[] = $par31_60;
                    $bucket61_90[] = $par61_90;
                    $bucket90_plus[] = $par90_plus;
                    $totalPar[] = $par0_30 + $par31_60 + $par61_90 + $par90_plus;

                    $current->addDay();
                }
            } else {
                $current = $start->copy()->startOfMonth();
                while ($current->lte($end)) {
                    $monthEnd = $current->copy()->endOfMonth();
                    if ($monthEnd->gt($end)) {
                        $monthEnd = $end->copy();
                    }
                    $labels[] = $current->format('M Y');
                    $monthEndStr = $monthEnd->format('Y-m-d');

                    // Use database aggregation to calculate PAR buckets
                    $parData = DB::table('loan_schedules as ls')
                        ->leftJoin(DB::raw('(
                            SELECT loan_schedule_id,
                                   COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as paid
                            FROM repayments
                            WHERE payment_date <= "' . $monthEndStr . '"
                            GROUP BY loan_schedule_id
                        ) as rep'), 'ls.id', '=', 'rep.loan_schedule_id')
                        ->whereIn('ls.loan_id', $loanIds)
                        ->whereDate('ls.due_date', '<', $monthEndStr)
                        ->selectRaw('
                            SUM(CASE
                                WHEN DATEDIFF("' . $monthEndStr . '", ls.due_date) <= 30
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_0_30,
                            SUM(CASE
                                WHEN DATEDIFF("' . $monthEndStr . '", ls.due_date) > 30 AND DATEDIFF("' . $monthEndStr . '", ls.due_date) <= 60
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_31_60,
                            SUM(CASE
                                WHEN DATEDIFF("' . $monthEndStr . '", ls.due_date) > 60 AND DATEDIFF("' . $monthEndStr . '", ls.due_date) <= 90
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_61_90,
                            SUM(CASE
                                WHEN DATEDIFF("' . $monthEndStr . '", ls.due_date) > 90
                                THEN GREATEST(0, (ls.principal + COALESCE(ls.accrued_interest, ls.interest, 0) + COALESCE(ls.fee_amount, 0)) - COALESCE(rep.paid, 0))
                                ELSE 0
                            END) as par_90_plus
                        ')
                        ->first();

                    $par0_30 = (float) ($parData->par_0_30 ?? 0);
                    $par31_60 = (float) ($parData->par_31_60 ?? 0);
                    $par61_90 = (float) ($parData->par_61_90 ?? 0);
                    $par90_plus = (float) ($parData->par_90_plus ?? 0);

                    $bucket0_30[] = $par0_30;
                    $bucket31_60[] = $par31_60;
                    $bucket61_90[] = $par61_90;
                    $bucket90_plus[] = $par90_plus;
                    $totalPar[] = $par0_30 + $par31_60 + $par61_90 + $par90_plus;

                    $current->addMonth();
                }
            }

            Log::debug('getPARAging: Data calculated', [
                'labels_count' => count($labels),
                'total_par_sum' => array_sum($totalPar)
            ]);

            return [
                'labels' => $labels,
                'bucket_0_30' => $bucket0_30,
                'bucket_31_60' => $bucket31_60,
                'bucket_61_90' => $bucket61_90,
                'bucket_90_plus' => $bucket90_plus,
                'total_par' => $totalPar,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getPARAging', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'labels' => [],
                'bucket_0_30' => [],
                'bucket_31_60' => [],
                'bucket_61_90' => [],
                'bucket_90_plus' => [],
                'total_par' => [],
            ];
        }
    }

    /**
     * Get Product Performance Chart Data - Optimized with database aggregations
     */
    private function getProductPerformance($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            // Get principal paid per loan first
            $principalPaid = DB::table('repayments')
                ->selectRaw('loan_id, COALESCE(SUM(principal), 0) as principal_paid')
                ->groupBy('loan_id')
                ->pluck('principal_paid', 'loan_id')
                ->toArray();

            // Get portfolio data by product using aggregation
            $portfolioData = DB::table('loans')
                ->leftJoin('products', 'loans.product_id', '=', 'products.id')
                ->leftJoin(DB::raw('(
                    SELECT loan_id, MIN(due_date) as first_overdue_date
                    FROM loan_schedules
                    WHERE due_date < "' . $today . '"
                    GROUP BY loan_id
                ) as overdue_schedules'), 'loans.id', '=', 'overdue_schedules.loan_id')
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    loans.id,
                    COALESCE(products.name, "Unknown") as product_name,
                    loans.amount,
                    overdue_schedules.first_overdue_date
                ')
                ->get();

            $productsData = [];
            foreach ($portfolioData as $row) {
                $productName = $row->product_name ?? 'Unknown';
                $principalPaidAmount = (float) ($principalPaid[$row->id] ?? 0);
                $outstanding = max(0, ($row->amount ?? 0) - $principalPaidAmount);

                if (!isset($productsData[$productName])) {
                    $productsData[$productName] = ['portfolio' => 0, 'npl_amount' => 0];
                }

                $productsData[$productName]['portfolio'] += $outstanding;

                if ($row->first_overdue_date) {
                    $daysOverdue = Carbon::parse($row->first_overdue_date)->diffInDays($today);
                    if ($daysOverdue > 90) {
                        $productsData[$productName]['npl_amount'] += $outstanding;
                    }
                }
            }

            // Get interest income by product for the period
            $interestData = DB::table('repayments')
                ->join('loans', 'repayments.loan_id', '=', 'loans.id')
                ->leftJoin('products', 'loans.product_id', '=', 'products.id')
                ->whereBetween('repayments.payment_date', [$startDate, $endDate])
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    COALESCE(products.name, "Unknown") as product_name,
                    SUM(repayments.interest) as interest_received
                ')
                ->groupBy('products.id', 'products.name')
                ->pluck('interest_received', 'product_name')
                ->toArray();

            $products = [];
            foreach ($productsData as $productName => $data) {
                $interestReceived = (float) ($interestData[$productName] ?? 0);
                $products[$productName] = [
                    'portfolio' => $data['portfolio'],
                    'npl_amount' => $data['npl_amount'],
                    'interest_received' => $interestReceived,
                ];
            }

            $labels = array_keys($products);
            $portfolioDataArray = [];
            $nplPercentData = [];
            $yieldData = [];

            foreach ($products as $productName => $data) {
                $portfolioDataArray[] = $data['portfolio'];
                $nplPercent = $data['portfolio'] > 0 ? ($data['npl_amount'] / $data['portfolio']) * 100 : 0;
                $nplPercentData[] = $nplPercent;
                $yield = $data['portfolio'] > 0 ? ($data['interest_received'] / $data['portfolio']) * 100 : 0;
                $yieldData[] = $yield;
            }

            return [
                'labels' => $labels,
                'portfolio' => $portfolioDataArray,
                'npl_percent' => $nplPercentData,
                'yield' => $yieldData,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getProductPerformance: ' . $e->getMessage());
            return [
                'labels' => [],
                'portfolio' => [],
                'npl_percent' => [],
                'yield' => [],
            ];
        }
    }

    /**
     * Get Customer Segmentation Chart Data - Optimized with database aggregations
     */
    private function getCustomerSegmentation($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            // Get principal paid per loan first
            $principalPaid = DB::table('repayments')
                ->selectRaw('loan_id, COALESCE(SUM(principal), 0) as principal_paid')
                ->groupBy('loan_id')
                ->pluck('principal_paid', 'loan_id')
                ->toArray();

            // Get outstanding balances aggregated by group_id and sector
            $segmentationData = DB::table('loans')
                ->leftJoin('sectors', 'loans.sector_id', '=', 'sectors.id')
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    loans.id,
                    loans.group_id,
                    loans.amount,
                    COALESCE(sectors.name, "Other") as sector_name
                ')
                ->get();

            $individual = 0;
            $group = 0;
            $msme = 0;
            $agriculture = 0;
            $other = 0;

            foreach ($segmentationData as $row) {
                $principalPaidAmount = (float) ($principalPaid[$row->id] ?? 0);
                $outstanding = max(0, ($row->amount ?? 0) - $principalPaidAmount);

                // Individual vs Group
                if ($row->group_id) {
                    $group += $outstanding;
                } else {
                    $individual += $outstanding;
                }

                // Sector-based
                $sectorName = strtolower($row->sector_name ?? 'Other');
                if (strpos($sectorName, 'agriculture') !== false || strpos($sectorName, 'agri') !== false) {
                    $agriculture += $outstanding;
                } elseif (strpos($sectorName, 'sme') !== false || strpos($sectorName, 'msme') !== false || strpos($sectorName, 'small') !== false) {
                    $msme += $outstanding;
                } else {
                    $other += $outstanding;
                }
            }

            return [
                'individual' => $individual,
                'group' => $group,
                'msme' => $msme,
                'agriculture' => $agriculture,
                'other' => $other,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getCustomerSegmentation: ' . $e->getMessage());
            return [
                'individual' => 0,
                'group' => 0,
                'msme' => 0,
                'agriculture' => 0,
                'other' => 0,
            ];
        }
    }

    /**
     * Get Top Customers Data - Optimized with database aggregations
     */
    private function getTopCustomers($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            // Get principal paid per loan first
            $principalPaid = DB::table('repayments')
                ->selectRaw('loan_id, COALESCE(SUM(principal), 0) as principal_paid')
                ->groupBy('loan_id')
                ->pluck('principal_paid', 'loan_id')
                ->toArray();

            // Get customer data with aggregated balances and overdue days
            $customersData = DB::table('loans')
                ->leftJoin('customers', 'loans.customer_id', '=', 'customers.id')
                ->leftJoin(DB::raw('(
                    SELECT loan_id, MIN(due_date) as first_overdue_date
                    FROM loan_schedules
                    WHERE due_date < "' . $today . '"
                    GROUP BY loan_id
                ) as overdue_schedules'), 'loans.id', '=', 'overdue_schedules.loan_id')
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
                ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds))
                ->selectRaw('
                    loans.customer_id,
                    loans.id as loan_id,
                    loans.amount,
                    COALESCE(customers.name, "Unknown") as customer_name,
                    overdue_schedules.first_overdue_date
                ')
                ->get();

            $customerStats = [];
            foreach ($customersData as $row) {
                $customerId = $row->customer_id;
                if (!isset($customerStats[$customerId])) {
                    $customerStats[$customerId] = [
                        'name' => $row->customer_name ?? 'Unknown',
                        'balance' => 0,
                        'days_in_arrears' => 0,
                    ];
                }

                $principalPaidAmount = (float) ($principalPaid[$row->loan_id] ?? 0);
                $outstanding = max(0, ($row->amount ?? 0) - $principalPaidAmount);
                $customerStats[$customerId]['balance'] += $outstanding;

                if ($row->first_overdue_date) {
                    $daysOverdue = Carbon::parse($row->first_overdue_date)->diffInDays($today);
                    if ($daysOverdue > $customerStats[$customerId]['days_in_arrears']) {
                        $customerStats[$customerId]['days_in_arrears'] = $daysOverdue;
                    }
                }
            }

            // Calculate risk scores and sort by balance
            $customers = [];
            foreach ($customerStats as $customerId => $stats) {
                $daysOverdue = $stats['days_in_arrears'];
                $riskScore = min(100, max(0, 100 - (100 * ($daysOverdue / 365))));

                $customers[] = [
                    'name' => $stats['name'],
                    'balance' => $stats['balance'],
                    'risk_score' => round($riskScore),
                    'days_in_arrears' => $daysOverdue,
                ];
            }

            // Sort by balance and get top 10
            usort($customers, function ($a, $b) {
                return $b['balance'] <=> $a['balance'];
            });

            return array_slice($customers, 0, 10);
        } catch (\Exception $e) {
            Log::error('Error in getTopCustomers: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get Branch Performance Data - Optimized with database aggregations
     */
    private function getBranchPerformance($startDate, $endDate, $userBranchIds)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            // Get principal paid per loan first
            $principalPaid = DB::table('repayments')
                ->selectRaw('loan_id, COALESCE(SUM(principal), 0) as principal_paid')
                ->groupBy('loan_id')
                ->pluck('principal_paid', 'loan_id')
                ->toArray();

            // Get branch performance using aggregated query
            $performanceData = DB::table('branches')
                ->leftJoin('loans', 'branches.id', '=', 'loans.branch_id')
                ->leftJoin(DB::raw('(
                    SELECT loan_id, MIN(due_date) as first_overdue_date
                    FROM loan_schedules
                    WHERE due_date < "' . $today . '"
                    GROUP BY loan_id
                ) as overdue_schedules'), 'loans.id', '=', 'overdue_schedules.loan_id')
                ->whereIn('branches.id', $userBranchIds)
                ->where('loans.status', Loan::STATUS_ACTIVE)
                ->selectRaw('
                    branches.id,
                    branches.name,
                    loans.id as loan_id,
                    loans.amount,
                    loans.disbursed_on,
                    overdue_schedules.first_overdue_date
                ')
                ->get();

            $branchStats = [];
            foreach ($performanceData as $row) {
                $branchId = $row->id;
                if (!isset($branchStats[$branchId])) {
                    $branchStats[$branchId] = [
                        'name' => $row->name ?? 'Unknown',
                        'portfolio' => 0,
                        'disbursement' => 0,
                        'active_loans' => 0,
                        'par30_amount' => 0,
                    ];
                }

                if ($row->loan_id) {
                    $branchStats[$branchId]['active_loans']++;
                    $principalPaidAmount = (float) ($principalPaid[$row->loan_id] ?? 0);
                    $outstanding = max(0, ($row->amount ?? 0) - $principalPaidAmount);
                    $branchStats[$branchId]['portfolio'] += $outstanding;

                    if (
                        $row->disbursed_on &&
                        Carbon::parse($row->disbursed_on)->between($startDate, $endDate)
                    ) {
                        $branchStats[$branchId]['disbursement'] += $row->amount ?? 0;
                    }

                    if ($row->first_overdue_date) {
                        $daysOverdue = Carbon::parse($row->first_overdue_date)->diffInDays($today);
                        if ($daysOverdue > 30) {
                            $branchStats[$branchId]['par30_amount'] += $outstanding;
                        }
                    }
                }
            }

            $performance = [];
            foreach ($branchStats as $branchId => $stats) {
                $par30Percent = $stats['portfolio'] > 0 ? ($stats['par30_amount'] / $stats['portfolio']) * 100 : 0;
                $performance[] = [
                    'name' => $stats['name'],
                    'portfolio' => $stats['portfolio'],
                    'disbursement' => $stats['disbursement'],
                    'active_loans' => $stats['active_loans'],
                    'par_30' => $par30Percent,
                ];
            }

            // Sort by portfolio
            usort($performance, function ($a, $b) {
                return $b['portfolio'] <=> $a['portfolio'];
            });

            return $performance;
        } catch (\Exception $e) {
            Log::error('Error in getBranchPerformance: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get Loan Status Distribution Chart Data
     */
    private function getLoanStatusDistribution($selectedBranchId, $userBranchIds)
    {
        $statuses = [
            Loan::STATUS_APPLIED => 'Applied',
            Loan::STATUS_CHECKED => 'Checked',
            Loan::STATUS_APPROVED => 'Approved',
            Loan::STATUS_AUTHORIZED => 'Authorized',
            Loan::STATUS_ACTIVE => 'Active',
            Loan::STATUS_REJECTED => 'Rejected',
            Loan::STATUS_DEFAULTED => 'Defaulted',
            Loan::STATUS_COMPLETE => 'Completed',
            Loan::STATUS_RESTRUCTURED => 'Restructured',
        ];

        // Use single aggregated query instead of multiple queries
        $query = DB::table('loans')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->when($selectedBranchId, function ($q) use ($selectedBranchId) {
                return $q->where('branch_id', $selectedBranchId);
            }, function ($q) use ($userBranchIds) {
                return $q->whereIn('branch_id', $userBranchIds);
            })
            ->groupBy('status')
            ->pluck('count', 'status');

        $data = [];
        $labels = [];

        foreach ($statuses as $status => $label) {
            $count = (int) ($query[$status] ?? 0);
            if ($count > 0) {
                $labels[] = $label;
                $data[] = $count;
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    /**
     * Get Interest Income Trend Chart Data
     */
    private function getInterestIncomeTrend($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $diff = $start->diffInDays($end);

            $labels = [];
            $data = [];

            // Optimized: Get loan IDs first
            $baseQuery = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds);
            $loanIds = $baseQuery->pluck('id')->toArray();

            if (empty($loanIds)) {
                return [
                    'labels' => [],
                    'data' => [],
                ];
            }

            if ($diff <= 31) {
                // Daily
                $current = $start->copy();
                while ($current->lte($end)) {
                    $labels[] = $current->format('M d');
                    $interest = DB::table('repayments')
                        ->whereIn('loan_id', $loanIds)
                        ->whereDate('payment_date', $current->format('Y-m-d'))
                        ->sum('interest');
                    $data[] = $interest ?? 0;
                    $current->addDay();
                }
            } elseif ($diff <= 93) {
                // Weekly
                $current = $start->copy()->startOfWeek();
                while ($current->lte($end)) {
                    $weekEnd = $current->copy()->endOfWeek();
                    if ($weekEnd->gt($end)) {
                        $weekEnd = $end->copy();
                    }
                    $labels[] = $current->format('M d') . ' - ' . $weekEnd->format('M d');
                    $interest = DB::table('repayments')
                        ->whereIn('loan_id', $loanIds)
                        ->whereBetween('payment_date', [$current->format('Y-m-d'), $weekEnd->format('Y-m-d')])
                        ->sum('interest');
                    $data[] = $interest ?? 0;
                    $current->addWeek();
                }
            } else {
                // Monthly
                $current = $start->copy()->startOfMonth();
                while ($current->lte($end)) {
                    $monthEnd = $current->copy()->endOfMonth();
                    if ($monthEnd->gt($end)) {
                        $monthEnd = $end->copy();
                    }
                    $labels[] = $current->format('M Y');
                    $interest = DB::table('repayments')
                        ->whereIn('loan_id', $loanIds)
                        ->whereBetween('payment_date', [$current->format('Y-m-d'), $monthEnd->format('Y-m-d')])
                        ->sum('interest');
                    $data[] = $interest ?? 0;
                    $current->addMonth();
                }
            }

            return [
                'labels' => $labels,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getInterestIncomeTrend: ' . $e->getMessage());
            return [
                'labels' => [],
                'data' => [],
            ];
        }
    }

    /**
     * Get Repayment Rate Trend Chart Data
     * Simplified version using database aggregations
     */
    private function getRepaymentRateTrend($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            $start = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);
            $diff = $start->diffInDays($end);

            $labels = [];
            $repaymentRateData = [];
            $collectionEfficiencyData = [];

            $baseQuery = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                ->where('status', Loan::STATUS_ACTIVE);

            $loanIds = $baseQuery->pluck('id')->toArray();

            if (empty($loanIds)) {
                return [
                    'labels' => [],
                    'repayment_rate' => [],
                    'collection_efficiency' => [],
                ];
            }

            // Use simplified calculation based on repayments vs total due
            if ($diff <= 31) {
                // Daily - use aggregated data
                $current = $start->copy();
                while ($current->lte($end)) {
                    $labels[] = $current->format('M d');

                    // Get total due (all schedules due up to this date)
                    $totalDue = DB::table('loan_schedules')
                        ->whereIn('loan_id', $loanIds)
                        ->whereDate('due_date', '<=', $current->format('Y-m-d'))
                        ->selectRaw('COALESCE(SUM(principal + COALESCE(accrued_interest, interest, 0) + COALESCE(fee_amount, 0)), 0) as total_due')
                        ->value('total_due') ?? 0;

                    // Get total paid (all repayments up to this date)
                    $totalPaid = DB::table('repayments')
                        ->whereIn('loan_id', $loanIds)
                        ->whereDate('payment_date', '<=', $current->format('Y-m-d'))
                        ->selectRaw('COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as total_paid')
                        ->value('total_paid') ?? 0;

                    $repaymentRate = $totalDue > 0 ? ($totalPaid / $totalDue) * 100 : 0;
                    $repaymentRateData[] = $repaymentRate;
                    $collectionEfficiencyData[] = $repaymentRate;

                    $current->addDay();
                }
            } else {
                // Monthly - use aggregated data
                $current = $start->copy()->startOfMonth();
                while ($current->lte($end)) {
                    $monthEnd = $current->copy()->endOfMonth();
                    if ($monthEnd->gt($end)) {
                        $monthEnd = $end->copy();
                    }
                    $labels[] = $current->format('M Y');

                    // Get total due (all schedules due up to month end)
                    $totalDue = DB::table('loan_schedules')
                        ->whereIn('loan_id', $loanIds)
                        ->whereDate('due_date', '<=', $monthEnd->format('Y-m-d'))
                        ->selectRaw('COALESCE(SUM(principal + COALESCE(accrued_interest, interest, 0) + COALESCE(fee_amount, 0)), 0) as total_due')
                        ->value('total_due') ?? 0;

                    // Get total paid (all repayments up to month end)
                    $totalPaid = DB::table('repayments')
                        ->whereIn('loan_id', $loanIds)
                        ->whereDate('payment_date', '<=', $monthEnd->format('Y-m-d'))
                        ->selectRaw('COALESCE(SUM(principal + interest + COALESCE(fee_amount, 0)), 0) as total_paid')
                        ->value('total_paid') ?? 0;

                    $repaymentRate = $totalDue > 0 ? ($totalPaid / $totalDue) * 100 : 0;
                    $repaymentRateData[] = $repaymentRate;
                    $collectionEfficiencyData[] = $repaymentRate;

                    $current->addMonth();
                }
            }

            return [
                'labels' => $labels,
                'repayment_rate' => $repaymentRateData,
                'collection_efficiency' => $collectionEfficiencyData,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getRepaymentRateTrend: ' . $e->getMessage());
            return [
                'labels' => [],
                'repayment_rate' => [],
                'collection_efficiency' => [],
            ];
        }
    }

    /**
     * Get Sector Performance Chart Data
     */
    private function getSectorPerformance($startDate, $endDate, $selectedBranchId, $userBranchIds)
    {
        try {
            // Optimized: Use database aggregations instead of N+1 queries
            $baseQuery = $this->getBaseLoanQuery($selectedBranchId, $userBranchIds)
                ->where('status', Loan::STATUS_ACTIVE);

            $loanIds = $baseQuery->pluck('id')->toArray();

            if (empty($loanIds)) {
                return [
                    'labels' => [],
                    'portfolio' => [],
                    'disbursement' => [],
                    'interest_income' => [],
                ];
            }

            // Get loans with sectors
            $loans = $baseQuery->with('sector')->get();

            // Get principal paid per loan using aggregation
            $principalPaid = DB::table('repayments')
                ->whereIn('loan_id', $loanIds)
                ->selectRaw('loan_id, COALESCE(SUM(principal), 0) as principal_paid')
                ->groupBy('loan_id')
                ->pluck('principal_paid', 'loan_id')
                ->toArray();

            // Get interest income per loan for the period
            $interestIncome = DB::table('repayments')
                ->whereIn('loan_id', $loanIds)
                ->whereBetween('payment_date', [$startDate, $endDate])
                ->selectRaw('loan_id, COALESCE(SUM(interest), 0) as interest_income')
                ->groupBy('loan_id')
                ->pluck('interest_income', 'loan_id')
                ->toArray();

            $sectors = [];

            foreach ($loans as $loan) {
                $sectorName = $loan->sector ? $loan->sector->name : 'Other';

                if (!isset($sectors[$sectorName])) {
                    $sectors[$sectorName] = [
                        'portfolio' => 0,
                        'disbursement' => 0,
                        'interest_income' => 0,
                        'loan_count' => 0,
                    ];
                }

                $principalPaidAmount = $principalPaid[$loan->id] ?? 0;
                $outstandingBalance = max(0, ($loan->amount ?? 0) - $principalPaidAmount);
                $sectors[$sectorName]['portfolio'] += $outstandingBalance;
                $sectors[$sectorName]['loan_count'] += 1;

                if (
                    $loan->disbursed_on &&
                    Carbon::parse($loan->disbursed_on)->between($startDate, $endDate)
                ) {
                    $sectors[$sectorName]['disbursement'] += $loan->amount ?? 0;
                }

                $sectors[$sectorName]['interest_income'] += $interestIncome[$loan->id] ?? 0;
            }

            // Sort by portfolio
            uasort($sectors, function ($a, $b) {
                return $b['portfolio'] <=> $a['portfolio'];
            });

            $labels = array_keys($sectors);
            $portfolioData = [];
            $disbursementData = [];
            $interestData = [];

            foreach ($sectors as $sector => $data) {
                $portfolioData[] = $data['portfolio'];
                $disbursementData[] = $data['disbursement'];
                $interestData[] = $data['interest_income'];
            }

            return [
                'labels' => $labels,
                'portfolio' => $portfolioData,
                'disbursement' => $disbursementData,
                'interest_income' => $interestData,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getSectorPerformance: ' . $e->getMessage());
            return [
                'labels' => [],
                'portfolio' => [],
                'disbursement' => [],
                'interest_income' => [],
            ];
        }
    }

    /**
     * Generate AI Insights
     */
    private function generateAIInsights($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds)
    {
        $insights = [];

        // Get current and previous period KPIs
        $currentRisk = $this->getRiskKPIs($startDate, $endDate, $selectedBranchId, $userBranchIds);
        $previousRisk = $this->getRiskKPIs($previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds);
        $currentRepayment = $this->getRepaymentKPIs($startDate, $endDate, $previousStartDate, $previousEndDate, $selectedBranchId, $userBranchIds);

        // PAR >30 analysis
        $par30Change = $currentRisk['par_30']['value'] - $previousRisk['par_30']['value'];
        if ($par30Change > 0) {
            $insights[] = sprintf(
                "PAR >30 Days increased to %.1f%%, up by %.1f%% from previous period. ",
                $currentRisk['par_30']['value'],
                $par30Change
            );
        } else {
            $insights[] = sprintf(
                "PAR >30 Days improved to %.1f%%, down by %.1f%% from previous period. ",
                $currentRisk['par_30']['value'],
                abs($par30Change)
            );
        }

        // Sector analysis - optimized with database aggregation
        $today = Carbon::now()->format('Y-m-d');

        // Get principal paid per loan first
        $principalPaid = DB::table('repayments')
            ->selectRaw('loan_id, COALESCE(SUM(principal), 0) as principal_paid')
            ->groupBy('loan_id')
            ->pluck('principal_paid', 'loan_id')
            ->toArray();

        $sectorRiskQuery = DB::table('loans')
            ->leftJoin(DB::raw('(
                SELECT loan_id, MIN(due_date) as first_overdue_date
                FROM loan_schedules
                WHERE due_date < "' . $today . '"
                GROUP BY loan_id
            ) as overdue_schedules'), 'loans.id', '=', 'overdue_schedules.loan_id')
            ->where('loans.status', Loan::STATUS_ACTIVE)
            ->when($selectedBranchId, fn($q) => $q->where('loans.branch_id', $selectedBranchId))
            ->when(!$selectedBranchId, fn($q) => $q->whereIn('loans.branch_id', $userBranchIds));

        if (Schema::hasTable('sectors')) {
            $sectorRiskQuery->leftJoin('sectors', 'loans.sector_id', '=', 'sectors.id')
                ->selectRaw('
                    loans.id,
                    loans.amount,
                    COALESCE(sectors.name, "Other") as sector_name,
                    overdue_schedules.first_overdue_date
                ');
        } else {
            // Fallback when sectors table is not present
            $sectorRiskQuery->selectRaw('
                loans.id,
                loans.amount,
                "Other" as sector_name,
                overdue_schedules.first_overdue_date
            ');
        }

        $sectorRiskData = $sectorRiskQuery->get();

        $sectorRisk = [];
        foreach ($sectorRiskData as $row) {
            $sectorName = $row->sector_name ?? 'Other';
            if (!isset($sectorRisk[$sectorName])) {
                $sectorRisk[$sectorName] = ['total' => 0, 'par30' => 0];
            }

            $principalPaidAmount = (float) ($principalPaid[$row->id] ?? 0);
            $outstanding = max(0, ($row->amount ?? 0) - $principalPaidAmount);
            $sectorRisk[$sectorName]['total'] += $outstanding;

            if ($row->first_overdue_date) {
                $daysOverdue = Carbon::parse($row->first_overdue_date)->diffInDays($today);
                if ($daysOverdue > 30) {
                    $sectorRisk[$sectorName]['par30'] += $outstanding;
                }
            }
        }

        $highRiskSector = null;
        $maxPar = 0;
        foreach ($sectorRisk as $sector => $data) {
            $parPercent = $data['total'] > 0 ? ($data['par30'] / $data['total']) * 100 : 0;
            if ($parPercent > $maxPar && $parPercent > 5) {
                $maxPar = $parPercent;
                $highRiskSector = $sector;
            }
        }

        if ($highRiskSector) {
            $insights[] = sprintf("High-risk sector identified: %s with %.1f%% PAR >30. ", $highRiskSector, $maxPar);
        }

        // Forecast
        if ($par30Change > 0) {
            $forecastPar = min(100, $currentRisk['par_30']['value'] + ($par30Change * 1.2));
            $insights[] = sprintf("Forecast indicates PAR >30 may rise to %.1f%% next period if current trends continue. ", $forecastPar);
        }

        // Collection recommendations
        if ($currentRepayment['overdue_amount']['value'] > 0) {
            $insights[] = "Focus collections on past due loans to improve cash flow. ";
        }

        if ($currentRisk['par_90']['value'] > 5) {
            $insights[] = "High-risk accounts need immediate review. ";
        }

        return [
            'insights' => $insights,
            'recommendations' => [
                'Increase collections on overdue loans',
                'Review high-risk sector exposure',
                'Monitor PAR trends closely',
            ],
        ];
    }

    /**
     * Helper methods for empty KPIs
     */
    private function getEmptyPortfolioKPIs()
    {
        return [
            'total_loan_portfolio' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'total_disbursement' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'active_loans' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'new_loans' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'avg_loan_size' => ['value' => 0, 'previous' => 0, 'change' => 0],
        ];
    }

    private function getEmptyProfitabilityKPIs()
    {
        return [
            'interest_income' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'fees_charges' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'effective_yield' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'avg_interest_rate' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'net_loan_revenue' => ['value' => 0, 'previous' => 0, 'change' => 0],
        ];
    }

    private function getEmptyRiskKPIs()
    {
        return [
            'par_30' => ['value' => 0, 'amount' => 0],
            'par_90' => ['value' => 0, 'amount' => 0],
            'npl' => ['value' => 0, 'amount' => 0],
            'default_rate' => ['value' => 0, 'count' => 0],
            'write_off_amount' => ['value' => 0],
        ];
    }

    private function getEmptyRepaymentKPIs()
    {
        return [
            'repayment_rate' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'collection_efficiency' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'overdue_amount' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'loans_in_arrears' => ['value' => 0, 'previous' => 0, 'change' => 0],
            'avg_days_in_arrears' => ['value' => 0, 'previous' => 0, 'change' => 0],
        ];
    }
}
