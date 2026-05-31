@extends('layouts.main')

@section('title', 'Loan Portfolio Classification Report')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard',  'url' => route('dashboard'),    'icon' => 'bx bx-home'],
            ['label' => 'Reports',    'url' => route('reports.index'), 'icon' => 'bx bx-bar-chart'],
            ['label' => 'Portfolio Classification', 'url' => '#',      'icon' => 'bx bx-table'],
        ]" />

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-uppercase">Loan Portfolio Classification Report</h6>
        </div>
        <hr />

        {{-- Filter Form --}}
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="{{ route('reports.loans.portfolio_classification') }}">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">As of Date <span class="text-danger">*</span></label>
                            <input type="date" name="as_of_date" class="form-control"
                                   value="{{ $asOfDate ?? now()->format('Y-m-d') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select">
                                <option value="">All Branches</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($branchId == $branch->id)>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Group</label>
                            <select name="group_id" class="form-select">
                                <option value="">All Groups</option>
                                @foreach($groups as $group)
                                    <option value="{{ $group->id }}" @selected($groupId == $group->id)>
                                        {{ $group->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Loan Officer</label>
                            <select name="loan_officer_id" class="form-select">
                                <option value="">All Officers</option>
                                @foreach($loanOfficers as $officer)
                                    <option value="{{ $officer->id }}" @selected($loanOfficerId == $officer->id)>
                                        {{ $officer->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active_completed" @selected(($status ?? '') === 'active_completed')>Active &amp; Completed</option>
                                <option value="active"    @selected(($status ?? '') === 'active')>Active Only</option>
                                <option value="completed" @selected(($status ?? '') === 'completed')>Completed Only</option>
                                <option value="defaulted" @selected(($status ?? '') === 'defaulted')>Defaulted Only</option>
                                <option value="all"       @selected(($status ?? '') === 'all')>All</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bx bx-search me-1"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if($reportData)

            {{-- No classifications warning --}}
            @if(!$reportData['has_classifications'])
                <div class="alert alert-warning">
                    <i class="bx bx-info-circle me-2"></i>
                    No active arrears classification buckets are configured for your company.
                    Aging bucket columns and provision amounts will not be shown.
                    <a href="{{ route('settings.arrears-classifications.index') }}" class="alert-link ms-1">Configure now</a>
                </div>
            @endif

            {{-- Summary Cards --}}
            @php $s = $reportData['summary']; @endphp
            <div class="row g-3 mb-4">
                <div class="col-md-2">
                    <div class="card text-center border-primary">
                        <div class="card-body py-2">
                            <div class="fs-5 fw-bold text-primary">{{ number_format($s['total_loans']) }}</div>
                            <div class="small text-muted">Total Loans</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center border-success">
                        <div class="card-body py-2">
                            <div class="fs-6 fw-bold text-success">{{ number_format($s['total_disbursed'], 2) }}</div>
                            <div class="small text-muted">Total Disbursed</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center border-warning">
                        <div class="card-body py-2">
                            <div class="fs-6 fw-bold text-warning">{{ number_format($s['total_outstanding'], 2) }}</div>
                            <div class="small text-muted">Total Outstanding</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card text-center border-info">
                        <div class="card-body py-2">
                            <div class="fs-6 fw-bold text-info">{{ number_format($s['total_principal_collected'], 2) }}</div>
                            <div class="small text-muted">Principal Collected</div>
                        </div>
                    </div>
                </div>
                @if($reportData['has_classifications'])
                <div class="col-md-2">
                    <div class="card text-center border-danger">
                        <div class="card-body py-2">
                            <div class="fs-6 fw-bold text-danger">{{ number_format($s['total_provision'], 2) }}</div>
                            <div class="small text-muted">Total Provision</div>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Export Buttons --}}
            <div class="d-flex gap-2 mb-3">
                <form method="GET" action="{{ route('reports.loans.portfolio_classification.export_excel') }}">
                    <input type="hidden" name="as_of_date"      value="{{ $asOfDate }}">
                    <input type="hidden" name="branch_id"       value="{{ $branchId }}">
                    <input type="hidden" name="group_id"        value="{{ $groupId }}">
                    <input type="hidden" name="loan_officer_id" value="{{ $loanOfficerId }}">
                    <input type="hidden" name="status"          value="{{ $status }}">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bx bx-spreadsheet me-1"></i> Export Excel
                    </button>
                </form>
                <form method="GET" action="{{ route('reports.loans.portfolio_classification.export_pdf') }}">
                    <input type="hidden" name="as_of_date"      value="{{ $asOfDate }}">
                    <input type="hidden" name="branch_id"       value="{{ $branchId }}">
                    <input type="hidden" name="group_id"        value="{{ $groupId }}">
                    <input type="hidden" name="loan_officer_id" value="{{ $loanOfficerId }}">
                    <input type="hidden" name="status"          value="{{ $status }}">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bx bx-file-pdf me-1"></i> Export PDF
                    </button>
                </form>
            </div>

            {{-- Data Table --}}
            @php
                $hasCls  = $reportData['has_classifications'];
                $clsList = $reportData['classifications'];
                $staticCols = 16; // S/N + 15 data cols before buckets
                $totalCols  = $staticCols + ($hasCls ? $clsList->count() + 2 : 0); // +2 = provision rate + provision amount
            @endphp
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm mb-0" style="font-size: 0.76rem;">
                            <thead class="table-dark">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Disb. Date</th>
                                    <th>Customer Name</th>
                                    <th class="text-center">Gender</th>
                                    <th class="text-center">Age</th>
                                    <th>Branch / Area</th>
                                    <th>Loan Product Type</th>
                                    <th class="text-end">Principal Disbursed</th>
                                    <th class="text-end">Interest Paid</th>
                                    <th class="text-end">Due Interest Unpaid</th>
                                    <th class="text-end">Fee Unpaid</th>
                                    <th class="text-end">Fee Paid</th>
                                    <th class="text-end">Principal Collected</th>
                                    <th class="text-end">Accrued Interest</th>
                                    <th class="text-end">Outstanding Balance</th>
                                    <th class="text-center">Past Due Days</th>
                                    @if($hasCls)
                                        @foreach($clsList as $cls)
                                            <th class="text-end" style="min-width:85px;">
                                                {{ $cls->bucket_label }}<br>
                                                <small class="fw-normal opacity-75">{{ $cls->status }}</small>
                                            </th>
                                        @endforeach
                                        <th class="text-end" style="min-width:70px;">Provision Rate %</th>
                                        <th class="text-end" style="min-width:85px;">Provision Amount</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportData['loans'] as $loan)
                                    <tr>
                                        <td class="text-center">{{ $loan['serial'] }}</td>
                                        <td>{{ $loan['disbursement_date'] }}</td>
                                        <td>{{ $loan['customer_name'] }}</td>
                                        <td class="text-center">{{ $loan['gender'] }}</td>
                                        <td class="text-center">{{ $loan['age'] ?? 'N/A' }}</td>
                                        <td>{{ $loan['branch'] }}</td>
                                        <td>{{ $loan['loan_product_type'] }}</td>
                                        <td class="text-end">{{ number_format($loan['principal_disbursed'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['interest_paid'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['due_interest_unpaid'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['fee_unpaid'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['fee_paid'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['principal_collected'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['accrued_interest'], 2) }}</td>
                                        <td class="text-end">{{ number_format($loan['outstanding_balance'], 2) }}</td>
                                        <td class="text-center">
                                            @if($loan['past_due_days'] > 0)
                                                <span class="badge bg-danger">{{ $loan['past_due_days'] }}</span>
                                            @else
                                                <span class="badge bg-success">0</span>
                                            @endif
                                        </td>
                                        @if($hasCls)
                                            @foreach($clsList as $cls)
                                                <td class="text-end">
                                                    @if(($loan['bucket_amounts'][$cls->id] ?? 0) > 0)
                                                        <strong>{{ number_format($loan['bucket_amounts'][$cls->id], 2) }}</strong>
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="text-end">
                                                {{ $loan['provision_rate'] > 0 ? number_format($loan['provision_rate'], 2).'%' : '-' }}
                                            </td>
                                            <td class="text-end">
                                                {{ $loan['provision_amount'] > 0 ? number_format($loan['provision_amount'], 2) : '-' }}
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $totalCols }}" class="text-center text-muted py-4">
                                            No loans found for the selected filters.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if(count($reportData['loans']) > 0)
                            <tfoot>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="7" class="text-end">TOTALS ({{ $s['total_loans'] }} loans)</td>
                                    <td class="text-end">{{ number_format($s['total_disbursed'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_interest_paid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_due_interest_unpaid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_fee_unpaid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_fee_paid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_principal_collected'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_accrued_interest'], 2) }}</td>
                                    <td class="text-end">{{ number_format($s['total_outstanding'], 2) }}</td>
                                    <td></td>
                                    @if($hasCls)
                                        @foreach($clsList as $cls)
                                            <td class="text-end">{{ number_format($s['bucket_totals'][$cls->id] ?? 0, 2) }}</td>
                                        @endforeach
                                        <td></td>
                                        <td class="text-end">{{ number_format($s['total_provision'], 2) }}</td>
                                    @endif
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>

        @else
            <div class="alert alert-info">
                <i class="bx bx-info-circle me-2"></i>
                Set the filters above and click <strong>Filter</strong> to generate the report.
            </div>
        @endif

    </div>
</div>
@endsection
