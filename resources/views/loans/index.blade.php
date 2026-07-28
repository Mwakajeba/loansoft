@extends('layouts.main')

@section('title', 'Loans')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => '#', 'icon' => 'bx bx-credit-card'],
        ]" />

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 text-uppercase">LOAN MANAGEMENT</h6>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#openingBalanceModal">
                    <i class="bx bx-upload me-1"></i> Opening Balance
                </button>
            </div>
            <hr />

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="row">
                                <!-- Loan Calculator -->
                                <div class="col-md-6 col-lg-4 mb-4">
                                    <div class="card border-info position-relative">
                                        <div class="card-body text-center">
                                            <div class="mb-3">
                                                <i class="bx bx-calculator fs-1 text-info"></i>
                                            </div>
                                            <h5 class="card-title">Loan Calculator</h5>
                                            <p class="card-text">Simulate loan scenarios, view schedules and export results.</p>
                                            <a href="{{ route('loan-calculator.index') }}" class="btn btn-info position-relative">
                                                <i class="bx bx-calculator me-1"></i> Open Calculator
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                @can('view loans')
                                    <!-- Active Loans -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-primary position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary">{{ $stats['active'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-building fs-1 text-primary"></i>
                                                </div>
                                                <h5 class="card-title">Active Loans</h5>
                                                <p class="card-text">Manage your company loans disbursed to customers.</p>
                                                <a href="{{ route('loans.list') }}" class="btn btn-primary position-relative">
                                                    <i class="bx bx-cog me-1"></i> View Loans
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view applied loans')
                                    <!-- Applied Loans -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-success position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success">{{ $stats['applied'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-plus-circle fs-1 text-success"></i>
                                                </div>
                                                <h5 class="card-title">Applied Loans</h5>
                                                <p class="card-text">Manage and initiate loan applications.</p>
                                                <a href="{{ route('loans.by-status', 'applied') }}"
                                                    class="btn btn-success position-relative">
                                                    <i class="bx bx-file-plus me-1"></i> View Applications
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan

                                @can('view checked loans')
                                    <!-- Checked Applications -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-teal position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-secondary">{{ $stats['checked'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-check-circle fs-1 text-secondary"></i>
                                                </div>
                                                <h5 class="card-title">Checked Applications</h5>
                                                <p class="card-text">Manage and check applied loans.</p>
                                                <a href="{{ route('loans.by-status', 'checked') }}"
                                                    class="btn btn-secondary position-relative">
                                                    <i class="bx bx-check me-1"></i> View Applications
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view approved loans')
                                    <!-- Approved Applications -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-purple position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-info">{{ $stats['approved'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-check-circle fs-1 text-info"></i>
                                                </div>
                                                <h5 class="card-title">Approved Applications</h5>
                                                <p class="card-text">Manage and verify applied loans.</p>
                                                <a href="{{ route('loans.by-status', 'approved') }}"
                                                    class="btn btn-info position-relative">
                                                    <i class="bx bx-verify me-1"></i> View Applications
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view authorized loans')
                                    <!-- Authorized Applications -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-orange position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark">{{ $stats['authorized'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-badge-check fs-1 text-warning"></i>
                                                </div>
                                                <h5 class="card-title">Authorized Applications</h5>
                                                <p class="card-text">Manage and approve applied loans.</p>
                                                <a href="{{ route('loans.by-status', 'authorized') }}"
                                                    class="btn btn-warning position-relative">
                                                    <i class="bx bx-badge-check me-1"></i> View Applications
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view defaulted loans')
                                    <!-- Defaulted Loans -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-danger position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $stats['defaulted'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-error fs-1 text-danger"></i>
                                                </div>
                                                <h5 class="card-title">Defaulted Loans</h5>
                                                <p class="card-text">Active loans with arrears greater than 90 days.</p>
                                                <a href="{{ route('loans.by-status', 'defaulted') }}"
                                                    class="btn btn-danger position-relative">
                                                    <i class="bx bx-error me-1"></i> View Loans
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view rejected loans')
                                    <!-- Rejected Applications -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-danger position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $stats['rejected'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-x-circle fs-1 text-danger"></i>
                                                </div>
                                                <h5 class="card-title">Rejected Applications</h5>
                                                <p class="card-text">Manage all rejected loan applications.</p>
                                                <a href="{{ route('loans.by-status', 'rejected') }}"
                                                    class="btn btn-danger position-relative">
                                                    <i class="bx bx-x-circle me-1"></i> View Applications
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view loans')
                                    <!-- Written Off Loans -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-danger position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $stats['written_off'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-x-circle fs-1 text-danger"></i>
                                                </div>
                                                <h5 class="card-title">Written Off Loans</h5>
                                                <p class="card-text">Manage all written off loans.</p>
                                                <a href="{{ route('loans.writtenoff') }}" class="btn btn-danger">
                                                    <i class="bx bx-x-circle me-1"></i> View Loans
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view completed loans')
                                    <!-- Completed Loans -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-success position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success">{{ $stats['completed'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-check-circle fs-1 text-success"></i>
                                                </div>
                                                <h5 class="card-title">Completed Loans</h5>
                                                <p class="card-text">Manage all completed loans.</p>
                                                <a href="{{ route('loans.by-status', 'completed') }}" class="btn btn-success">
                                                    <i class="bx bx-check-circle me-1"></i> View Loans
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                                @can('view loans')
                                    <!-- Restructured Loans -->
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card border-info position-relative">
                                            <span
                                                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-info">{{ $stats['restructured'] ?? 0 }}</span>
                                            <div class="card-body text-center">
                                                <div class="mb-3">
                                                    <i class="bx bx-refresh fs-1 text-info"></i>
                                                </div>
                                                <h5 class="card-title">Restructured Loans</h5>
                                                <p class="card-text">Manage all restructured loans.</p>
                                                <a href="{{ route('loans.by-status', 'restructured') }}" class="btn btn-info position-relative">
                                                    <i class="bx bx-refresh me-1"></i> View Loans
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Opening Balance Modal -->
    <div class="modal fade" id="openingBalanceModal" tabindex="-1" aria-labelledby="openingBalanceModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="openingBalanceModalLabel">Opening Balance - Bulk Loan Creation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="openingBalanceForm" action="{{ route('loans.opening-balance.store') }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body">
                        <div class="row">
                            <!-- Download Template Button -->
                            <div class="col-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">Step 1: Download Template</h6>
                                    <button type="button" id="downloadTemplateBtn" class="btn btn-outline-primary btn-sm">
                                        <i class="bx bx-download me-1"></i> Download Template
                                    </button>
                                </div>
                                <small class="text-muted">Download the Excel template (dropdowns for interest cycle &amp; sector) and fill in your loan data</small>
                            </div>

                            <!-- Product Selection -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Loan Product <span class="text-danger">*</span></label>
                                <select name="product_id" class="form-select @error('product_id') is-invalid @enderror"
                                    required>
                                    <option value="">Select Product</option>
                                    @foreach($products ?? [] as $product)
                                        <option value="{{ $product->id ?? '' }}" {{ old('product_id') == ($product->id ?? '') ? 'selected' : '' }}>
                                            {{ $product->name ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Branch Selection -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select name="branch_id" class="form-select @error('branch_id') is-invalid @enderror"
                                    required>
                                    <option value="">Select Branch</option>
                                    @foreach($branches ?? [] as $branch)
                                        <option value="{{ $branch->id ?? '' }}" {{ old('branch_id') == ($branch->id ?? '') ? 'selected' : '' }}>
                                            {{ $branch->name ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- Chart Account Selection -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Chart Account <span class="text-danger">*</span></label>
                                <select name="chart_account_id"
                                    class="form-select @error('chart_account_id') is-invalid @enderror select2-single" required>
                                    <option value="">Select Chart Account</option>
                                    @foreach($chartAccounts ?? [] as $account)
                                        <option value="{{ $account->id ?? '' }}" {{ old('chart_account_id') == ($account->id ?? '') ? 'selected' : '' }}>
                                            {{ $account->account_name ?? '' }} ({{ $account->account_code ?? '' }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('chart_account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <!-- File Upload -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Excel / CSV file <span class="text-danger">*</span></label>
                                <input type="file" name="csv_file"
                                    class="form-control @error('csv_file') is-invalid @enderror" accept=".csv,.xlsx,.xls" required>
                                @error('csv_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <small class="text-muted">Upload the filled template (.xlsx recommended for dropdowns)</small>
                            </div>

                            <!-- Deduct release fees -->
                            <div class="col-12 mb-3">
                                <div class="form-check">
                                    <input type="hidden" name="deduct_fees_on_release" value="0">
                                    <input class="form-check-input" type="checkbox" value="1"
                                        id="deductFeesOnRelease" name="deduct_fees_on_release"
                                        {{ old('deduct_fees_on_release', '0') == '1' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="deductFeesOnRelease">
                                        Deduct all release-date fees from cash on loan release
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    When checked: fixed/% fees use product fee settings if Excel is <strong>0</strong>;
                                    <strong>custom</strong> fees must be filled in the Excel <code>fee_*</code> columns.
                                    See the template sheet <em>Release Fees Guide</em>.
                                </small>
                            </div>
                        </div>

                        <!-- Instructions -->
                        <div class="alert alert-info">
                            <h6 class="alert-heading">Instructions:</h6>
                            <ul class="mb-0">
                                <li>Select a loan product first, then download the Excel template</li>
                                <li>Use the <strong>interest_cycle</strong> dropdown (column J) — same options as Create Loan (daily, weekly, monthly, etc.)</li>
                                <li>Optional <strong>first_repayment_date</strong>: leave blank to use the default schedule from the interest cycle</li>
                                <li>Fee columns (<code>fee_&lt;id&gt;</code>): for <strong>custom</strong> fees enter the amount; for <strong>fixed</strong> or <strong>percentage</strong> leave 0 to calculate from fee settings (or enter an override amount)</li>
                                <li>Tick <strong>Deduct all release-date fees</strong> only if cash disbursed should be reduced by those fees</li>
                                <li>Delete template rows you do not need; only rows with amount, interest, and period are imported</li>
                                <li>Ensure customer numbers exist in the system</li>
                                <li>Loans will be created with 'active' status</li>
                                <li>Repayments will be processed automatically if amount_paid > 0</li>
                                <li>Upload starts processing automatically; progress is shown while loans are created in chunks of 50</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="openingBalanceSubmitBtn" class="btn btn-warning">
                            <i class="bx bx-upload me-1"></i> Process Opening Balance
                        </button>
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

@push('styles')
    <style>
        .border-purple {
            border-color: #6f42c1 !important;
        }

        .text-purple {
            color: #6f42c1 !important;
        }

        .btn-purple {
            background-color: #6f42c1;
            border-color: #6f42c1;
            color: white;
        }

        .btn-purple:hover {
            background-color: #5a32a3;
            border-color: #5a32a3;
            color: white;
        }

        .border-orange {
            border-color: #fd7e14 !important;
        }

        .text-orange {
            color: #fd7e14 !important;
        }

        .btn-orange {
            background-color: #fd7e14;
            border-color: #fd7e14;
            color: white;
        }

        .btn-orange:hover {
            background-color: #e8690b;
            border-color: #e8690b;
            color: white;
        }

        .border-teal {
            border-color: #20c997 !important;
        }

        .text-teal {
            color: #20c997 !important;
        }

        .btn-teal {
            background-color: #20c997;
            border-color: #20c997;
            color: white;
        }

        .btn-teal:hover {
            background-color: #1ba37e;
            border-color: #1ba37e;
            color: white;
        }

        .border-danger {
            border-color: #dc3545 !important;
        }

        .text-danger {
            color: #dc3545 !important;
        }

        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #bb2d3b;
            border-color: #bb2d3b;
            color: white;
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const downloadTemplateBtn = document.getElementById('downloadTemplateBtn');
            const productSelect = document.querySelector('#openingBalanceForm select[name="product_id"]');
            const openingBalanceForm = document.getElementById('openingBalanceForm');
            const submitBtn = document.getElementById('openingBalanceSubmitBtn');
            const progressUrl = '{{ route("loans.import-progress") }}';

            downloadTemplateBtn.addEventListener('click', function () {
                const productId = productSelect.value;

                if (!productId) {
                    alert('Please select a loan product first before downloading the template.');
                    productSelect.focus();
                    return;
                }

                const downloadUrl = '{{ route("loans.opening-balance.template") }}?product_id=' + productId;
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = 'opening_balance_template_{{ date("Y-m-d") }}.xlsx';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });

            function showOpeningBalanceProgressModal() {
                const html = `
                    <div class="modal fade" id="obProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Processing Opening Balance...</h5>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Progress</span>
                                            <span id="obProgressText">0%</span>
                                        </div>
                                        <div class="progress" style="height: 25px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
                                                 role="progressbar" id="obProgressBar" style="width: 0%">0%</div>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <p class="mb-1"><strong>Processed:</strong> <span id="obCurrentRow">0</span> / <span id="obTotalRows">0</span></p>
                                        <p class="mb-1 text-success"><strong>Created:</strong> <span id="obSuccessCount">0</span></p>
                                        <p class="mb-0 text-danger"><strong>Failed:</strong> <span id="obFailedCount">0</span></p>
                                    </div>
                                    <div id="obErrorsBox" class="mt-3 d-none">
                                        <h6 class="text-danger">Sample errors</h6>
                                        <ul id="obErrorsList" class="small mb-0"></ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
                const existing = document.getElementById('obProgressModal');
                if (existing) existing.remove();
                document.body.insertAdjacentHTML('beforeend', html);
                return new bootstrap.Modal(document.getElementById('obProgressModal'));
            }

            function updateObProgress(progress) {
                const pct = progress.percentage || 0;
                const bar = document.getElementById('obProgressBar');
                const text = document.getElementById('obProgressText');
                if (bar) {
                    bar.style.width = pct + '%';
                    bar.textContent = pct + '%';
                }
                if (text) text.textContent = pct + '%';
                const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
                set('obCurrentRow', progress.current || 0);
                set('obTotalRows', progress.total || 0);
                set('obSuccessCount', progress.success || 0);
                set('obFailedCount', progress.failed || 0);

                if (Array.isArray(progress.errors) && progress.errors.length > 0) {
                    const box = document.getElementById('obErrorsBox');
                    const list = document.getElementById('obErrorsList');
                    if (box && list) {
                        box.classList.remove('d-none');
                        list.innerHTML = progress.errors.slice(0, 10).map(function (e) {
                            return '<li>Row ' + (e.row || '?') + ' (' + (e.customer_no || '') + '): ' + (e.message || '') + '</li>';
                        }).join('');
                    }
                }
            }

            function pollOpeningBalanceProgress(importId, onComplete) {
                fetch(progressUrl + '?import_id=' + encodeURIComponent(importId), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(function (progress) {
                        if (progress.status === 'not_found') return;
                        updateObProgress(progress);
                        if (progress.status === 'completed' || progress.status === 'error') {
                            onComplete(progress);
                        }
                    })
                    .catch(function () {});
            }

            function finishOpeningBalance(progress) {
                const modalEl = document.getElementById('obProgressModal');
                if (modalEl) {
                    const inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                const success = progress.success || 0;
                const failed = progress.failed || 0;
                let msg = 'Opening balance complete. Created: ' + success + ' loan(s).';
                if (failed > 0) msg += ' Failed: ' + failed + '.';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: failed > 0 ? 'Completed with errors' : 'Success',
                        text: msg,
                        icon: failed > 0 ? 'warning' : 'success'
                    }).then(function () { window.location.reload(); });
                } else {
                    alert(msg);
                    window.location.reload();
                }
            }

            if (openingBalanceForm) {
                openingBalanceForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const productId = openingBalanceForm.querySelector('[name="product_id"]').value;
                    const branchId = openingBalanceForm.querySelector('[name="branch_id"]').value;
                    const chartAccountId = openingBalanceForm.querySelector('[name="chart_account_id"]').value;
                    const fileInput = openingBalanceForm.querySelector('[name="csv_file"]');

                    if (!productId || !branchId || !chartAccountId || !fileInput.files.length) {
                        alert('Please fill in all required fields and select a file.');
                        return;
                    }

                    const formData = new FormData(openingBalanceForm);
                    const originalHtml = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin me-1"></i> Uploading...';

                    const progressModal = showOpeningBalanceProgressModal();
                    progressModal.show();

                    fetch(openingBalanceForm.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    })
                        .then(function (response) {
                            return response.json().then(function (data) {
                                return { ok: response.ok, data: data };
                            });
                        })
                        .then(function (result) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalHtml;

                            if (!result.ok || !result.data.success) {
                                progressModal.hide();
                                const msg = result.data.message || result.data.error || 'Upload failed.';
                                if (typeof Swal !== 'undefined') {
                                    Swal.fire({ title: 'Error', text: msg, icon: 'error' });
                                } else {
                                    alert(msg);
                                }
                                return;
                            }

                            const importId = result.data.import_id;
                            if (result.data.total) {
                                document.getElementById('obTotalRows').textContent = result.data.total;
                            }

                            if (result.data.status === 'completed') {
                                pollOpeningBalanceProgress(importId, function (progress) {
                                    finishOpeningBalance(progress);
                                });
                                return;
                            }

                            let interval = setInterval(function () {
                                pollOpeningBalanceProgress(importId, function (progress) {
                                    clearInterval(interval);
                                    finishOpeningBalance(progress);
                                });
                            }, 800);
                        })
                        .catch(function () {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalHtml;
                            progressModal.hide();
                            alert('Upload failed. Please try again.');
                        });
                });
            }
        });
    </script>
@endpush