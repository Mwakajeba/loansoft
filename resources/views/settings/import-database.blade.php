@extends('layouts.main')

@section('title', 'Import Database')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'bx bx-cog'],
            ['label' => 'Import Database', 'url' => '#', 'icon' => 'bx bx-import']
        ]" />
        <h6 class="mb-0 text-uppercase">IMPORT DATABASE</h6>
        <hr/>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bx bx-check-circle me-2"></i>{{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bx bx-error-circle me-2"></i>{{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-lg-7">
                <div class="card border-primary">
                    <div class="card-body">
                        <h5 class="card-title">Upload SQL dump</h5>
                        <p class="text-muted">
                            Maximum file size: <strong>1024 MB</strong>. Accepted: <code>.sql</code>, <code>.sql.gz</code>, <code>.zip</code> (containing a .sql file).
                            Import runs in a background job to avoid request timeouts.
                        </p>

                        <div class="alert alert-warning">
                            <i class="bx bx-error me-1"></i>
                            This will overwrite the current database contents. Import starts automatically in the background after upload.
                            If status stays on <strong>queued</strong>, click <strong>Process now</strong> or run:
                            <code>php artisan database-import:process</code>
                        </div>

                        <div class="alert alert-danger">
                            <i class="bx bx-server me-1"></i>
                            If you see <strong>413 Request Entity Too Large</strong>, Nginx is blocking the upload.
                            Set <code>client_max_body_size 1100M;</code> in the site’s Nginx config, raise PHP
                            <code>upload_max_filesize</code>/<code>post_max_size</code> to ≥1024M, then
                            <code>sudo nginx -t && sudo systemctl reload nginx</code> and restart PHP-FPM.
                            See <code>deploy/nginx-large-uploads.conf</code>.
                        </div>

                        <form action="{{ route('settings.import-database.store') }}" method="POST" enctype="multipart/form-data" id="importDatabaseForm">
                            @csrf

                            <div class="mb-3">
                                <label for="database_file" class="form-label">Database file <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" id="database_file" name="database_file"
                                       accept=".sql,.zip,.gz,application/sql,application/zip,application/gzip" required>
                                <div class="form-text">Max 1024 MB</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label d-block">Before import <span class="text-danger">*</span></label>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="mode" id="mode_backup" value="backup" checked>
                                    <label class="form-check-label" for="mode_backup">
                                        <strong>Backup existing database</strong>, then import the new file
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="mode" id="mode_replace" value="replace">
                                    <label class="form-check-label" for="mode_replace">
                                        <strong>Delete / replace without backup</strong> — import only (no safety dump)
                                    </label>
                                </div>
                            </div>

                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="confirm" id="confirm" value="1" required>
                                <label class="form-check-label" for="confirm">
                                    I understand this will overwrite the live database and I have verified the SQL file.
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary" id="importSubmitBtn"
                                @if($activeImport) disabled @endif>
                                <i class="bx bx-import me-1"></i> Start import
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Active / latest status</h5>
                        @if($activeImport)
                            <div id="activeImportBox"
                                 data-status-url="{{ route('settings.import-database.status', $activeImport->id) }}"
                                 data-import-id="{{ $activeImport->id }}"
                                 data-created-at="{{ $activeImport->created_at?->toIso8601String() }}">
                                <p class="mb-1"><strong>File:</strong> {{ $activeImport->original_filename }}</p>
                                <p class="mb-1"><strong>Mode:</strong> {{ $activeImport->mode }}</p>
                                <p class="mb-1">
                                    <strong>Status:</strong>
                                    <span class="badge bg-info" id="activeImportStatus">{{ $activeImport->status }}</span>
                                </p>
                                <p class="mb-2 text-muted" id="activeImportMessage">{{ $activeImport->message }}</p>

                                @if(in_array($activeImport->status, ['queued', 'failed'], true))
                                    <div class="alert alert-warning py-2 small mb-2" id="queuedWorkerHint">
                                        Still queued means the background process did not start (or failed).
                                        Click <strong>Process now</strong>, or on the server run:
                                        <code>php artisan database-import:process {{ $activeImport->id }}</code>
                                    </div>
                                    <form action="{{ route('settings.import-database.process', $activeImport->id) }}" method="POST" class="d-inline" id="processNowForm">
                                        @csrf
                                        <button type="submit" class="btn btn-warning btn-sm" id="processNowBtn">
                                            <i class="bx bx-play me-1"></i> Process now
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <p class="text-muted mb-0">No import is currently queued or running.</p>
                        @endif

                        <hr>
                        <p class="small text-muted mb-0">
                            Server PHP/nginx limits must allow uploads up to 1024&nbsp;MB
                            (<code>upload_max_filesize</code>, <code>post_max_size</code>,
                            <code>client_max_body_size</code>).
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-12 mt-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Recent imports</h5>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>File</th>
                                        <th>Size</th>
                                        <th>Mode</th>
                                        <th>Status</th>
                                        <th>Message</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($imports as $import)
                                        <tr>
                                            <td>{{ $import->id }}</td>
                                            <td>{{ $import->original_filename }}</td>
                                            <td>{{ $import->formatted_size }}</td>
                                            <td>
                                                @if($import->mode === 'backup')
                                                    <span class="badge bg-success">backup first</span>
                                                @else
                                                    <span class="badge bg-danger">replace</span>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    $badge = match($import->status) {
                                                        'completed' => 'success',
                                                        'failed' => 'danger',
                                                        'processing' => 'primary',
                                                        default => 'secondary',
                                                    };
                                                @endphp
                                                <span class="badge bg-{{ $badge }}">{{ $import->status }}</span>
                                            </td>
                                            <td class="small">{{ \Illuminate\Support\Str::limit($import->message, 80) }}</td>
                                            <td>{{ $import->created_at?->format('Y-m-d H:i') }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">No imports yet.</td>
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
</div>

{{-- Full-page loading overlay --}}
<div id="importLoadingOverlay" class="import-loading-overlay @if($activeImport && $activeImport->status === 'processing') is-visible @endif" aria-live="polite" aria-busy="true">
    <div class="import-loading-card">
        <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
            <span class="visually-hidden">Loading…</span>
        </div>
        <h5 class="mb-2" id="importLoadingTitle">
            @if($activeImport && $activeImport->status === 'processing')
                Import in progress…
            @else
                Uploading database…
            @endif
        </h5>
        <p class="text-muted mb-0 small" id="importLoadingMessage">
            @if($activeImport && $activeImport->status === 'processing')
                {{ $activeImport->message ?: 'Please wait while the database is imported.' }}
            @else
                Please wait. Do not close or refresh this page.
            @endif
        </p>
        <p class="mt-3 mb-0">
            <span class="badge bg-info" id="importLoadingStatusBadge">{{ $activeImport->status ?? '' }}</span>
        </p>
    </div>
</div>
@endsection

@push('styles')
<style>
    .import-loading-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(15, 23, 42, 0.55);
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }
    .import-loading-overlay.is-visible {
        display: flex;
    }
    .import-loading-card {
        background: #fff;
        border-radius: 0.75rem;
        padding: 2rem 2.25rem;
        max-width: 420px;
        width: 100%;
        text-align: center;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const overlay = document.getElementById('importLoadingOverlay');
    const titleEl = document.getElementById('importLoadingTitle');
    const messageElOverlay = document.getElementById('importLoadingMessage');
    const badgeEl = document.getElementById('importLoadingStatusBadge');

    function showLoading(title, message) {
        if (!overlay) return;
        if (titleEl && title) titleEl.textContent = title;
        if (messageElOverlay && message) messageElOverlay.textContent = message;
        overlay.classList.add('is-visible');
    }

    function hideLoading() {
        if (overlay) overlay.classList.remove('is-visible');
    }

    const form = document.getElementById('importDatabaseForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const mode = (document.querySelector('input[name="mode"]:checked') || {}).value;
            const isReplace = mode === 'replace';
            const message = isReplace
                ? 'Replace WITHOUT a safety backup? This cannot be undone from this screen.'
                : 'Backup the current database, then import the uploaded file?';
            if (!window.confirm(message)) {
                e.preventDefault();
                return;
            }
            const btn = document.getElementById('importSubmitBtn');
            if (btn) {
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Uploading…';
            }
            showLoading(
                'Uploading database…',
                'Large files may take several minutes. Please wait and do not close this page.'
            );
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Uploading…',
                    html: 'Uploading your SQL file and queuing the import job.<br><small class="text-muted">Please wait — do not refresh.</small>',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });
            }
        });
    }

    const box = document.getElementById('activeImportBox');
    if (!box) return;

    const url = box.dataset.statusUrl;
    const statusEl = document.getElementById('activeImportStatus');
    const messageEl = document.getElementById('activeImportMessage');
    const processForm = document.getElementById('processNowForm');

    if (processForm) {
        processForm.addEventListener('submit', function () {
            showLoading('Starting import…', 'Starting the background import process. Please wait.');
        });
    }

    async function poll() {
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (statusEl) statusEl.textContent = data.status || '';
            if (messageEl) messageEl.textContent = data.message || '';
            if (badgeEl) badgeEl.textContent = data.status || '';

            if (data.status === 'processing') {
                showLoading('Import in progress…', data.message || 'Please wait while the database is imported.');
            } else if (data.status === 'queued') {
                hideLoading();
            }

            if (data.status === 'completed' || data.status === 'failed') {
                hideLoading();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: data.status === 'completed' ? 'success' : 'error',
                        title: data.status === 'completed' ? 'Import completed' : 'Import failed',
                        text: data.message || '',
                        timer: 2000,
                        showConfirmButton: false,
                    }).then(function () {
                        window.location.reload();
                    });
                } else {
                    setTimeout(function () { window.location.reload(); }, 1500);
                }
                return;
            }
        } catch (e) {}
        setTimeout(poll, 3000);
    }
    poll();
})();
</script>
@endpush
