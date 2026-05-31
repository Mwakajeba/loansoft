@extends('layouts.main')

@section('title', 'Loan Aging Report')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
              ['label' => 'Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-file'],
            ['label' => 'Loan Aging Report', 'url' => '#', 'icon' => 'bx bx-timer']
        ]" />
        <h6 class="mb-0 text-uppercase">LOAN AGING REPORT</h6>
        <hr />

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bx bx-timer me-2"></i>Loan Aging Report</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('accounting.loans.reports.loan_aging') }}">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="as_of_date" class="form-label">As of Date</label>
                            <input type="date" class="form-control" id="as_of_date" name="as_of_date" value="{{ request('as_of_date', date('Y-m-d')) }}">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="branch_id" class="form-label">Branch</label>
                            <select class="form-select" id="branch_id" name="branch_id">
                                @if(($branches->count() ?? 0) > 1)
                                    <option value="all" {{ request('branch_id') === 'all' ? 'selected' : '' }}>All My Branches</option>
                                @endif
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="loan_officer_id" class="form-label">Loan Officer</label>
                            <select class="form-select" id="loan_officer_id" name="loan_officer_id">
                                <option value="">All Loan Officers</option>
                                @foreach($loanOfficers as $officer)
                                    <option value="{{ $officer->id }}" {{ request('loan_officer_id') == $officer->id ? 'selected' : '' }}>{{ $officer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bx bx-search me-1"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if(isset($agingData))
        <div class="card mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bx bx-list-ul me-2"></i>Aging Summary</h5>
                <div class="d-flex gap-2">
                    <form method="GET" action="{{ route('accounting.loans.reports.loan_aging') }}" class="d-inline">
                        <input type="hidden" name="as_of_date" value="{{ request('as_of_date') }}">
                        <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                        <input type="hidden" name="loan_officer_id" value="{{ request('loan_officer_id') }}">
                        <input type="hidden" name="export_type" value="excel">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="bx bx-download me-1"></i> Excel
                        </button>
                    </form>
                    <form method="GET" action="{{ route('accounting.loans.reports.loan_aging') }}" class="d-inline">
                        <input type="hidden" name="as_of_date" value="{{ request('as_of_date') }}">
                        <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
                        <input type="hidden" name="loan_officer_id" value="{{ request('loan_officer_id') }}">
                        <input type="hidden" name="export_type" value="pdf">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="bx bx-download me-1"></i> PDF
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <style>
                    .loan-aging-report-table thead th { background-color: #999 !important; color: #fff !important; border-color: #888 !important; font-weight: 600; }
                    .loan-aging-report-table tfoot th,
                    .loan-aging-report-table tfoot td { background-color: #999 !important; color: #fff !important; border-color: #888 !important; font-weight: 600; }
                </style>
                <div class="table-responsive">
                    @if(count($agingData))
                        @php
                            $sumAmount = collect($agingData)->sum(fn ($r) => (float) ($r['loan_amount'] ?? 0));
                            $sumOut = collect($agingData)->sum(fn ($r) => (float) ($r['outstanding_principal'] ?? 0));
                            $sumCurrent = collect($agingData)->sum(fn ($r) => (float) ($r['bucket_current'] ?? 0));
                            $sumEsm = collect($agingData)->sum(fn ($r) => (float) ($r['bucket_esm'] ?? 0));
                            $sumSub = collect($agingData)->sum(fn ($r) => (float) ($r['bucket_substandard'] ?? 0));
                            $sumDoubt = collect($agingData)->sum(fn ($r) => (float) ($r['bucket_doubtful'] ?? 0));
                            $sumLoss = collect($agingData)->sum(fn ($r) => (float) ($r['bucket_loss'] ?? 0));
                            $sumProvision = collect($agingData)->sum(fn ($r) => (float) ($r['provision_amount'] ?? 0));
                            $rowCount = count($agingData);
                        @endphp
                        <table class="table table-bordered table-striped loan-aging-report-table">
                            <thead>
                                <tr class="text-white" style="background:#1f4e79;">
                                    <th class="text-center">#</th>
                                    <th>Customer</th>
                                    <th>Customer No</th>
                                    <th>Phone</th>
                                    <th>Loan No</th>
                                    <th>Disbursed Date</th>
                                    <th>Loan Amount</th>
                                    <th>Gender</th>
                                    <th>Age</th>
                                    <th>Subsector</th>
                                    <th>Outstanding principal</th>
                                    <th class="text-center">Days In Arrears</th>
                                    <th style="background:#70ad47;">0-5 CURRENT (1%)</th>
                                    <th style="background:#ffc000;">6-30 ESPECIALLY MENTIONED (5%)</th>
                                    <th style="background:#bf9000;">31-60 SUBSTANDARD (25%)</th>
                                    <th style="background:#ed7d31;">61-90 DOUBTFUL (50%)</th>
                                    <th style="background:#ff0000;">MORE 91 LOSS (100%)</th>
                                    <th>PROVISION RATE %</th>
                                    <th>PROVISION AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($agingData as $row)
                                    <tr>
                                        <td class="text-center">{{ $loop->iteration }}</td>
                                        <td>{{ $row['customer'] }}</td>
                                        <td>{{ $row['customer_no'] }}</td>
                                        <td>{{ $row['phone'] }}</td>
                                        <td>{{ $row['loan_no'] }}</td>
                                        <td>{{ $row['disbursed_date'] }}</td>
                                        <td class="text-end">{{ number_format($row['loan_amount'], 2) }}</td>
                                        <td>{{ $row['gender'] ?? '' }}</td>
                                        <td>{{ $row['age_category'] ?? '' }}</td>
                                        <td>{{ $row['subsector'] ?? '' }}</td>
                                        <td class="text-end">{{ number_format($row['outstanding_principal'], 2) }}</td>
                                        <td class="text-center">{{ $row['days_in_arrears'] ?? 0 }}</td>
                                        <td class="text-end">{{ number_format($row['bucket_current'], 2) }}</td>
                                        <td class="text-end">{{ number_format($row['bucket_esm'], 2) }}</td>
                                        <td class="text-end">{{ number_format($row['bucket_substandard'], 2) }}</td>
                                        <td class="text-end">{{ number_format($row['bucket_doubtful'], 2) }}</td>
                                        <td class="text-end">{{ number_format($row['bucket_loss'], 2) }}</td>
                                        <td class="text-center">{{ $row['provision_rate'] ?? 0 }}%</td>
                                        <td class="text-end">{{ number_format($row['provision_amount'] ?? 0, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="6" class="text-start">Total ({{ $rowCount }} records)</th>
                                    <th class="text-end">{{ number_format($sumAmount, 2) }}</th>
                                    <th colspan="3"></th>
                                    <th class="text-end">{{ number_format($sumOut, 2) }}</th>
                                    <th></th>
                                    <th class="text-end">{{ number_format($sumCurrent, 2) }}</th>
                                    <th class="text-end">{{ number_format($sumEsm, 2) }}</th>
                                    <th class="text-end">{{ number_format($sumSub, 2) }}</th>
                                    <th class="text-end">{{ number_format($sumDoubt, 2) }}</th>
                                    <th class="text-end">{{ number_format($sumLoss, 2) }}</th>
                                    <th></th>
                                    <th class="text-end">{{ number_format($sumProvision, 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    @else
                        <table class="table table-bordered">
                            <tbody>
                                <tr>
                                    <td class="text-center text-muted py-4">No aging data found for the selected criteria.</td>
                                </tr>
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
