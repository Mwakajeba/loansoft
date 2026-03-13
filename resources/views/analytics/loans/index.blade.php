@extends('layouts.main')

@section('title', 'Loan Analytics Dashboard')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <!-- Header Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card border-top border-0 border-4" style="border-color: #006400 !important;">
                        <div class="card-body p-4">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <div class="card-title d-flex align-items-center">
                                        <div><i class="bx bx-line-chart me-1 font-22"
                                                style="color: #006400 !important;"></i></div>
                                        <h5 class="mb-0" style="color: #006400 !important;">Executive Loan Analytics
                                            Dashboard</h5>
                                    </div>
                                    <p class="mb-0 text-muted">Real-time KPIs, AI-driven insights, and predictive analytics
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <!-- Date Range Selector -->
                                    <div class="d-flex gap-2">
                                        <select id="dateRange" class="form-select form-select-sm">
                                            <option value="daily" {{ $dateRange == 'daily' ? 'selected' : '' }}>Daily</option>
                                            <option value="weekly" {{ $dateRange == 'weekly' ? 'selected' : '' }}>Weekly
                                            </option>
                                            <option value="monthly" {{ $dateRange == 'monthly' ? 'selected' : '' }}>Monthly
                                            </option>
                                            <option value="quarterly" {{ $dateRange == 'quarterly' ? 'selected' : '' }}>
                                                Quarterly</option>
                                            <option value="yearly" {{ $dateRange == 'yearly' ? 'selected' : '' }}>Yearly
                                            </option>
                                            <option value="custom" {{ $dateRange == 'custom' ? 'selected' : '' }}>Custom
                                            </option>
                                        </select>
                                        @if(count($branches) > 1)
                                            <select id="branchFilter" class="form-select form-select-sm">
                                                <option value="">All Clusters</option>
                                                @foreach($branches as $branch)
                                                    <option value="{{ $branch->id }}" {{ $selectedBranchId == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>
                                    <div id="customDateRange" class="mt-2" style="display: none;">
                                        <div class="d-flex gap-2">
                                            <input type="date" id="startDate" class="form-control form-control-sm"
                                                value="{{ $startDate }}">
                                            <input type="date" id="endDate" class="form-control form-control-sm"
                                                value="{{ $endDate }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading Indicator -->
            <div id="loadingIndicator" class="text-center py-5" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading analytics data...</p>
            </div>

            <!-- KPI Cards Section -->
            <div id="kpiSection">
                <!-- Portfolio Growth KPIs -->
                <div class="row mb-3">
                    <div class="col-12">
                        <h6 class="mb-3">Portfolio Growth KPIs</h6>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Total Loan Portfolio</p>
                                        <h4 class="mb-0" id="kpi-total-portfolio">-</h4>
                                        <small class="text-muted" id="kpi-total-portfolio-change">-</small>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="bx bx-trending-up text-primary font-30"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Loans Disbursed</p>
                                        <h4 class="mb-0" id="kpi-total-disbursement">-</h4>
                                        <small class="text-muted" id="kpi-total-disbursement-change">-</small>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="bx bx-money text-success font-30"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-info">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Active Loans</p>
                                        <h4 class="mb-0" id="kpi-active-loans">-</h4>
                                        <small class="text-muted" id="kpi-active-loans-change">-</small>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="bx bx-list-check text-info font-30"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-warning">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">New Loans Issued</p>
                                        <h4 class="mb-0" id="kpi-new-loans">-</h4>
                                        <small class="text-muted" id="kpi-new-loans-change">-</small>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="bx bx-plus-circle text-warning font-30"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-secondary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Avg Loan Size</p>
                                        <h4 class="mb-0" id="kpi-avg-loan-size">-</h4>
                                        <small class="text-muted" id="kpi-avg-loan-size-change">-</small>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <i class="bx bx-calculator text-secondary font-30"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profitability KPIs -->
                <div class="row mb-3">
                    <div class="col-12">
                        <h6 class="mb-3">Profitability KPIs</h6>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Interest Income</p>
                                        <h4 class="mb-0" id="kpi-interest-income">-</h4>
                                        <small class="text-muted" id="kpi-interest-income-change">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-info">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Fees & Charges</p>
                                        <h4 class="mb-0" id="kpi-fees-charges">-</h4>
                                        <small class="text-muted" id="kpi-fees-charges-change">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-primary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Effective Yield (%)</p>
                                        <h4 class="mb-0" id="kpi-effective-yield">-</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-warning">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Avg Interest Rate</p>
                                        <h4 class="mb-0" id="kpi-avg-interest-rate">-</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Net Loan Revenue</p>
                                        <h4 class="mb-0" id="kpi-net-loan-revenue">-</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Risk & Portfolio Quality KPIs -->
                <div class="row mb-3">
                    <div class="col-12">
                        <h6 class="mb-3">Risk & Portfolio Quality KPIs</h6>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4" id="kpi-par30-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">PAR >30 (%)</p>
                                        <h4 class="mb-0" id="kpi-par30">-</h4>
                                        <small class="text-muted" id="kpi-par30-amount">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4" id="kpi-par90-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">PAR >90 (%)</p>
                                        <h4 class="mb-0" id="kpi-par90">-</h4>
                                        <small class="text-muted" id="kpi-par90-amount">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4" id="kpi-npl-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">NPL (%)</p>
                                        <h4 class="mb-0" id="kpi-npl">-</h4>
                                        <small class="text-muted" id="kpi-npl-amount">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4" id="kpi-default-rate-card">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Default Rate</p>
                                        <h4 class="mb-0" id="kpi-default-rate">-</h4>
                                        <small class="text-muted" id="kpi-default-count">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-danger">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Write-Off Amount</p>
                                        <h4 class="mb-0" id="kpi-write-off">-</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Repayment Performance KPIs -->
                <div class="row mb-3">
                    <div class="col-12">
                        <h6 class="mb-3">Repayment Performance KPIs</h6>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Repayment Rate (%)</p>
                                        <h4 class="mb-0" id="kpi-repayment-rate">-</h4>
                                        <small class="text-muted" id="kpi-repayment-rate-change">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-info">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Collection Efficiency (%)</p>
                                        <h4 class="mb-0" id="kpi-collection-efficiency">-</h4>
                                        <small class="text-muted" id="kpi-collection-efficiency-change">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-warning">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Overdue Amount</p>
                                        <h4 class="mb-0" id="kpi-overdue-amount">-</h4>
                                        <small class="text-muted" id="kpi-overdue-amount-change">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-danger">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Loans in Arrears</p>
                                        <h4 class="mb-0" id="kpi-loans-in-arrears">-</h4>
                                        <small class="text-muted" id="kpi-loans-in-arrears-change">-</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <div class="card border-top border-0 border-4 border-secondary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <p class="text-muted mb-0 small">Avg Days in Arrears</p>
                                        <h4 class="mb-0" id="kpi-avg-days-arrears">-</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="row mb-3" id="chartsSection">
                <!-- Loan Disbursement Trend -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Loan Disbursement Trend</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="disbursementTrendChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Portfolio vs Repayments -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Portfolio vs. Repayments</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="portfolioVsRepaymentsChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- PAR Aging Breakdown -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">PAR Aging Breakdown</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="parAgingChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Loan Product Performance -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Loan Product Performance</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="productPerformanceChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Loan Status Distribution -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Loan Status Distribution</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="loanStatusChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Interest Income Trend -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Interest Income Trend</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="interestIncomeTrendChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Repayment Rate Trend -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Repayment Rate Trend</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="repaymentRateTrendChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Sector Performance -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Sector Performance</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="sectorPerformanceChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Insights & AI Section -->
            <div class="row mb-3">
                <!-- Top Customers -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Top Customers</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Balance</th>
                                            <th>Risk Score</th>
                                            <th>Days in Arrears</th>
                                        </tr>
                                    </thead>
                                    <tbody id="topCustomersTable">
                                        <tr>
                                            <td colspan="4" class="text-center">Loading...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer Segmentation -->
                <div class="col-12 col-lg-6 mb-3">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Customer Segmentation</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="customerSegmentationChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Insights Section -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="card border-top border-0 border-4 border-primary">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bx bx-brain"></i> AI Insights & Recommendations</h6>
                        </div>
                        <div class="card-body">
                            <div id="aiInsightsContent">
                                <p class="text-muted">Loading AI insights...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cluster Performance -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Cluster Performance</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Cluster</th>
                                            <th>Portfolio</th>
                                            <th>Disbursement</th>
                                            <th>Active Loans</th>
                                            <th>PAR >30 (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="branchPerformanceTable">
                                        <tr>
                                            <td colspan="5" class="text-center">Loading...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let charts = {};
            let abortController = null;

            // Initialize
            console.log('Analytics page initialized');
            console.log('Routes:', {
                kpis: '{{ route("api.analytics.kpis") }}',
                charts: '{{ route("api.analytics.charts") }}',
                insights: '{{ route("api.analytics.insights") }}'
            });

            // Get branch filter element early (before it's used)
            const branchFilter = document.getElementById('branchFilter');

            // Date range change handler
            document.getElementById('dateRange').addEventListener('change', function () {
                if (this.value === 'custom') {
                    document.getElementById('customDateRange').style.display = 'block';
                } else {
                    document.getElementById('customDateRange').style.display = 'none';
                }
                loadAllData();
            });

            // Branch filter change handler
            if (branchFilter) {
                branchFilter.addEventListener('change', loadAllData);
            }

            // Custom date change handlers
            document.getElementById('startDate')?.addEventListener('change', loadAllData);
            document.getElementById('endDate')?.addEventListener('change', loadAllData);

            function getQueryParams() {
                const params = new URLSearchParams();
                const dateRangeEl = document.getElementById('dateRange');
                const startDateEl = document.getElementById('startDate');
                const endDateEl = document.getElementById('endDate');

                if (dateRangeEl) {
                    params.append('range', dateRangeEl.value);
                }
                if (startDateEl) {
                    params.append('start_date', startDateEl.value || '');
                }
                if (endDateEl) {
                    params.append('end_date', endDateEl.value || '');
                }
                if (branchFilter) {
                    params.append('branch_id', branchFilter.value);
                }
                return params;
            }

            // Add timeout to detect if loading never completes and force hide
            setTimeout(() => {
                const loadingEl = document.getElementById('loadingIndicator');
                if (loadingEl && loadingEl.style.display !== 'none') {
                    console.error('Loading timeout - page still loading after 30 seconds, forcing hide');
                    showError('Loading is taking longer than expected. Please check the console for errors.');
                    hideLoading(); // Force hide after timeout
                }
            }, 30000);

            // Also add a shorter timeout to ensure loading hides even if something goes wrong
            setTimeout(() => {
                hideLoading();
                console.log('Fallback: Loading indicator hidden after 60 seconds');
            }, 60000);

            // Initialize data loading after everything is set up
            loadAllData();

            async function loadAllData() {
                // Cancel any pending requests
                if (abortController) {
                    abortController.abort();
                }
                abortController = new AbortController();

                showLoading();
                const params = getQueryParams();
                const signal = abortController.signal;
                const kpiUrl = `{{ route('api.analytics.kpis') }}?${params.toString()}`;

                console.log('Loading KPIs from:', kpiUrl);

                try {
                    // Load KPIs first (critical data)
                    const kpiResponse = await fetch(kpiUrl, {
                        signal,
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    console.log('KPI Response status:', kpiResponse.status);

                    if (!kpiResponse.ok) {
                        const text = await kpiResponse.text();
                        console.error('KPI Response error body:', text.substring(0, 500));
                        throw new Error(`KPI request failed: ${kpiResponse.status}`);
                    }

                    const kpiData = await kpiResponse.json();
                    console.log('KPI Data received:', kpiData);
                    console.log('KPI Data keys:', Object.keys(kpiData));
                    console.log('KPI Data structure:', {
                        hasPortfolio: !!kpiData.portfolio,
                        hasProfitability: !!kpiData.profitability,
                        hasRisk: !!kpiData.risk,
                        hasRepayment: !!kpiData.repayment
                    });

                    if (kpiData.error) {
                        console.error('KPI Error:', kpiData.error);
                        throw new Error(kpiData.error);
                    }

                    try {
                        updateKPIs(kpiData);
                        console.log('KPIs updated successfully');
                        // Don't hide loading here - let it hide after charts load or timeout
                    } catch (updateError) {
                        console.error('Error updating KPIs:', updateError);
                        console.error('KPI Data that caused error:', kpiData);
                        showError('Failed to display KPIs: ' + updateError.message);
                        hideLoading(); // Hide on error
                    }

                    // Load all charts in parallel using Promise.allSettled
                    const chartTypes = [
                        'disbursement_trend',
                        'portfolio_vs_repayments',
                        'par_aging',
                        'product_performance',
                        'customer_segmentation',
                        'loan_status_distribution',
                        'interest_income_trend',
                        'repayment_rate_trend',
                        'sector_performance'
                    ];

                    const chartPromises = chartTypes.map(chartType =>
                        loadChartAsync(chartType, params, signal)
                    );

                    // Add other data loaders
                    const otherPromises = [
                        loadTopCustomersAsync(params, signal),
                        loadBranchPerformanceAsync(params, signal),
                        loadAIInsightsAsync(params, signal)
                    ];

                    // Execute all in parallel - don't wait for all to complete
                    Promise.allSettled([...chartPromises, ...otherPromises])
                        .then(results => {
                            console.log('All chart/data requests completed');
                            results.forEach((result, index) => {
                                if (result.status === 'rejected' && result.reason?.name !== 'AbortError') {
                                    console.warn(`Request ${index} failed:`, result.reason);
                                } else if (result.status === 'fulfilled') {
                                    console.log(`Request ${index} succeeded`);
                                }
                            });
                            // Ensure loading is hidden after all requests complete
                            hideLoading();
                        })
                        .catch(err => {
                            console.error('Error in Promise.allSettled:', err);
                            hideLoading();
                        });

                } catch (err) {
                    if (err.name === 'AbortError') {
                        console.log('Request was aborted');
                        return; // Request was cancelled, ignore
                    }
                    console.error('Data loading error:', err);
                    console.error('Error stack:', err.stack);
                    showError('Failed to load analytics data: ' + (err.message || 'Unknown error'));
                    hideLoading();
                }
            }

            async function loadChartAsync(chartType, params, signal) {
                if (!chartType) {
                    console.error('loadChartAsync: chartType is missing');
                    return;
                }

                const chartParams = new URLSearchParams(params.toString());
                chartParams.append('chart_type', chartType);

                try {
                    const url = `{{ route('api.analytics.charts') }}?${chartParams.toString()}`;
                    console.log(`Loading chart: ${chartType} from ${url}`);

                    const response = await fetch(url, {
                        signal,
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error(`Chart ${chartType} HTTP error ${response.status}:`, errorText);
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const data = await response.json();

                    if (data.error) {
                        console.error(`Chart ${chartType} error:`, data.error);
                        throw new Error(data.error);
                    }

                    console.log(`Chart ${chartType} loaded successfully`, data);
                    renderChart(chartType, data);
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        console.warn(`Chart ${chartType} failed:`, err);
                        showChartError(chartType);
                    }
                    // Don't throw - let other charts continue loading
                }
            }

            function renderChart(chartType, data) {
                switch (chartType) {
                    case 'disbursement_trend': updateDisbursementTrendChart(data); break;
                    case 'portfolio_vs_repayments': updatePortfolioVsRepaymentsChart(data); break;
                    case 'par_aging': updatePARAgingChart(data); break;
                    case 'product_performance': updateProductPerformanceChart(data); break;
                    case 'customer_segmentation': updateCustomerSegmentationChart(data); break;
                    case 'loan_status_distribution': updateLoanStatusChart(data); break;
                    case 'interest_income_trend': updateInterestIncomeTrendChart(data); break;
                    case 'repayment_rate_trend': updateRepaymentRateTrendChart(data); break;
                    case 'sector_performance': updateSectorPerformanceChart(data); break;
                }
            }

            function showChartError(chartType) {
                const chartContainers = {
                    'disbursement_trend': 'disbursementTrendChart',
                    'portfolio_vs_repayments': 'portfolioVsRepaymentsChart',
                    'par_aging': 'parAgingChart',
                    'product_performance': 'productPerformanceChart',
                    'customer_segmentation': 'customerSegmentationChart',
                    'loan_status_distribution': 'loanStatusChart',
                    'interest_income_trend': 'interestIncomeTrendChart',
                    'repayment_rate_trend': 'repaymentRateTrendChart',
                    'sector_performance': 'sectorPerformanceChart'
                };
                const canvasId = chartContainers[chartType];
                if (canvasId) {
                    const canvas = document.getElementById(canvasId);
                    if (canvas?.parentElement) {
                        canvas.parentElement.innerHTML = '<p class="text-muted text-center p-3">Chart unavailable</p>';
                    }
                }
            }

            async function loadTopCustomersAsync(params, signal) {
                const topParams = new URLSearchParams(params.toString());
                topParams.append('chart_type', 'top_customers');

                try {
                    const response = await fetch(`{{ route('api.analytics.charts') }}?${topParams.toString()}`, {
                        signal,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();

                    const tbody = document.getElementById('topCustomersTable');
                    if (data.error || data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center">No data available</td></tr>';
                        return;
                    }
                    tbody.innerHTML = data.map(c => `
                    <tr>
                        <td>${escapeHtml(c.name)}</td>
                        <td>${formatCurrency(c.balance)}</td>
                        <td><span class="badge ${getRiskBadgeClass(c.risk_score)}">${c.risk_score}</span></td>
                        <td>${c.days_in_arrears}</td>
                    </tr>
                `).join('');
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        document.getElementById('topCustomersTable').innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading data</td></tr>';
                    }
                    throw err;
                }
            }

            async function loadBranchPerformanceAsync(params, signal) {
                const branchParams = new URLSearchParams(params.toString());
                branchParams.append('chart_type', 'branch_performance');

                try {
                    const response = await fetch(`{{ route('api.analytics.charts') }}?${branchParams.toString()}`, {
                        signal,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();

                    const tbody = document.getElementById('branchPerformanceTable');
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center">No data available</td></tr>';
                        return;
                    }
                    tbody.innerHTML = data.map(b => `
                    <tr>
                        <td>${escapeHtml(b.name)}</td>
                        <td>${formatCurrency(b.portfolio)}</td>
                        <td>${formatCurrency(b.disbursement)}</td>
                        <td>${b.active_loans}</td>
                        <td><span class="badge ${getParBadgeClass(b.par_30)}">${b.par_30.toFixed(1)}%</span></td>
                    </tr>
                `).join('');
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        document.getElementById('branchPerformanceTable').innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading data</td></tr>';
                    }
                    throw err;
                }
            }

            async function loadAIInsightsAsync(params, signal) {
                try {
                    const response = await fetch(`{{ route('api.analytics.insights') }}?${params.toString()}`, {
                        signal,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const data = await response.json();

                    const content = document.getElementById('aiInsightsContent');
                    let html = '<ul class="list-unstyled mb-0">';
                    (data.insights || []).forEach(insight => {
                        html += `<li class="mb-2"><i class="bx bx-info-circle text-primary"></i> ${escapeHtml(insight)}</li>`;
                    });
                    html += '</ul>';
                    if (data.recommendations?.length > 0) {
                        html += '<hr><h6>Recommendations:</h6><ul class="list-unstyled">';
                        data.recommendations.forEach(rec => {
                            html += `<li class="mb-1"><i class="bx bx-check-circle text-success"></i> ${escapeHtml(rec)}</li>`;
                        });
                        html += '</ul>';
                    }
                    content.innerHTML = html;
                } catch (err) {
                    if (err.name !== 'AbortError') {
                        document.getElementById('aiInsightsContent').innerHTML = '<p class="text-danger">Failed to load insights</p>';
                    }
                    throw err;
                }
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function updateKPIs(data) {
                try {
                    console.log('updateKPIs called with data:', data);

                    // Check if data structure is valid
                    if (!data) {
                        console.error('No data provided to updateKPIs');
                        return;
                    }

                    // Portfolio Growth - with null checks
                    if (data.portfolio) {
                        const portfolio = data.portfolio;
                        if (portfolio.total_loan_portfolio) {
                            updateKPICard('kpi-total-portfolio', portfolio.total_loan_portfolio.value || 0, portfolio.total_loan_portfolio.change || 0);
                        }
                        if (portfolio.total_disbursement) {
                            updateKPICard('kpi-total-disbursement', portfolio.total_disbursement.value || 0, portfolio.total_disbursement.change || 0);
                        }
                        if (portfolio.active_loans) {
                            updateKPICard('kpi-active-loans', portfolio.active_loans.value || 0, portfolio.active_loans.change || 0, true);
                        }
                        if (portfolio.new_loans) {
                            updateKPICard('kpi-new-loans', portfolio.new_loans.value || 0, portfolio.new_loans.change || 0, true);
                        }
                        if (portfolio.avg_loan_size) {
                            updateKPICard('kpi-avg-loan-size', portfolio.avg_loan_size.value || 0, portfolio.avg_loan_size.change || 0);
                        }
                    } else {
                        console.warn('Portfolio data missing');
                    }

                    // Profitability - with null checks
                    if (data.profitability) {
                        const profitability = data.profitability;
                        if (profitability.interest_income) {
                            updateKPICard('kpi-interest-income', profitability.interest_income.value || 0, profitability.interest_income.change || 0);
                        }
                        if (profitability.fees_charges) {
                            updateKPICard('kpi-fees-charges', profitability.fees_charges.value || 0, profitability.fees_charges.change || 0);
                        }
                        const effectiveYieldEl = document.getElementById('kpi-effective-yield');
                        if (effectiveYieldEl && profitability.effective_yield) {
                            effectiveYieldEl.textContent = (profitability.effective_yield.value || 0).toFixed(2) + '%';
                        }
                        const avgInterestRateEl = document.getElementById('kpi-avg-interest-rate');
                        if (avgInterestRateEl && profitability.avg_interest_rate) {
                            avgInterestRateEl.textContent = (profitability.avg_interest_rate.value || 0).toFixed(2) + '%';
                        }
                        if (profitability.net_loan_revenue) {
                            updateKPICard('kpi-net-loan-revenue', profitability.net_loan_revenue.value || 0, 0);
                        }
                    } else {
                        console.warn('Profitability data missing');
                    }

                    // Risk - with null checks
                    if (data.risk) {
                        const risk = data.risk;
                        if (risk.par_30) {
                            const par30 = risk.par_30.value || 0;
                            const par30El = document.getElementById('kpi-par30');
                            if (par30El) {
                                par30El.textContent = par30.toFixed(1) + '%';
                            }
                            const par30AmountEl = document.getElementById('kpi-par30-amount');
                            if (par30AmountEl) {
                                par30AmountEl.textContent = formatCurrency(risk.par_30.amount || 0);
                            }
                            updateRiskCard('kpi-par30-card', par30);
                        }
                        if (risk.par_90) {
                            const par90 = risk.par_90.value || 0;
                            const par90El = document.getElementById('kpi-par90');
                            if (par90El) {
                                par90El.textContent = par90.toFixed(1) + '%';
                            }
                            const par90AmountEl = document.getElementById('kpi-par90-amount');
                            if (par90AmountEl) {
                                par90AmountEl.textContent = formatCurrency(risk.par_90.amount || 0);
                            }
                            updateRiskCard('kpi-par90-card', par90);
                        }
                        if (risk.npl) {
                            const npl = risk.npl.value || 0;
                            const nplEl = document.getElementById('kpi-npl');
                            if (nplEl) {
                                nplEl.textContent = npl.toFixed(1) + '%';
                            }
                            const nplAmountEl = document.getElementById('kpi-npl-amount');
                            if (nplAmountEl) {
                                nplAmountEl.textContent = formatCurrency(risk.npl.amount || 0);
                            }
                            updateRiskCard('kpi-npl-card', npl);
                        }
                        if (risk.default_rate) {
                            const defaultRateEl = document.getElementById('kpi-default-rate');
                            if (defaultRateEl) {
                                defaultRateEl.textContent = (risk.default_rate.value || 0).toFixed(1) + '%';
                            }
                            const defaultCountEl = document.getElementById('kpi-default-count');
                            if (defaultCountEl) {
                                defaultCountEl.textContent = (risk.default_rate.count || 0) + ' loans';
                            }
                            updateRiskCard('kpi-default-rate-card', risk.default_rate.value || 0);
                        }
                        if (risk.write_off_amount) {
                            updateKPICard('kpi-write-off', risk.write_off_amount.value || 0, 0);
                        }
                    } else {
                        console.warn('Risk data missing');
                    }

                    // Repayment - with null checks
                    if (data.repayment) {
                        const repayment = data.repayment;
                        if (repayment.repayment_rate) {
                            updateKPICard('kpi-repayment-rate', repayment.repayment_rate.value || 0, repayment.repayment_rate.change || 0, false, '%');
                        }
                        if (repayment.collection_efficiency) {
                            updateKPICard('kpi-collection-efficiency', repayment.collection_efficiency.value || 0, repayment.collection_efficiency.change || 0, false, '%');
                        }
                        if (repayment.overdue_amount) {
                            updateKPICard('kpi-overdue-amount', repayment.overdue_amount.value || 0, repayment.overdue_amount.change || 0);
                        }
                        if (repayment.loans_in_arrears) {
                            updateKPICard('kpi-loans-in-arrears', repayment.loans_in_arrears.value || 0, repayment.loans_in_arrears.change || 0, true);
                        }
                        const avgDaysArrearsEl = document.getElementById('kpi-avg-days-arrears');
                        if (avgDaysArrearsEl && repayment.avg_days_in_arrears) {
                            avgDaysArrearsEl.textContent = Math.round(repayment.avg_days_in_arrears.value || 0) + ' days';
                        }
                    } else {
                        console.warn('Repayment data missing');
                    }

                    console.log('updateKPIs completed successfully');
                } catch (error) {
                    console.error('Error in updateKPIs:', error);
                    console.error('Data that caused error:', data);
                    throw error;
                }
            }

            function updateKPICard(elementId, value, change, isInteger = false, suffix = '') {
                try {
                    const element = document.getElementById(elementId);
                    if (!element) {
                        console.warn('Element not found:', elementId);
                        return;
                    }

                    const numValue = parseFloat(value) || 0;
                    const numChange = parseFloat(change) || 0;

                    if (isInteger) {
                        element.textContent = Math.round(numValue).toLocaleString() + suffix;
                    } else {
                        element.textContent = formatCurrency(numValue) + suffix;
                    }

                    const changeElement = document.getElementById(elementId + '-change');
                    if (changeElement) {
                        const changeValue = numChange.toFixed(1);
                        const isPositive = numChange >= 0;
                        changeElement.textContent = (isPositive ? '+' : '') + changeValue + '%';
                        changeElement.className = 'text-muted ' + (isPositive ? 'text-success' : 'text-danger');
                    }
                } catch (error) {
                    console.error('Error updating KPI card:', elementId, error);
                }
            }

            function updateRiskCard(cardId, value) {
                const card = document.getElementById(cardId);
                card.className = 'card border-top border-0 border-4';
                if (value < 3) {
                    card.classList.add('border-success');
                } else if (value < 5) {
                    card.classList.add('border-warning');
                } else {
                    card.classList.add('border-danger');
                }
            }

            function updateDisbursementTrendChart(data) {
                const ctx = document.getElementById('disbursementTrendChart').getContext('2d');
                if (charts.disbursementTrend) {
                    charts.disbursementTrend.destroy();
                }
                charts.disbursementTrend = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Disbursement',
                            data: data.data,
                            borderColor: '#006400',
                            backgroundColor: 'rgba(0, 100, 0, 0.1)',
                            tension: 0.4,
                            fill: true,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function (value) {
                                        return formatCurrency(value);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function updatePortfolioVsRepaymentsChart(data) {
                const ctx = document.getElementById('portfolioVsRepaymentsChart').getContext('2d');
                if (charts.portfolioVsRepayments) {
                    charts.portfolioVsRepayments.destroy();
                }
                charts.portfolioVsRepayments = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'Outstanding',
                                data: data.outstanding,
                                backgroundColor: '#006400',
                            },
                            {
                                label: 'Repayments',
                                data: data.repayments,
                                backgroundColor: '#198754',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function (value) {
                                        return formatCurrency(value);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function updatePARAgingChart(data) {
                const ctx = document.getElementById('parAgingChart').getContext('2d');
                if (charts.parAging) {
                    charts.parAging.destroy();
                }
                charts.parAging = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: '0-30 Days',
                                data: data.bucket_0_30,
                                backgroundColor: '#198754',
                            },
                            {
                                label: '31-60 Days',
                                data: data.bucket_31_60,
                                backgroundColor: '#ffc107',
                            },
                            {
                                label: '61-90 Days',
                                data: data.bucket_61_90,
                                backgroundColor: '#fd7e14',
                            },
                            {
                                label: '>90 Days',
                                data: data.bucket_90_plus,
                                backgroundColor: '#dc3545',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true },
                        },
                        scales: {
                            x: { stacked: true },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    callback: function (value) {
                                        return formatCurrency(value);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function updateProductPerformanceChart(data) {
                const ctx = document.getElementById('productPerformanceChart').getContext('2d');
                if (charts.productPerformance) {
                    charts.productPerformance.destroy();
                }
                charts.productPerformance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'Portfolio',
                                data: data.portfolio,
                                backgroundColor: '#006400',
                            },
                            {
                                label: 'NPL %',
                                data: data.npl_percent,
                                backgroundColor: '#0d6efd',
                            },
                            {
                                label: 'Yield',
                                data: data.yield,
                                backgroundColor: '#198754',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function (value) {
                                        return formatCurrency(value);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function updateCustomerSegmentationChart(data) {
                const ctx = document.getElementById('customerSegmentationChart').getContext('2d');
                if (charts.customerSegmentation) {
                    charts.customerSegmentation.destroy();
                }
                charts.customerSegmentation = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Individual', 'Group Loans', 'MSME', 'Agriculture', 'Other'],
                        datasets: [{
                            data: [
                                data.individual,
                                data.group,
                                data.msme,
                                data.agriculture,
                                data.other
                            ],
                            backgroundColor: [
                                '#198754',
                                '#0d6efd',
                                '#ffc107',
                                '#fd7e14',
                                '#6c757d'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true, position: 'bottom' },
                        }
                    }
                });
            }

            function updateLoanStatusChart(data) {
                const ctx = document.getElementById('loanStatusChart').getContext('2d');
                if (charts.loanStatus) {
                    charts.loanStatus.destroy();
                }
                charts.loanStatus = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.data,
                            backgroundColor: [
                                '#0d6efd',
                                '#198754',
                                '#ffc107',
                                '#fd7e14',
                                '#006400',
                                '#dc3545',
                                '#6c757d',
                                '#20c997',
                                '#6610f2'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true, position: 'bottom' },
                        }
                    }
                });
            }

            function updateInterestIncomeTrendChart(data) {
                const ctx = document.getElementById('interestIncomeTrendChart').getContext('2d');
                if (charts.interestIncomeTrend) {
                    charts.interestIncomeTrend.destroy();
                }
                charts.interestIncomeTrend = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Interest Income',
                            data: data.data,
                            borderColor: '#006400',
                            backgroundColor: 'rgba(0, 100, 0, 0.1)',
                            tension: 0.4,
                            fill: true,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function (value) {
                                        return formatCurrency(value);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function updateRepaymentRateTrendChart(data) {
                const ctx = document.getElementById('repaymentRateTrendChart').getContext('2d');
                if (charts.repaymentRateTrend) {
                    charts.repaymentRateTrend.destroy();
                }
                charts.repaymentRateTrend = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'Repayment Rate (%)',
                                data: data.repayment_rate,
                                borderColor: '#006400',
                                backgroundColor: 'rgba(0, 100, 0, 0.1)',
                                tension: 0.4,
                                fill: true,
                            },
                            {
                                label: 'Collection Efficiency (%)',
                                data: data.collection_efficiency,
                                borderColor: '#198754',
                                backgroundColor: 'rgba(25, 135, 84, 0.1)',
                                tension: 0.4,
                                fill: true,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    callback: function (value) {
                                        return value + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function updateSectorPerformanceChart(data) {
                const ctx = document.getElementById('sectorPerformanceChart').getContext('2d');
                if (charts.sectorPerformance) {
                    charts.sectorPerformance.destroy();
                }
                charts.sectorPerformance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'Portfolio',
                                data: data.portfolio,
                                backgroundColor: '#006400',
                            },
                            {
                                label: 'Disbursement',
                                data: data.disbursement,
                                backgroundColor: '#198754',
                            },
                            {
                                label: 'Interest Income',
                                data: data.interest_income,
                                backgroundColor: '#ffc107',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: true },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function (value) {
                                        return formatCurrency(value);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function formatCurrency(value) {
                return 'TSHS ' + parseFloat(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function getRiskBadgeClass(score) {
                if (score >= 80) return 'bg-success';
                if (score >= 60) return 'bg-warning';
                return 'bg-danger';
            }

            function getParBadgeClass(value) {
                if (value < 3) return 'bg-success';
                if (value < 5) return 'bg-warning';
                return 'bg-danger';
            }

            function showLoading() {
                document.getElementById('loadingIndicator').style.display = 'block';
                document.getElementById('kpiSection').style.opacity = '0.5';
                document.getElementById('chartsSection').style.opacity = '0.5';
            }

            function hideLoading() {
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('kpiSection').style.opacity = '1';
                document.getElementById('chartsSection').style.opacity = '1';
            }

            function showError(message) {
                // Create or update error message
                let errorDiv = document.getElementById('errorMessage');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.id = 'errorMessage';
                    errorDiv.className = 'alert alert-danger alert-dismissible fade show';
                    errorDiv.style.position = 'fixed';
                    errorDiv.style.top = '20px';
                    errorDiv.style.right = '20px';
                    errorDiv.style.zIndex = '9999';
                    errorDiv.style.maxWidth = '400px';
                    document.body.appendChild(errorDiv);
                }
                errorDiv.innerHTML = `
                <strong>Error!</strong> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
                setTimeout(() => {
                    if (errorDiv) {
                        errorDiv.remove();
                    }
                }, 5000);
            }
        });
    </script>
@endsection