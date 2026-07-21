@extends('layouts.main')

@section('title', 'Bulk Cash Deposits')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Deposit Accounts', 'url' => route('cash_collaterals.index'), 'icon' => 'bx bx-credit-card'],
            ['label' => 'Bulk Deposit', 'url' => '#', 'icon' => 'bx bx-upload']
        ]" />

        <h6 class="mb-0 text-uppercase">BULK CASH DEPOSITS</h6>
        <hr />

        <div class="row">
            <div class="col-lg-8">
                <div class="card radius-10">
                    <div class="card-body">
                        <h5 class="card-title mb-3">Upload customer deposit amounts</h5>
                        <p class="text-muted">
                            Download the template with all deposit accounts in your branch ({{ number_format($customerCount) }} accounts),
                            fill in the <strong>amount</strong> column, then upload. Only bank account and deposit date are required besides the file.
                        </p>

                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <a href="{{ route('cash_collaterals.rmw.deposit.sample') }}" class="btn btn-outline-primary">
                                <i class="bx bx-download me-1"></i> Download Template
                            </a>
                            @can('deposit cash collateral')
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bulkDepositModal">
                                <i class="bx bx-upload me-1"></i> Upload &amp; Process
                            </button>
                            @endcan
                            <a href="{{ route('cash_collaterals.index') }}" class="btn btn-secondary">
                                <i class="bx bx-arrow-back me-1"></i> Back to List
                            </a>
                        </div>

                        <div class="alert alert-info mb-0">
                            <strong>File columns:</strong> <code>customer_no</code>, <code>customer_name</code> (optional), <code>deposit_type</code> (optional), <code>amount</code>.
                            Rows with empty or zero amounts are skipped. Processing runs in the background with a progress bar.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@can('deposit cash collateral')
<div class="modal fade" id="bulkDepositModal" tabindex="-1" aria-labelledby="bulkDepositModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="bulkDepositForm" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkDepositModalLabel">Bulk Deposit Upload</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="bank_account_id" class="form-label">Bank Account <span class="text-danger">*</span></label>
                            <select name="bank_account_id" id="bank_account_id" class="form-select" required>
                                <option value="">-- Select Bank Account --</option>
                                @foreach($bankAccounts as $bankAccount)
                                    <option value="{{ $bankAccount->id }}">
                                        {{ $bankAccount->name }} - {{ $bankAccount->account_number }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="deposit_date" class="form-label">Deposit Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="deposit_date" name="deposit_date"
                                   value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-12">
                            <label for="upload_file" class="form-label">Excel / CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="upload_file" name="upload_file"
                                   accept=".xlsx,.xls,.csv,.txt" required>
                            <div class="form-text">Max 20MB. Use the downloaded template.</div>
                        </div>
                        <div class="col-12">
                            <label for="notes" class="form-label">Notes (optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"
                                      placeholder="Bulk cash deposit"></textarea>
                        </div>
                    </div>
                    <div id="bulkDepositFormError" class="alert alert-danger mt-3 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="bulkDepositSubmitBtn">
                        <i class="bx bx-play-circle me-1"></i> Start Bulk Deposit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
@endsection

@push('scripts')
@can('deposit cash collateral')
<script>
(function () {
    const form = document.getElementById('bulkDepositForm');
    const submitBtn = document.getElementById('bulkDepositSubmitBtn');
    const uploadModalEl = document.getElementById('bulkDepositModal');
    const uploadModal = uploadModalEl ? bootstrap.Modal.getOrCreateInstance(uploadModalEl) : null;
    const progressUrl = '{{ route('cash_collaterals.rmw.deposit.progress') }}';
    const storeUrl = '{{ route('cash_collaterals.rmw.deposit.store') }}';
    let progressModal = null;
    let pollTimer = null;

    function showProgressModal() {
        const html = `
            <div class="modal fade" id="bulkDepositProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Processing Bulk Deposits...</h5>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Progress</span>
                                    <span id="bulkDepositProgressText">0%</span>
                                </div>
                                <div class="progress" style="height: 28px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                                         role="progressbar" id="bulkDepositProgressBar" style="width: 0%">0%</div>
                                </div>
                            </div>
                            <div class="text-center">
                                <p class="mb-1"><strong>Processed:</strong> <span id="bulkDepositCurrent">0</span> / <span id="bulkDepositTotal">0</span></p>
                                <p class="mb-1 text-success"><strong>Successful:</strong> <span id="bulkDepositSuccess">0</span></p>
                                <p class="mb-0 text-danger"><strong>Failed:</strong> <span id="bulkDepositFailed">0</span></p>
                            </div>
                            <div id="bulkDepositErrorsBox" class="mt-3 d-none">
                                <h6 class="text-danger">Errors</h6>
                                <ul id="bulkDepositErrorsList" class="small mb-0"></ul>
                            </div>
                        </div>
                        <div class="modal-footer d-none" id="bulkDepositDoneFooter">
                            <button type="button" class="btn btn-primary" id="bulkDepositDoneBtn">Done</button>
                        </div>
                    </div>
                </div>
            </div>`;
        const existing = document.getElementById('bulkDepositProgressModal');
        if (existing) existing.remove();
        document.body.insertAdjacentHTML('beforeend', html);
        progressModal = new bootstrap.Modal(document.getElementById('bulkDepositProgressModal'));
        document.getElementById('bulkDepositDoneBtn').addEventListener('click', function () {
            progressModal.hide();
            window.location.reload();
        });
        return progressModal;
    }

    function updateProgress(progress) {
        const pct = progress.percentage || 0;
        const bar = document.getElementById('bulkDepositProgressBar');
        const text = document.getElementById('bulkDepositProgressText');
        if (bar) {
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
        }
        if (text) text.textContent = pct + '%';

        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        set('bulkDepositCurrent', progress.current || 0);
        set('bulkDepositTotal', progress.total || 0);
        set('bulkDepositSuccess', progress.success || 0);
        set('bulkDepositFailed', progress.failed || 0);

        if (Array.isArray(progress.errors) && progress.errors.length > 0) {
            const box = document.getElementById('bulkDepositErrorsBox');
            const list = document.getElementById('bulkDepositErrorsList');
            if (box && list) {
                box.classList.remove('d-none');
                list.innerHTML = progress.errors.slice(0, 15).map(function (e) {
                    const row = e.row ? 'Row ' + e.row + ': ' : '';
                    const cust = e.customer_no ? '(' + e.customer_no + ') ' : '';
                    return '<li>' + row + cust + (e.message || '') + '</li>';
                }).join('');
            }
        }
    }

    function pollProgress(importId) {
        fetch(progressUrl + '?import_id=' + encodeURIComponent(importId), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json())
            .then(function (progress) {
                if (progress.status === 'not_found') return;
                updateProgress(progress);
                if (progress.status === 'completed' || progress.status === 'error') {
                    clearInterval(pollTimer);
                    pollTimer = null;
                    const bar = document.getElementById('bulkDepositProgressBar');
                    if (bar) bar.classList.remove('progress-bar-animated');
                    document.getElementById('bulkDepositDoneFooter')?.classList.remove('d-none');
                    if (progress.status === 'error') {
                        const box = document.getElementById('bulkDepositErrorsBox');
                        if (box) box.classList.remove('d-none');
                    }
                }
            })
            .catch(function () {});
    }

    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const errorBox = document.getElementById('bulkDepositFormError');
        errorBox.classList.add('d-none');
        errorBox.textContent = '';

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Starting...';

        const formData = new FormData(form);

        fetch(storeUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || formData.get('_token')
            }
        })
            .then(async function (response) {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Upload failed.');
                }
                return data;
            })
            .then(function (data) {
                uploadModal?.hide();
                form.reset();
                document.getElementById('deposit_date').value = '{{ date('Y-m-d') }}';

                const modal = showProgressModal();
                updateProgress({ percentage: 0, current: 0, total: data.total || 0, success: 0, failed: data.skipped || 0 });
                modal.show();

                pollProgress(data.import_id);
                pollTimer = setInterval(function () { pollProgress(data.import_id); }, 1500);
            })
            .catch(function (err) {
                errorBox.textContent = err.message || 'Could not start bulk deposit.';
                errorBox.classList.remove('d-none');
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bx bx-play-circle me-1"></i> Start Bulk Deposit';
            });
    });
})();
</script>
@endcan
@endpush
