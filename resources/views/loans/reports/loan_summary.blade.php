@extends('layouts.main')

@section('title', 'Loan Report')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-credit-card'],
            ['label' => 'Loan Report', 'url' => '#', 'icon' => 'bx bx-table']
        ]" />
        <h6 class="mb-0 text-uppercase">Loan Report</h6>
        <hr />

        <div class="card mb-4">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bx bx-table me-2"></i>Loan Report</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('reports.loans.loan_report') }}">
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label for="as_of_date" class="form-label">As of Date</label>
                            <input type="date" class="form-control" id="as_of_date" name="as_of_date" value="{{ $asOfDate ?? date('Y-m-d') }}">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="branch_id" class="form-label">Branch</label>
                            <select class="form-select" id="branch_id" name="branch_id">
                                @if(($branches->count() ?? 0) > 1)
                                    <option value="all" {{ ($branchId ?? '') === 'all' || empty($branchId) ? 'selected' : '' }}>All My Branches</option>
                                @endif
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ (string) ($branchId ?? '') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
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
                                    <option value="{{ $officer->id }}" {{ (string) ($loanOfficerId ?? '') === (string) $officer->id ? 'selected' : '' }}>{{ $officer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bx bx-search me-1"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>

                @if(isset($rows) && !empty($rows))
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="button" class="btn btn-success" onclick="exportLoanReport('excel')">
                                <i class="bx bx-file me-1"></i> Export Excel
                            </button>
                            <button type="button" class="btn btn-danger" onclick="exportLoanReport('pdf')">
                                <i class="bx bx-file-pdf me-1"></i> Export PDF
                            </button>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        @if(isset($rows))
        <div class="card mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm">
                        <thead>
                            <tr class="text-center" style="background:#ffff00;">
                                <th colspan="8">LOAN REPORT</th>
                            </tr>
                            <tr class="text-white" style="background:#4472C4;">
                                <th>Customer Name</th>
                                <th>Group Name</th>
                                <th>Phone Number</th>
                                <th class="text-end">Loan Amount</th>
                                <th class="text-end">Total Received</th>
                                <th class="text-end">Remain Balance</th>
                                <th class="text-end">Overdue Amount</th>
                                <th>Loan End Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td>{{ $row['customer_name'] }}</td>
                                    <td>{{ $row['group_name'] }}</td>
                                    <td>{{ $row['phone_number'] }}</td>
                                    <td class="text-end">{{ number_format($row['loan_amount'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['total_received'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['remain_balance'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['overdue_amount'], 2) }}</td>
                                    <td>{{ $row['loan_end_date'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted">No loan data found.</td></tr>
                            @endforelse
                        </tbody>
                        @if(!empty($rows))
                        <tfoot class="table-dark">
                            <tr>
                                <th colspan="3" class="text-center">TOTALS</th>
                                <th class="text-end">{{ number_format($summary['loan_amount'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['total_received'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['remain_balance'] ?? 0, 2) }}</th>
                                <th class="text-end">{{ number_format($summary['overdue_amount'] ?? 0, 2) }}</th>
                                <th></th>
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
@endsection

@push('scripts')
<script>
function exportLoanReport(type) {
    const form = document.querySelector('form');
    const formData = new FormData(form);
    formData.append('export_type', type);

    const params = new URLSearchParams();
    for (let [key, value] of formData.entries()) {
        if (value !== '') {
            params.append(key, value);
        }
    }

    window.location.href = '{{ route("reports.loans.loan_report") }}?' + params.toString();
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#group_id').select2({ width: '100%' });
    }
});
</script>
@endpush
