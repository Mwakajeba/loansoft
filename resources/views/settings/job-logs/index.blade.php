@extends('layouts.main')

@section('title', 'Job Logs')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Job Logs', 'url' => '#', 'icon' => 'bx bx-list-ul']
        ]" />

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bx bx-list-ul me-2"></i>Job Logs</h5>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <label class="form-label">Job Name</label>
                        <select id="filter_job_name" class="form-select">
                            <option value="">All Jobs</option>
                            <option value="AccruePenaltyJob">Penalty Accrual</option>
                            <option value="CalculateDailyInterestJob">Daily Interest Calculation</option>
                            <option value="CalculateLoanPenaltyJob">Loan Penalty Calculation</option>
                            <option value="MatureInterestJob">Mature Interest Job</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select id="filter_status" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="running">Running</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date From</label>
                        <input type="date" id="filter_date_from" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date To</label>
                        <input type="date" id="filter_date_to" class="form-control">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" id="btn_filter" class="btn btn-primary me-2">
                            <i class="bx bx-filter-alt"></i> Filter
                        </button>
                        <button type="button" id="btn_reset" class="btn btn-secondary">
                            <i class="bx bx-reset"></i> Reset
                        </button>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-light-primary border-0">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-1">Total Jobs</h6>
                                <h4 class="mb-0 text-primary" id="total_jobs">0</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light-success border-0">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-1">Completed</h6>
                                <h4 class="mb-0 text-success" id="completed_jobs">0</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light-danger border-0">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-1">Failed</h6>
                                <h4 class="mb-0 text-danger" id="failed_jobs">0</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light-info border-0">
                            <div class="card-body text-center">
                                <h6 class="text-muted mb-1">Running</h6>
                                <h4 class="mb-0 text-info" id="running_jobs">0</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="table-responsive">
                    <table id="jobLogsTable" class="table table-bordered table-striped w-100">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Job Name</th>
                                <th>Status</th>
                                <th>Processed</th>
                                <th>Successful</th>
                                <th>Failed</th>
                                <th>Total Amount</th>
                                <th>Duration</th>
                                <th>Started At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var table = $('#jobLogsTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ route('settings.job-logs.data') }}",
                data: function(d) {
                    d.job_name = $('#filter_job_name').val();
                    d.status = $('#filter_status').val();
                    d.date_from = $('#filter_date_from').val();
                    d.date_to = $('#filter_date_to').val();
                },
                error: function(xhr, error, thrown) {
                    console.error('DataTables Ajax Error:', error, thrown);
                    console.error('Response:', xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error Loading Data',
                        text: 'Failed to load job logs. Please refresh the page and try again.',
                        footer: 'Error: ' + (xhr.responseJSON?.message || error)
                    });
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
                { data: 'job_name', name: 'job_name' },
                { data: 'status_badge', name: 'status' },
                { data: 'processed', name: 'processed' },
                { data: 'successful', name: 'successful' },
                { data: 'failed', name: 'failed' },
                { data: 'formatted_amount', name: 'total_amount' },
                { data: 'formatted_duration', name: 'duration_seconds' },
                { data: 'started_at_formatted', name: 'started_at' },
                { data: 'actions', name: 'actions', orderable: false, searchable: false }
            ],
            order: [[8, 'desc']],
            drawCallback: function(settings) {
                updateSummaryCards(settings.json);
            }
        });

        function updateSummaryCards(json) {
            // Update summary cards based on server response
            if (json && json.summary) {
                $('#total_jobs').text(json.summary.total || 0);
                $('#completed_jobs').text(json.summary.completed || 0);
                $('#failed_jobs').text(json.summary.failed || 0);
                $('#running_jobs').text(json.summary.running || 0);
            }
        }

        $('#btn_filter').on('click', function() {
            table.ajax.reload();
        });

        $('#btn_reset').on('click', function() {
            $('#filter_job_name').val('');
            $('#filter_status').val('');
            $('#filter_date_from').val('');
            $('#filter_date_to').val('');
            table.ajax.reload();
        });
    });
</script>
@endpush
