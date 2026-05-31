@extends('layouts.main')

@section('title', 'CRB Report')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loan Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-file'],
            ['label' => 'CRB Report', 'url' => '#', 'icon' => 'bx bx-file-find']
        ]" />

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bx bx-file-find me-2"></i>Credit Reference Bureau (CRB) Report</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" action="{{ route('accounting.loans.reports.crb') }}" class="mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="reporting_date" class="form-label">Reporting Date</label>
                            <input type="date" class="form-control" id="reporting_date" name="reporting_date" 
                                   value="{{ $reportingDate }}" required>
                        </div>
                        <div class="col-md-3">
                            <label for="branch_id" class="form-label">Branch</label>
                            <select class="form-select" id="branch_id" name="branch_id">
                                <option value="">All Branches</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ $branchId == $branch->id ? 'selected' : '' }}>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="loan_officer_id" class="form-label">Loan Officer</label>
                            <select class="form-select" id="loan_officer_id" name="loan_officer_id">
                                <option value="">All Officers</option>
                                @foreach($loanOfficers as $officer)
                                    <option value="{{ $officer->id }}" {{ $loanOfficerId == $officer->id ? 'selected' : '' }}>
                                        {{ $officer->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" class="btn btn-primary me-2" onclick="filterOnly()">
                                <i class="bx bx-filter me-1"></i>Filter
                            </button>
                            <div class="btn-group">
                                <button type="button" class="btn btn-success" onclick="exportData('excel')">
                                    <i class="bx bx-file me-1"></i>Excel
                                </button>
                                <button type="button" class="btn btn-danger" onclick="exportData('pdf')">
                                    <i class="bx bx-file-pdf me-1"></i>PDF
                                </button>
                            </div>
                        </div>
                        <script>
                        function filterOnly() {
                            const form = document.querySelector('form');
                            // Remove any export input if exists
                            const existingExport = form.querySelector('input[name="export"]');
                            if (existingExport) {
                                existingExport.remove();
                            }
                            form.submit();
                        }
                        
                        function exportData(type) {
                            const form = document.querySelector('form');
                            // Remove any existing export input
                            const existingExport = form.querySelector('input[name="export"]');
                            if (existingExport) {
                                existingExport.remove();
                            }
                            // Add new export input
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'export';
                            input.value = type;
                            form.appendChild(input);
                            form.submit();
                        }
                        </script>
                    </div>
                </form>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card border-0 bg-light-primary">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Loans</h6>
                                <h4 class="mb-0 text-primary">{{ number_format($summary['total_loans']) }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 bg-light-success">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Loan Amount</h6>
                                <h4 class="mb-0 text-success">TZS {{ number_format($summary['total_loan_amount'], 2) }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 bg-light-warning">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Outstanding</h6>
                                <h4 class="mb-0 text-warning">TZS {{ number_format($summary['total_outstanding'], 2) }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 bg-light-danger">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Past Due</h6>
                                <h4 class="mb-0 text-danger">TZS {{ number_format($summary['total_past_due'], 2) }}</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CRB Report Table -->
                <div class="expenses-table-card">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover mb-0 expenses-datatable" id="crbTable">
                        <thead class="table-light">
                            <tr>
                                <th>Reporting Date</th>
                                <th>Full Name</th>
                                <th>Contract Code</th>
                                <th>Customer Code</th>
                                <th>Branch</th>
                                <th>Loan Status</th>
                                <th>Type of Contract</th>
                                <th>Loan Purpose</th>
                                <th>Interest Rate</th>
                                <th class="text-end">Total Loan</th>
                                <th class="text-end">Total Loan Taken</th>
                                <th class="text-end">Installment Amount</th>
                                <th class="text-center">No. of Installments</th>
                                <th class="text-center">Outstanding Installments</th>
                                <th class="text-end">Outstanding Amount</th>
                                <th class="text-end">Past Due Amount</th>
                                <th class="text-center">Past Due Days</th>
                                <th class="text-center">No. of Due Installments</th>
                                <th>Date of Last Payment</th>
                                <th class="text-end">Total Monthly Payment</th>
                                <th>Payment Periodicity</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Real End Date</th>
                                <th>Collateral Type</th>
                                <th class="text-end">Collateral Value</th>
                                <th>Role of Customer</th>
                                <th>Currency</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($crbData as $data)
                                <tr>
                                    <td>{{ $data['reporting_date'] }}</td>
                                    <td>{{ $data['fullname'] }}</td>
                                    <td>{{ $data['contract_code'] }}</td>
                                    <td>{{ $data['customer_code'] }}</td>
                                    <td>{{ $data['branch'] }}</td>
                                    <td>
                                        <span class="badge bg-{{ $data['loan_status'] == 'Active' ? 'success' : ($data['loan_status'] == 'Defaulted' ? 'danger' : 'primary') }}">
                                            {{ $data['loan_status'] }}
                                        </span>
                                    </td>
                                    <td>{{ $data['type_of_contract'] }}</td>
                                    <td>{{ $data['loan_purpose'] }}</td>
                                    <td>{{ $data['interest_rate'] }}%</td>
                                    <td class="text-end">{{ number_format($data['total_loan'], 2) }}</td>
                                    <td class="text-end">{{ number_format($data['total_loan_taken'], 2) }}</td>
                                    <td class="text-end">{{ number_format($data['installment_amount'], 2) }}</td>
                                    <td class="text-center">{{ $data['number_of_installments'] }}</td>
                                    <td class="text-center">{{ $data['number_of_outstanding_installments'] }}</td>
                                    <td class="text-end">{{ number_format($data['outstanding_amount'], 2) }}</td>
                                    <td class="text-end">{{ number_format($data['past_due_amount'], 2) }}</td>
                                    <td class="text-center">
                                        @if($data['past_due_days'] > 0)
                                            <span class="badge bg-danger">{{ $data['past_due_days'] }}</span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $data['number_of_due_installments'] }}</td>
                                    <td>{{ $data['date_of_last_payment'] ? \Carbon\Carbon::parse($data['date_of_last_payment'])->format('d-m-Y') : 'N/A' }}</td>
                                    <td class="text-end">{{ number_format($data['total_monthly_payment'], 2) }}</td>
                                    <td>{{ $data['payment_periodicity'] }}</td>
                                    <td>{{ $data['start_date'] ? \Carbon\Carbon::parse($data['start_date'])->format('d-m-Y') : 'N/A' }}</td>
                                    <td>{{ $data['end_date'] ? \Carbon\Carbon::parse($data['end_date'])->format('d-m-Y') : 'N/A' }}</td>
                                    <td>{{ $data['real_end_date'] ? \Carbon\Carbon::parse($data['real_end_date'])->format('d-m-Y') : 'N/A' }}</td>
                                    <td>{{ $data['collateral_type'] }}</td>
                                    <td class="text-end">{{ number_format($data['collateral_value'], 2) }}</td>
                                    <td>{{ $data['role_of_customer'] }}</td>
                                    <td>{{ $data['currency'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="28" class="text-center text-muted py-4">
                                        <i class="bx bx-info-circle fs-1 mb-3"></i>
                                        <p>No loan data available for the selected criteria.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    $(document).ready(function() {
        $('#crbTable').DataTable({
            pageLength: 50,
            order: [[0, 'desc']],
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ],
            columnDefs: [
                { targets: [9, 10, 11, 14, 15], className: 'text-end' },
                { targets: [12, 13, 16, 17], className: 'text-center' }
            ]
        });
    });
</script>
@endpush
@endsection
