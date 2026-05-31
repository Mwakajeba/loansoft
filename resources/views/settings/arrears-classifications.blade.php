@extends('layouts.main')

@section('title', 'Arrears Classifications')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Arrears Classifications', 'url' => '#', 'icon' => 'bx bx-category']
        ]" />

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-uppercase">Arrears Classifications / Aging Buckets</h6>
            <a href="{{ route('settings.index') }}" class="btn btn-secondary btn-sm">
                <i class="bx bx-arrow-back me-1"></i> Back to Settings
            </a>
        </div>
        <hr />

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

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="card-title mb-1">Loan Aging Buckets &amp; Provision Rates</h5>
                        <p class="text-muted mb-0">Configure days past due ranges, classification statuses, and provision percentages</p>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassificationModal">
                        <i class="bx bx-plus me-1"></i> Add Classification
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="arrears-classifications-table" style="width:100%">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>Bucket (Days)</th>
                                <th>Status</th>
                                <th>Provision %</th>
                                <th>Comments</th>
                                <th>Active</th>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div class="mt-4" id="clear-classifications-box" style="display: none;">
                    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0">
                        <div>
                            <strong><i class="bx bx-trash me-1"></i>Clear all classifications</strong>
                            <span class="d-block small text-muted mb-0">Removes every bucket for this company so you can recreate Tanzania defaults from scratch.</span>
                        </div>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearAllClassifications()">
                            <i class="bx bx-reset me-1"></i> Clear all
                        </button>
                    </div>
                </div>

                <div class="mt-4" id="seed-defaults-box">
                    <div class="alert alert-info">
                        <h6 class="alert-heading"><i class="bx bx-bulb me-2"></i>Tanzania default buckets</h6>
                        <p class="mb-2">One-click setup uses the following day-past-due ranges and provision rates:</p>
                        <ul class="mb-2">
                            <li><strong>0–5 days</strong> — Current (1%)</li>
                            <li><strong>6–30 days</strong> — Especially mentioned (5%)</li>
                            <li><strong>31–60 days</strong> — Substandard (25%)</li>
                            <li><strong>61–90 days</strong> — Doubtful (50%)</li>
                            <li><strong>91+ days</strong> — Loss / NPL (100%)</li>
                        </ul>
                        <p class="small text-muted mb-2">Adjust buckets after creation if your policy or regulator guidance differs.</p>
                        <button type="button" class="btn btn-info btn-sm" onclick="seedDefaultClassifications()">
                            <i class="bx bx-magic-wand me-1"></i> Create Tanzania default classifications
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Classification Modal -->
<div class="modal fade" id="addClassificationModal" tabindex="-1" aria-labelledby="addClassificationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('settings.arrears-classifications.store') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="addClassificationModalLabel">
                        <i class="bx bx-plus-circle me-2"></i>Add Arrears Classification
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="days_from" class="form-label">Days From <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="days_from" name="days_from" min="0" value="{{ old('days_from') }}" required>
                            <small class="text-muted">Starting day (e.g., 0, 1, 31, 91, 181)</small>
                        </div>
                        <div class="col-md-6">
                            <label for="days_to" class="form-label">Days To</label>
                            <input type="number" class="form-control" id="days_to" name="days_to" min="0" value="{{ old('days_to') }}">
                            <small class="text-muted">Leave empty for unlimited (e.g., 366+ for Loss)</small>
                        </div>
                        <div class="col-md-12">
                            <label for="bucket_label" class="form-label">Bucket Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="bucket_label" name="bucket_label" placeholder="e.g., 1-30, 31-90, 181-365, 366+" value="{{ old('bucket_label') }}" required>
                            <small class="text-muted">Display label for this bucket</small>
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="">-- Select Status --</option>
                                <option value="Current" @selected(old('status') === 'Current')>Current</option>
                                <option value="Past Due" @selected(old('status') === 'Past Due')>Past Due</option>
                                <option value="Especially Mentioned" @selected(old('status') === 'Especially Mentioned')>Especially Mentioned</option>
                                <option value="Watch" @selected(old('status') === 'Watch')>Watch</option>
                                <option value="Substandard" @selected(old('status') === 'Substandard')>Substandard</option>
                                <option value="Doubtful" @selected(old('status') === 'Doubtful')>Doubtful</option>
                                <option value="Loss/NPL" @selected(old('status') === 'Loss/NPL')>Loss/NPL</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="provision_percentage" class="form-label">Provision % <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="provision_percentage" name="provision_percentage" min="0" max="100" step="0.01" value="{{ old('provision_percentage') }}" required>
                            <small class="text-muted">e.g., 0, 1, 5, 25, 50, 100</small>
                        </div>
                        <div class="col-md-6">
                            <label for="sort_order" class="form-label">Sort Order</label>
                            <input type="number" class="form-control" id="sort_order" name="sort_order" min="0" value="{{ old('sort_order', 0) }}">
                        </div>
                        <div class="col-md-6">
                            <label for="is_active" class="form-label">Status</label>
                            <select class="form-select" id="is_active" name="is_active">
                                <option value="1" @selected(old('is_active', '1') == '1')>Active</option>
                                <option value="0" @selected(old('is_active') === '0')>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label for="comments" class="form-label">Comments</label>
                            <textarea class="form-control" id="comments" name="comments" rows="3" placeholder="Enter any additional notes or comments...">{{ old('comments') }}</textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bx bx-x me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-1"></i>Save Classification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Classification Modal -->
<div class="modal fade" id="editClassificationModal" tabindex="-1" aria-labelledby="editClassificationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editClassificationForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title" id="editClassificationModalLabel">
                        <i class="bx bx-edit me-2"></i>Edit Arrears Classification
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_days_from" class="form-label">Days From <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_days_from" name="days_from" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_days_to" class="form-label">Days To</label>
                            <input type="number" class="form-control" id="edit_days_to" name="days_to" min="0">
                            <small class="text-muted">Leave empty for unlimited</small>
                        </div>
                        <div class="col-md-12">
                            <label for="edit_bucket_label" class="form-label">Bucket Label <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_bucket_label" name="bucket_label" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="">-- Select Status --</option>
                                <option value="Current">Current</option>
                                <option value="Past Due">Past Due</option>
                                <option value="Especially Mentioned">Especially Mentioned</option>
                                <option value="Watch">Watch</option>
                                <option value="Substandard">Substandard</option>
                                <option value="Doubtful">Doubtful</option>
                                <option value="Loss/NPL">Loss/NPL</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_provision_percentage" class="form-label">Provision % <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_provision_percentage" name="provision_percentage" min="0" max="100" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_sort_order" class="form-label">Sort Order</label>
                            <input type="number" class="form-control" id="edit_sort_order" name="sort_order" min="0">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_is_active" class="form-label">Status</label>
                            <select class="form-select" id="edit_is_active" name="is_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label for="edit_comments" class="form-label">Comments</label>
                            <textarea class="form-control" id="edit_comments" name="comments" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bx bx-x me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bx bx-save me-1"></i>Update Classification
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    var arrearsClassificationsTable;
    var arrearsClassificationsBaseUrl = @json(url('settings/arrears-classifications'));

    $(function() {
        arrearsClassificationsTable = $('#arrears-classifications-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: "{{ route('settings.arrears-classifications.data') }}",
            columns: [
                { data: 'sort_order', name: 'sort_order' },
                { data: 'bucket_label', name: 'bucket_label', orderable: true, searchable: true },
                { data: 'status_badge', name: 'status', orderable: false, searchable: false },
                { data: 'provision_formatted', name: 'provision_percentage', orderable: true, searchable: false },
                { data: 'comments', name: 'comments', defaultContent: '-', orderable: false },
                { data: 'active_badge', name: 'is_active', orderable: false, searchable: false },
                { data: 'actions', name: 'actions', orderable: false, searchable: false }
            ],
            order: [[0, 'asc']],
            drawCallback: function() {
                var api = arrearsClassificationsTable;
                var total = api ? api.page.info().recordsTotal : 0;
                $('#seed-defaults-box').toggle(total === 0);
                $('#clear-classifications-box').toggle(total > 0);
            }
        });

        $(document).on('click', '.btn-edit-classification', function() {
            var raw = $(this).attr('data-row');
            var row = typeof raw === 'string' ? JSON.parse(raw) : raw;
            editClassification(row);
        });
    });

    function editClassification(classification) {
        document.getElementById('editClassificationForm').action = arrearsClassificationsBaseUrl + '/' + classification.id;
        document.getElementById('edit_days_from').value = classification.days_from;
        document.getElementById('edit_days_to').value = classification.days_to != null ? classification.days_to : '';
        document.getElementById('edit_bucket_label').value = classification.bucket_label || '';
        document.getElementById('edit_status').value = classification.status || '';
        document.getElementById('edit_provision_percentage').value = classification.provision_percentage != null ? classification.provision_percentage : '';
        document.getElementById('edit_sort_order').value = classification.sort_order != null ? classification.sort_order : '';
        document.getElementById('edit_is_active').value = classification.is_active ? '1' : '0';
        document.getElementById('edit_comments').value = classification.comments || '';

        var modal = new bootstrap.Modal(document.getElementById('editClassificationModal'));
        modal.show();
    }

    $(document).on('click', '.delete-classification-btn', function(e) {
        e.preventDefault();
        const classificationId = $(this).data('id');
        const bucketLabel = $(this).data('bucket-label');

        Swal.fire({
            title: 'Are you sure?',
            html: 'Are you sure you want to delete this classification?<br><strong>' + bucketLabel + '</strong><br><small class="text-muted">This action cannot be undone.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(arrearsClassificationsBaseUrl + '/' + classificationId, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) { return response.json().then(function(data) { return { ok: response.ok, data: data }; }); })
                .then(function(result) {
                    if (result.ok && result.data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted',
                            text: result.data.message || 'Classification removed.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        if (typeof arrearsClassificationsTable !== 'undefined') {
                            arrearsClassificationsTable.ajax.reload(null, false);
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: (result.data && result.data.message) || 'Delete failed.'
                        });
                    }
                })
                .catch(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please try again.'
                    });
                });
            }
        });
    });

    function clearAllClassifications() {
        Swal.fire({
            title: 'Clear all classifications?',
            html: 'This will <strong>permanently delete</strong> every arrears bucket for your company. You can recreate Tanzania defaults afterwards.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, clear all',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                fetch("{{ route('settings.arrears-classifications.clear-all') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Cleared',
                            text: data.message,
                            confirmButtonColor: '#3085d6'
                        }).then(function() {
                            if (typeof arrearsClassificationsTable !== 'undefined') {
                                arrearsClassificationsTable.ajax.reload(null, false);
                            } else {
                                window.location.reload();
                            }
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Clear failed.',
                            confirmButtonColor: '#d33'
                        });
                    }
                })
                .catch(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred. Please try again.',
                        confirmButtonColor: '#d33'
                    });
                });
            }
        });
    }

    function seedDefaultClassifications() {
        Swal.fire({
            title: 'Create Tanzania default classifications?',
            text: 'This will add the 0–5 / 6–30 / 31–60 / 61–90 / 91+ buckets with the standard provision rates (only if the list is empty).',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, create them!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch("{{ route('settings.arrears-classifications.seed-defaults') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: data.message,
                            confirmButtonColor: '#3085d6'
                        }).then(function() {
                            if (typeof arrearsClassificationsTable !== 'undefined') {
                                arrearsClassificationsTable.ajax.reload(null, false);
                            } else {
                                window.location.reload();
                            }
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: data.message || 'Could not create defaults.',
                            confirmButtonColor: '#d33'
                        });
                    }
                })
                .catch(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'An error occurred. Please try again.',
                        confirmButtonColor: '#d33'
                    });
                });
            }
        });
    }
</script>
@endpush

