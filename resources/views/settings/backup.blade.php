@extends('layouts.main')

@section('title', 'Backup Settings')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Backup Settings', 'url' => '#', 'icon' => 'bx bx-data']
        ]" />
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0 text-uppercase">BACKUP & RESTORE</h6>
            <a href="{{ route('settings.system') }}#backup-tab" class="btn btn-sm btn-outline-secondary">
                <i class="bx bx-cog me-1"></i> Backup Configuration
            </a>
        </div>
        <hr/>

        <div class="row">
            <!-- Statistics Cards -->
            <div class="col-12">
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-white" style="background-color: #fcd105;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bx bx-data fs-1"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">{{ $stats['total'] ?? 0 }}</h4>
                                        <p class="mb-0">Total Backups</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white" style="background-color: #23A036;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bx bx-check-circle fs-1"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">{{ $stats['completed'] ?? 0 }}</h4>
                                        <p class="mb-0">Completed</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white" style="background-color: #006400;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bx bx-x-circle fs-1"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">{{ $stats['failed'] ?? 0 }}</h4>
                                        <p class="mb-0">Failed</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white" style="background-color: #fcd105;">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bx bx-hdd fs-1"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">{{ number_format(($stats['total_size'] ?? 0) / 1024 / 1024, 2) }} MB</h4>
                                        <p class="mb-0">Total Size</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Create Backup Section -->
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Create New Backup</h5>
                        <p class="text-muted small mb-3">
                            <i class="bx bx-info-circle me-1"></i>
                            Backups run in the background with full data (database includes all data). After clicking Create, refresh the page to see the backup appear as "In Progress" and then "Completed".
                        </p>

                        {{-- Job status (when just dispatched, like reminder SMS) --}}
                        <div id="backupJobStatusCard" class="card mb-3 border-primary" style="display: none;">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bx bx-loader-alt bx-spin me-2"></i> Backup job in progress</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-1"><strong>Status:</strong> <span id="backupJobStatus">—</span></p>
                                <p class="mb-0 small text-muted" id="backupJobSummary"></p>
                            </div>
                        </div>
                        
                        @if(session('success'))
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bx bx-check-circle me-2"></i>
                                {{ session('success') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        @if(session('error'))
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bx bx-error-circle me-2"></i>
                                {{ session('error') }}
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        @endif

                        <form action="{{ route('settings.backup.create') }}" method="POST">
                            @csrf
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="type" class="form-label">Backup Type <span class="text-danger">*</span></label>
                                        <select class="form-select" id="type" name="type" required>
                                            <option value="">Select backup type</option>
                                            <option value="database">Database Only</option>
                                            <option value="full">Full Backup (Database + Files)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <input type="text" class="form-control" id="description" name="description" 
                                               placeholder="Optional description for this backup">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="mb-3">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="submit" class="btn w-100" style="background-color: #006400; border-color: #006400; color: white;">
                                            <i class="bx bx-plus me-1"></i> Create Backup
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Backup List -->
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="card-title mb-0">Backup History</h5>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#cleanModal">
                                <i class="bx bx-trash me-1"></i> Clean Old Backups
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-bordered" id="backupHistoryTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Restore Form (Hidden) -->
        <form id="restoreForm" action="{{ route('settings.backup.restore') }}" method="POST" style="display: none;">
            @csrf
            <input type="hidden" id="restoreBackupId" name="backup_id">
        </form>
    </div>
</div>

<!-- Clean Old Backups Modal -->
<div class="modal fade" id="cleanModal" tabindex="-1" aria-labelledby="cleanModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cleanModalLabel">Clean Old Backups</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('settings.backup.clean') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="days" class="form-label">Delete backups older than (days)</label>
                        <input type="number" class="form-control" id="days" name="days" 
                               value="30" min="1" max="365" required>
                        <div class="form-text">This will permanently delete backups older than the specified number of days.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Clean Old Backups</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!--end page wrapper -->
<!--start overlay-->
<div class="overlay toggle-icon"></div>
<!--end overlay-->
<!--Start Back To Top Button--> <a href="javaScript:;" class="back-to-top"><i class='bx bxs-up-arrow-alt'></i></a>
<!--End Back To Top Button-->
<footer class="page-footer">
    <p class="mb-0">Copyright © {{ date('Y') }}. All right reserved. -- By SAFCO FINTECH</p>
</footer>

@endsection

@push('scripts')
<script>
$(document).ready(function() {
    $('#backupHistoryTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("settings.backup.history.data") }}',
            type: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        },
        columns: [
            { data: 'name_cell', name: 'name', orderable: true, searchable: true },
            { data: 'type_badge', name: 'type', orderable: true, searchable: false },
            { data: 'formatted_size', name: 'size', orderable: true, searchable: false },
            { data: 'status_badge', name: 'status', orderable: true, searchable: false },
            { data: 'creator_name', name: 'creator.name', orderable: false, searchable: true },
            { data: 'created_at_fmt', name: 'created_at', orderable: true, searchable: false },
            { data: 'actions', name: 'actions', orderable: false, searchable: false }
        ],
        order: [[5, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50], [10, 25, 50]],
        language: {
            processing: '<div class="spinner-border text-primary" role="status"></div>',
            emptyTable: 'No backups found.',
            zeroRecords: 'No matching backups found.'
        }
    });

    $(document).on('click', '.backup-restore-btn', function() {
        var id = $(this).data('backup-id');
        var name = $(this).data('name') || '';
        if (typeof window.confirmRestore === 'function') {
            window.confirmRestore(id, name);
        }
    });
    $(document).on('click', '.backup-delete-btn', function() {
        var hashId = $(this).data('hash-id');
        var name = $(this).data('name') || '';
        if (typeof window.confirmDelete === 'function') {
            window.confirmDelete(hashId, name);
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Make functions globally available
    window.confirmRestore = function(backupId, backupName) {
        Swal.fire({
            title: 'Confirm Restore',
            text: `Are you sure you want to restore from "${backupName}"? This will overwrite current data.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, restore it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('restoreBackupId').value = backupId;
                document.getElementById('restoreForm').submit();
            }
        });
    };

    window.confirmDelete = function(backupId, backupName) {
        Swal.fire({
            title: 'Confirm Delete',
            text: `Are you sure you want to delete "${backupName}"? This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `{{ url('settings/backup') }}/${backupId}`;
                
                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = '_token';
                csrfToken.value = '{{ csrf_token() }}';
                
                const methodField = document.createElement('input');
                methodField.type = 'hidden';
                methodField.name = '_method';
                methodField.value = 'DELETE';
                
                form.appendChild(csrfToken);
                form.appendChild(methodField);
                document.body.appendChild(form);
                form.submit();
            }
        });
    };
});
</script>

@if(session('job_log_id'))
<script>
(function() {
    var jobLogId = {{ (int) session('job_log_id') }};
    if (!jobLogId) return;
    var statusUrl = "{{ url('settings/backup/status') }}/" + jobLogId;
    var card = document.getElementById('backupJobStatusCard');
    var statusEl = document.getElementById('backupJobStatus');
    var summaryEl = document.getElementById('backupJobSummary');
    if (!card || !statusEl || !summaryEl) return;
    card.style.display = 'block';
    function poll() {
        fetch(statusUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) return;
                statusEl.textContent = data.status || '—';
                summaryEl.textContent = data.summary || data.error_message || '';
                if (data.status === 'running') {
                    setTimeout(poll, 3000);
                } else {
                    card.querySelector('.card-header').classList.remove('bg-primary');
                    card.querySelector('.card-header').classList.add(data.status === 'completed' ? 'bg-success' : 'bg-danger');
                    card.querySelector('.card-header h6').innerHTML = '<i class="bx bx-check me-2"></i> Job finished';
                    if (typeof $ !== 'undefined' && $.fn.DataTable && $.fn.DataTable.isDataTable('#backupHistoryTable')) {
                        $('#backupHistoryTable').DataTable().ajax.reload(null, false);
                    }
                }
            })
            .catch(function() {});
    }
    poll();
})();
</script>
@endif
@endpush 