@extends('layouts.main')

@section('title', 'Loan Repayment Report')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
             ['label' => 'Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-file'],
            ['label' => 'Loan Repayment Report', 'url' => '#', 'icon' => 'bx bx-money']
        ]" />

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <h5 class="mb-0"><i class="bx bx-money me-2"></i>Loan Repayment Report</h5>
                            <small class="text-muted">Generate and export detailed loan repayment records.</small>
                        </div>
                        <div class="d-flex gap-2">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-danger dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bx bx-download me-1"></i> Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="exportReport('pdf', 'download')">
                                            <i class="bx bx-file-pdf me-2"></i> Export PDF
                                        </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportReport('excel', 'download')">
                                            <i class="bx bx-file me-2"></i> Export Excel
                                        </a></li>
                                </ul>
                            </div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bx bx-show-alt me-1"></i> View
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="exportReport('pdf', 'view')">
                                            <i class="bx bx-file-pdf me-2"></i> View PDF
                                        </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form id="loanRepaymentForm" method="GET" action="{{ route('accounting.loans.reports.repayment') }}">
                            <div class="row">
                                <div class="col-md-6 col-lg-3 mb-3">
                                    <label for="start_date" class="form-label">Start Date</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" value="{{ $startDate ?? request('start_date', now()->startOfMonth()->format('Y-m-d')) }}">
                                </div>
                                <div class="col-md-6 col-lg-3 mb-3">
                                    <label for="end_date" class="form-label">End Date</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" value="{{ $endDate ?? request('end_date', now()->format('Y-m-d')) }}">
                                </div>
                                <div class="col-md-6 col-lg-3 mb-3">
                                    <label for="branch_id" class="form-label">Branch</label>
                                    <select class="form-select select2-single" id="branch_id" name="branch_id">
                                        @if(($branches->count() ?? 0) > 1)
                                            <option value="all" {{ request('branch_id') === 'all' ? 'selected' : '' }}>All My Branches</option>
                                        @endif
                                        @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>
                                            {{ $branch->name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 col-lg-3 mb-3">
                                    <label for="group_id" class="form-label">Group</label>
                                    <select class="form-select select2-single" id="group_id" name="group_id">
                                        <option value="">All Groups</option>
                                        @foreach($groups as $group)
                                        <option value="{{ $group->id }}" {{ request('group_id') == $group->id ? 'selected' : '' }}>
                                            {{ $group->name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                                 <div class="col-md-6 col-lg-3 mb-3">
                                    <label for="loan_officer_id" class="form-label">Loan Officer</label>
                                    <select class="form-select select2-single" id="loan_officer_id" name="loan_officer_id">
                                        <option value="">All Loan Officer</option>
                                        @foreach($loanOfficers as $laonOfficer)
                                        <option value="{{ $laonOfficer->id }}" {{ request('loan_officer_id') == $laonOfficer->id ? 'selected' : '' }}>
                                            {{ $laonOfficer->name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 col-lg-3 mb-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bx bx-search me-1"></i> Apply Filters
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @if(isset($repayments))
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Report Summary</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-2 mb-3">
                                <div class="border-l-4 border-blue-500 rounded-lg p-4 bg-gray-50">
                                    <p class="text-sm font-medium text-gray-500">Total Payments</p>
                                    <h3 class="text-2xl font-bold mt-1 text-blue-600">
                                        {{ number_format($summary['transaction_count'] ?? 0) }}
                                    </h3>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="border-l-4 border-emerald-500 rounded-lg p-4 bg-gray-50">
                                    <p class="text-sm font-medium text-gray-500">Total Amount Paid</p>
                                    <h3 class="text-2xl font-bold mt-1 text-emerald-600">
                                        {{ number_format($summary['total_paid'] ?? 0, 2) }}
                                    </h3>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="border-l-4 border-primary rounded-lg p-4 bg-gray-50">
                                    <p class="text-sm font-medium text-gray-500">Total Principal</p>
                                    <h3 class="text-2xl font-bold mt-1 text-primary">
                                        {{ number_format($summary['total_principal'] ?? 0, 2) }}
                                    </h3>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="border-l-4 border-warning rounded-lg p-4 bg-gray-50">
                                    <p class="text-sm font-medium text-gray-500">Total Interest</p>
                                    <h3 class="text-2xl font-bold mt-1 text-warning">
                                        {{ number_format($summary['total_interest'] ?? 0, 2) }}
                                    </h3>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="border-l-4 border-info rounded-lg p-4 bg-gray-50">
                                    <p class="text-sm font-medium text-gray-500">Total Fee Amount</p>
                                    <h3 class="text-2xl font-bold mt-1 text-info">
                                        {{ number_format($summary['total_fees'] ?? 0, 2) }}
                                    </h3>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="border-l-4 border-danger rounded-lg p-4 bg-gray-50">
                                    <p class="text-sm font-medium text-gray-500">Total Penalty Amount</p>
                                    <h3 class="text-2xl font-bold mt-1 text-danger">
                                        {{ number_format($summary['total_penalty'] ?? 0, 2) }}
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Repayment Details</h6>
                    </div>
                    <div class="card-body">
                        @if(isset($repayments) && (($summary['transaction_count'] ?? 0) > 0))
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th scope="col">Payment Date</th>
                                        <th scope="col">Amount Paid</th>
                                        <th scope="col">Payment Method</th>
                                        <th scope="col">Loan Officer</th>
                                        <th scope="col">Customer Name</th>
                                        <th scope="col">Loan No</th>
                                        <th scope="col">Loan Product</th>
                                        <th scope="col">Principal</th>
                                        <th scope="col">Interest</th>
                                        <th scope="col">Fee Amount</th>
                                        <th scope="col">Penalty Amount</th>
                                        <th scope="col">Balance</th>
                                        <th scope="col">Branch</th>
                                        <th scope="col">Group Name</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($monthlyGroups as $monthGroup)
                                    <tr class="table-secondary">
                                        <td colspan="14" class="fw-bold">{{ $monthGroup->month_label }}</td>
                                    </tr>
                                        @forelse($monthGroup->rows as $repayment)
                                        <tr>
                                            <td>{{ \Carbon\Carbon::parse($repayment->payment_date)->format('M d, Y') }}</td>
                                            <td class="text-right">{{ number_format((float) ($repayment->amount_paid ?? 0), 2) }}</td>
                                            <td>{{ $repayment->payment_method ?? 'N/A' }}</td>
                                            <td>{{ $repayment->loan_officer_name ?? 'N/A' }}</td>
                                            <td>{{ $repayment->customer_name ?? 'N/A' }}</td>
                                            <td>{{ $repayment->loan_no ?? 'N/A' }}</td>
                                            <td>{{ $repayment->loan_product ?? 'N/A' }}</td>
                                            <td class="text-right">{{ number_format((float) ($repayment->principal ?? 0), 2) }}</td>
                                            <td class="text-right">{{ number_format((float) ($repayment->interest ?? 0), 2) }}</td>
                                            <td class="text-right">{{ number_format((float) ($repayment->fee_amount ?? 0), 2) }}</td>
                                            <td class="text-right">{{ number_format((float) ($repayment->penalty_amount ?? 0), 2) }}</td>
                                            <td class="text-right">{{ number_format((float) ($repayment->balance ?? 0), 2) }}</td>
                                            <td>{{ $repayment->branch_name ?? 'N/A' }}</td>
                                            <td>{{ $repayment->group_name ?? 'N/A' }}</td>
                                        </tr>
                                        @empty
                                        <tr>
                                            <td colspan="14" class="text-center text-muted">No payments found for this month.</td>
                                        </tr>
                                        @endforelse
                                    <tr class="table-light fw-bold">
                                        <td>{{ $monthGroup->month_label }} Total</td>
                                        <td class="text-right">{{ number_format($monthGroup->summary['total_paid'] ?? 0, 2) }}</td>
                                        <td colspan="5"></td>
                                        <td class="text-right">{{ number_format($monthGroup->summary['total_principal'] ?? 0, 2) }}</td>
                                        <td class="text-right">{{ number_format($monthGroup->summary['total_interest'] ?? 0, 2) }}</td>
                                        <td class="text-right">{{ number_format($monthGroup->summary['total_fees'] ?? 0, 2) }}</td>
                                        <td class="text-right">{{ number_format($monthGroup->summary['total_penalty'] ?? 0, 2) }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                    @endforeach
                                    <tr class="table-dark text-white fw-bold">
                                        <td>Grand Total</td>
                                        <td class="text-right">{{ number_format($summary['total_paid'] ?? 0, 2) }}</td>
                                        <td colspan="5"></td>
                                        <td class="text-right">{{ number_format($summary['total_principal'] ?? 0, 2) }}</td>
                                        <td class="text-right">{{ number_format($summary['total_interest'] ?? 0, 2) }}</td>
                                        <td class="text-right">{{ number_format($summary['total_fees'] ?? 0, 2) }}</td>
                                        <td class="text-right">{{ number_format($summary['total_penalty'] ?? 0, 2) }}</td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        @else
                        <div class="text-center py-4">
                            <i class="bx bx-info-circle fs-1 text-muted"></i>
                            <p class="mt-2 text-muted">No loan repayment data found for the selected criteria.</p>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

<script>
    function exportReport(type, action) {
        const form = document.getElementById('loanRepaymentForm');
        const formData = new FormData(form);
        formData.append('export_type', type);
        formData.append('export_action', action);

        const url = '{{ route("accounting.loans.reports.loan-export-repayment") }}?' + new URLSearchParams(Object.fromEntries(formData));

        Swal.fire({
            title: 'Generating Report...',
            html: `Please wait while we prepare your ${type.toUpperCase()} report.`,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        setTimeout(() => {
            Swal.close();
        }, 3000);
    }
</script>
@endsection
