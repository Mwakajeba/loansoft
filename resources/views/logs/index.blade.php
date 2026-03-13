@extends('layouts.main')

@section('title', $pageTitle ?? 'Activity Logs')

@section('content')
<div class="page-wrapper">
    <div class="page-content">

        <!-- Breadcrumbs -->
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => $pageTitle ?? 'Activity Logs', 'url' => '#', 'icon' => 'bx bx-list-ul']
        ]" />

        <h6 class="mb-0 text-uppercase">{{ $pageTitle ?? 'ACTIVITY LOGS' }}</h6>
        <hr />

        <!-- Filters -->
        <div class="card radius-10 mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('settings.logs.index') }}" id="logsFilterForm" class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small">Date From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Date To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">User</label>
                        <select name="user_id" class="form-select form-select-sm">
                            <option value="">All Users</option>
                            @foreach($users ?? [] as $u)
                                <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Model</label>
                        <select name="model" class="form-select form-select-sm">
                            <option value="">All Models</option>
                            @foreach($models ?? [] as $m)
                                <option value="{{ $m }}" {{ request('model') === $m ? 'selected' : '' }}>{{ $m }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">Action</label>
                        <select name="action" class="form-select form-select-sm">
                            <option value="">All Actions</option>
                            @foreach($actions ?? [] as $a)
                                <option value="{{ $a }}" {{ request('action') === $a ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $a)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bx bx-filter-alt"></i> Filter
                        </button>
                        <a href="{{ route('settings.logs.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary & Export -->
        <div class="row row-cols-1 row-cols-lg-4 mb-3">
            <div class="col mb-2">
                <div class="card radius-10">
                    <div class="card-body d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="text-muted mb-1">Total Logs</p>
                            <h4 class="mb-0">{{ number_format($totalLogs ?? 0) }}</h4>
                        </div>
                        <div class="widgets-icons text-white" style="background-color: #006400 !important;">
                            <i class='bx bx-history'></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col mb-2 d-flex align-items-center">
                <div class="btn-group">
                    <a href="{{ route('settings.logs.export.excel', request()->only(['date_from','date_to','user_id','model','action'])) }}" class="btn btn-sm btn-success">
                        <i class="bx bx-file-blank"></i> Export Excel
                    </a>
                    <a href="{{ route('settings.logs.export.pdf', request()->only(['date_from','date_to','user_id','model','action'])) }}" class="btn btn-sm btn-danger">
                        <i class="bx bx-file"></i> Export PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="row">
            <div class="col-12">
                <div class="card radius-10">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered dt-responsive nowrap" id="logsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Model</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>Changes (before → after)</th>
                                        <th>IP Address</th>
                                        <th>Device</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- DataTables will populate rows via AJAX -->
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
@push('scripts')
<script>
    $(document).ready(function() {
        var filterParams = {
            date_from: '{{ request("date_from") }}',
            date_to: '{{ request("date_to") }}',
            user_id: '{{ request("user_id") }}',
            model: '{{ request("model") }}',
            action: '{{ request("action") }}'
        };
        var table = $('#logsTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("settings.logs.data") }}',
                type: 'GET',
                data: function(d) {
                    $.extend(d, filterParams);
                },
                timeout: 60000,
                error: function(xhr, error, code) {
                    console.error('Activity Logs DataTables Ajax Error:', error, code, xhr);
                }
            },
            columns: [
                { data: 'activity_time', name: 'activity_time', title: 'Date' },
                { data: 'user_name', name: 'user.name', title: 'User' },
                { data: 'model', name: 'model', title: 'Model' },
                { data: 'action', name: 'action', title: 'Action' },
                { data: 'description', name: 'description', title: 'Description' },
                { data: 'changes_summary', name: 'changes_summary', title: 'Changes (before → after)', orderable: false, searchable: false },
                { data: 'ip_address', name: 'ip_address', title: 'IP Address' },
                { data: 'device', name: 'device', title: 'Device' },
            ],
            order: [[0, 'desc']],
            pageLength: 10,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            responsive: true,
            language: {
                search: "",
                searchPlaceholder: "Search logs...",
                processing: '<div class="d-flex justify-content-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>',
                emptyTable: "No activity logs found",
                info: "Showing _START_ to _END_ of _TOTAL_ logs",
                infoEmpty: "Showing 0 to 0 of 0 logs",
                infoFiltered: "(filtered from _MAX_ total logs)",
                lengthMenu: "Show _MENU_ logs per page",
                zeroRecords: "No matching logs found"
            }
        });
    });
</script>
@endpush