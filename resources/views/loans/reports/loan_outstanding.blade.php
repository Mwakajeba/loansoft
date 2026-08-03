@extends('layouts.main')

@section('title', 'Loan Outstanding Balance Report')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
             ['label' => 'Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-file'],
            ['label' => 'Loan Outstanding Balance Report', 'url' => '#', 'icon' => 'bx bx-calculator']
        ]" />
        <h6 class="mb-0 text-uppercase">LOAN OUTSTANDING BALANCE REPORT</h6>
        <hr />

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bx bx-calculator me-2"></i>Loan Outstanding Balance Report</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('accounting.loans.reports.loan_outstanding') }}">
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label for="as_of_date" class="form-label">As of Date</label>
                            <input type="date" class="form-control" id="as_of_date" name="as_of_date" value="{{ $asOfDate ?? request('as_of_date', date('Y-m-d')) }}">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="branch_id" class="form-label">Branch</label>
                            <select class="form-select" id="branch_id" name="branch_id">
                                @if(($branches->count() ?? 0) > 1)
                                    <option value="all" {{ ($branchId ?? request('branch_id')) === 'all' ? 'selected' : '' }}>All My Branches</option>
                                @endif
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ ($branchId ?? request('branch_id')) == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="group_id" class="form-label">Group</label>
                            <select class="form-select select2-single" id="group_id" name="group_id" data-placeholder="Search group...">
                                <option value="all" {{ empty($groupId) || ($groupId ?? '') === 'all' ? 'selected' : '' }}>All Groups</option>
                                @foreach($groups as $group)
                                    <option value="{{ $group->id }}" {{ (string) ($groupId ?? '') === (string) $group->id ? 'selected' : '' }}>
                                        {{ $group->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="loan_officer_id" class="form-label">Loan Officer</label>
                            <select class="form-select" id="loan_officer_id" name="loan_officer_id">
                                <option value="">All Officers</option>
                                @foreach($loanOfficers as $officer)
                                    <option value="{{ $officer->id }}" {{ ($loanOfficerId ?? request('loan_officer_id')) == $officer->id ? 'selected' : '' }}>{{ $officer->name }}</option>
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
                
                @if(isset($outstandingData) && !empty($outstandingData))
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                                <i class="bx bx-file me-1"></i> Export Excel
                            </button>
                            <button type="button" class="btn btn-danger" onclick="exportReport('pdf')">
                                <i class="bx bx-file-pdf me-1"></i> Export PDF
                            </button>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        @if(isset($outstandingData))
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bx bx-list-ul me-2"></i>Loan Outstanding Balance Report</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm">
                        <thead>
                            <tr class="text-white text-center" style="background:#4472C4;">
                                <th colspan="14">Loan Information</th>
                                <th colspan="4" style="background:#28a745;">PAID AMOUNT</th>
                                <th colspan="5" style="background:#fd7e14;">OUTSTANDING AMOUNT</th>
                                <th style="background:#dc3545;">Outstanding Balance</th>
                            </tr>
                            <tr class="text-white" style="background:#4472C4;">
                                <th>Customer</th><th>Customer No</th><th>Group</th><th>Phone</th><th>Loan No</th><th>Expires</th>
                                <th>Branch</th><th>Loan Officer</th><th>Disbursed Date</th>
                                <th class="text-end">Disbursed Amount</th><th class="text-end">Total Interest</th>
                                <th class="text-end">Total P+I</th><th class="text-end">Expected Fees</th><th class="text-end">Total penalties</th>
                                <th class="text-end" style="background:#28a745;">Principal Paid</th>
                                <th class="text-end" style="background:#28a745;">Interest Paid</th>
                                <th class="text-end" style="background:#28a745;">Fees Paid</th>
                                <th class="text-end" style="background:#28a745;">Penalty Paid</th>
                                <th class="text-end" style="background:#fd7e14;">Outstanding Principal</th>
                                <th class="text-end" style="background:#fd7e14;">Accrued/Outstanding Interest</th>
                                <th class="text-end" style="background:#fd7e14;">Outstanding Fees</th>
                                <th class="text-end" style="background:#fd7e14;">Outstanding Penalty</th>
                                <th class="text-end" style="background:#fd7e14;">Other Outstanding</th>
                                <th class="text-end" style="background:#dc3545;">Outstanding Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($outstandingData as $row)
                                <tr>
                                    <td>{{ $row['customer'] }}</td>
                                    <td>{{ $row['customer_no'] }}</td>
                                    <td>{{ $row['group'] ?? 'Individual' }}</td>
                                    <td>{{ $row['phone'] }}</td>
                                    <td>{{ $row['loan_no'] }}</td>
                                    <td>{{ $row['expires'] }}</td>
                                    <td>{{ $row['branch'] }}</td>
                                    <td>{{ $row['loan_officer'] }}</td>
                                    <td>{{ $row['disbursed_date'] }}</td>
                                    <td class="text-end">{{ number_format($row['disbursed_amount'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['total_interest'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['total_principal_interest'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['expected_fees'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($row['total_penalties'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($row['principal_paid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['interest_paid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['fees_paid'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($row['penalty_paid'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($row['outstanding_principal'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['outstanding_interest'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['outstanding_fees'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($row['outstanding_penalty'] ?? 0, 2) }}</td>
                                    <td class="text-end">{{ number_format($row['other_outstanding'] ?? 0, 2) }}</td>
                                    <td class="text-end fw-bold">{{ number_format($row['outstanding_balance'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="24" class="text-center text-muted">No outstanding data found.</td></tr>
                            @endforelse
                        </tbody>
                        @if(!empty($outstandingData))
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="9" class="text-center">TOTALS</th>
                                <th class="text-end">{{ number_format($summary['total_disbursed'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_interest'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_principal_interest'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_expected_fees'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_penalties'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_principal_paid'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_interest_paid'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_fees_paid'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_penalty_paid'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_outstanding_principal'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_outstanding_interest'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_outstanding_fees'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_outstanding_penalty'] ?? 0, 2) }}</th>
                                <th class="text-end">0.00</th>
                                <th class="text-end">{{ number_format($summary['total_outstanding_balance'] ?? 0, 2) }}</th>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

<script>
function exportReport(type) {
    const form = document.querySelector('form');
    const formData = new FormData(form);
    formData.append('export_type', type);
    
    // Convert FormData to URL parameters
    const params = new URLSearchParams();
    for (let [key, value] of formData.entries()) {
        if (value !== '') {
            params.append(key, value);
        }
    }
    
    const url = '{{ route("accounting.loans.reports.loan_outstanding") }}?' + params.toString();
    
    // Show loading state
    Swal.fire({
        title: 'Generating Report...',
        text: 'Please wait while we prepare your ' + type.toUpperCase() + ' report.',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Download the file
    window.location.href = url;
    
    // Close the loading state after a short delay
    setTimeout(() => {
        Swal.close();
    }, 2000);
}
</script>
@endsection
