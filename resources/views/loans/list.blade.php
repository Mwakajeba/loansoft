@php
    use Vinkla\Hashids\Facades\Hashids;
@endphp

@extends('layouts.main')

@section('title', $pageTitle ?? 'Loan Management')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => route('loans.index'), 'icon' => 'bx bx-credit-card'],
            ['label' => $pageTitle ?? 'Loan List', 'url' => '#', 'icon' => 'bx bx-list']
        ]" />
            <h6 class="mb-0 text-uppercase">{{ $pageTitle ?? 'LOAN LIST' }}</h6>
            <hr />

            <!-- Flash Messages -->
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bx bx-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('warning'))
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i>{{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    @if(session('import_errors'))
                        <details class="mt-2">
                            <summary class="text-decoration-underline" style="cursor: pointer;">View Error Details</summary>
                            <ul class="mt-2 mb-0">
                                @foreach(session('import_errors') as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bx bx-error-circle me-2"></i>
                    <strong>Error:</strong>
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            <!-- Dashboard Stats -->
            <div class="row row-cols-1 row-cols-lg-4">
                <div class="col mb-4">
                    <div class="card radius-10">
                        <div class="card-body d-flex align-items-center">
                            <div class="flex-grow-1">
                                <p class="text-muted mb-1">{{ $pageTitle ?? 'Total Loans' }}</p>
                                <h4 class="mb-0" id="totalLoansCount">Loading...</h4>
                            </div>
                            <div class="widgets-icons bg-gradient-burning text-white"><i class='bx bx-money'></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loans Table -->
            <div class="row">
                <div class="col-12">
                    <div class="card radius-10">
                        <div class="card-body">
                            @can('create loan')
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h6 class="card-title mb-0">{{ $pageTitle ?? 'Loans List' }}</h6>
                                    @if(!isset($status) || !in_array($status, ['checked', 'approved', 'authorized', 'rejected']))
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-outline-success" data-bs-toggle="modal"
                                                data-bs-target="#importModal">
                                                <i class="bx bx-import"></i> Import Loans
                                            </button>
                                            @if(isset($status) && $status === 'applied')
                                                <a href="{{ route('loans.application.create') }}" class="btn btn-primary">
                                                    <i class="bx bx-plus"></i> Create Loan Application
                                                </a>
                                            @else
                                                <a href="{{ route('loans.create') }}" class="btn btn-primary">
                                                    <i class="bx bx-plus"></i> Create Direct Loan
                                                </a>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endcan

                            <div class="table-responsive">
                                <table class="table table-bordered dt-responsive nowrap table-striped" id="loansTable">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Product</th>
                                            <th>Amount</th>
                                            <th>Interest Rate</th>
                                            <th>Total Amount</th>
                                            <th>Period</th>
                                            <th>Status</th>
                                            <th>Branch</th>
                                            <th>Date Applied</th>
                                            <th>Comment</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Approval Modal -->
            <div class="modal fade" id="approvalModal" tabindex="-1" aria-labelledby="approvalModalLabel"
                aria-hidden="true">
                <div class="modal-dialog">
                    <form id="approvalForm" method="POST" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="approvalModalLabel">Confirm Action</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p id="approvalMessage"></p>
                            <div class="mb-3" id="disburse_date_wrapper" style="display:none;">
                                <label for="approval_disbursement_date" class="form-label">Disbursement Date <span
                                        class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="disbursement_date"
                                    id="approval_disbursement_date" max="{{ date('Y-m-d') }}" value="{{ date('Y-m-d') }}">
                                <div class="form-text">Select the date when the loan will be disbursed.</div>
                            </div>
                            <div class="mb-3" id="disburse_bank_wrapper" style="display:none;">
                                <label for="approval_bank_account_id" class="form-label">Select Bank Account <span
                                        class="text-danger">*</span></label>
                                <select class="form-select" name="bank_account_id" id="approval_bank_account_id">
                                    <option value="">-- Select Bank Account --</option>
                                    @foreach($bankAccounts ?? [] as $bankAccount)
                                        <option value="{{ $bankAccount->id }}">{{ $bankAccount->account_number }} -
                                            {{ $bankAccount->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">This bank account will be used for the disbursement entry.</div>
                            </div>
                            <div class="mb-3">
                                <label for="approval_comments" class="form-label">Comments (Optional)</label>
                                <textarea class="form-control" name="comments" id="approval_comments" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Confirm</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Import Modal -->
            <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="importModalLabel">Import Loans</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="{{ route('loans.import') }}" method="POST" enctype="multipart/form-data"
                            id="importForm">
                            @csrf
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="bx bx-info-circle me-2"></i>
                                            <strong>Import Instructions:</strong>
                                            <ul class="mb-0 mt-2">
                                                <li>Upload an Excel file (.xlsx, .xls) or CSV file with loan data</li>
                                                <li>Select loan type to determine chart account source</li>
                                                <li>Configure default settings for the import</li>
                                                <li>Maximum file size: 10MB</li>
                                                <li>Required columns: customer_no, amount, period, interest,
                                                    date_applied, interest_cycle, loan_officer, group_id, sector</li>
                                                <li><strong>Customer Number:</strong> Use the customer number (not ID).
                                                    Invalid customer numbers will be skipped.</li>
                                                <li>Use dropdowns in Excel template for Interest Cycle and Sector</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="loan_type" class="form-label">Loan Type <span
                                                class="text-danger">*</span></label>
                                        <select class="form-select" id="loan_type" name="loan_type" required>
                                            <option value="">Select Loan Type</option>
                                            <option value="old">Old Loans</option>
                                            <option value="new">New Loans</option>
                                        </select>
                                        <div class="form-text">Determines chart account type (Old = Equity, New = Bank)
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="import_file" class="form-label">Select Excel/CSV File <span
                                                class="text-danger">*</span></label>
                                        <input type="file" class="form-control" id="import_file" name="import_file"
                                            accept=".xlsx,.xls,.csv,.txt" required>
                                        <div class="form-text">Supported: Excel (.xlsx, .xls), CSV, TXT (Max: 10MB)</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="branch_id" class="form-label">Branch <span
                                                class="text-danger">*</span></label>
                                        <select class="form-select" id="branch_id" name="branch_id" required>
                                            <option value="">Select Branch</option>
                                            @if(isset($branches))
                                                @foreach($branches as $branch)
                                                    <option value="{{ $branch->id }}" {{ auth()->user()->branch_id == $branch->id ? 'selected' : '' }}>
                                                        {{ $branch->name }}
                                                    </option>
                                                @endforeach
                                            @endif
                                        </select>
                                        <div class="form-text">Branch for imported loans</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="product_id" class="form-label">Loan Product <span
                                                class="text-danger">*</span></label>
                                        <select class="form-select" id="product_id" name="product_id" required>
                                            <option value="">Select Loan Product</option>
                                            @if(isset($loanProducts))
                                                @foreach($loanProducts as $product)
                                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                                @endforeach
                                            @endif
                                        </select>
                                        <div class="form-text">Default product for loans</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="account_id" class="form-label">Bank Account <span
                                                class="text-danger">*</span></label>
                                        <select class="form-select" id="account_id" name="account_id" required disabled>
                                            <option value="">Select loan type first</option>
                                        </select>
                                        <div class="form-text" id="chart_account_help">Select loan type to see available
                                            bank accounts</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="skip_errors"
                                                name="skip_errors" checked>
                                            <label class="form-check-label" for="skip_errors">
                                                Skip rows with errors and continue import
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <div class="me-auto">
                                    <a href="{{ route('loans.import-template') }}" class="btn btn-outline-secondary btn-sm"
                                        id="downloadTemplate">
                                        <i class="bx bx-download"></i> Download Sample Template
                                    </a>
                                </div>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">
                                    <i class="bx bx-import"></i> Import Loans
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(document).ready(function () {
            // Show SweetAlert for success messages
            @if(session('success'))
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: '{{ session('success') }}',
                    timer: 3000,
                    showConfirmButton: false
                });
            @endif

            // Show SweetAlert for import warnings with detailed errors and logs
            @if(session('warning'))
                (function () {
                    function escapeHtml(str) {
                        return String(str)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/\"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }
                    const errors = @json(session('import_errors', []));
                    const logs = @json(session('import_logs', []));
                    const tips = @json(session('import_tips', []));
                    let html = '';

                    if (errors.length) {
                        html += '<div style="text-align:left; margin-bottom:10px;">'
                            + '<strong>Errors:</strong>'
                            + '<ul style="max-height:200px; overflow:auto; padding-left:18px; margin-top:6px;">';
                        errors.forEach(function (e) {
                            html += '<li>' + escapeHtml(e) + '</li>';
                        });
                        html += '</ul></div>';
                    }

                    if (logs.length) {
                        html += '<div style="text-align:left;">'
                            + '<strong>Logs:</strong>'
                            + '<pre style="white-space:pre-wrap; max-height:200px; overflow:auto; margin-top:6px;">';
                        logs.forEach(function (l) {
                            html += escapeHtml(l) + '\n';
                        });
                        html += '</pre></div>';
                    }

                    if (tips.length) {
                        html += '<div style="text-align:left; margin-top:10px;">'
                            + '<strong>How to fix:</strong>'
                            + '<ul style="max-height:200px; overflow:auto; padding-left:18px; margin-top:6px;">';
                        tips.forEach(function (t) {
                            html += '<li>' + escapeHtml(t) + '</li>';
                        });
                        html += '</ul></div>';
                    }

                    Swal.fire({
                        icon: 'warning',
                        title: 'Import Completed With Issues',
                        html: html || escapeHtml(`{{ session('warning') }}`),
                        width: 800,
                        showCloseButton: true,
                        confirmButtonText: 'OK'
                    });
                })();
            @endif

            // Show SweetAlert for error messages
            @if($errors->any())
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: '{{ $errors->first() }}',
                    timer: 5000,
                    showConfirmButton: true
                });
            @endif
            const currentStatus = '{{ $status ?? "active" }}';

            // Initialize DataTable with Ajax
            const table = $('#loansTable').DataTable({
                processing: true,
                serverSide: true,
                responsive: true,
                deferRender: true, // Only render visible rows
                stateSave: false, // Disable state saving for better performance
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rtip',
                ajax: {
                    url: '{{ route("loans.data") }}',
                    type: 'GET',
                    data: function (d) {
                        d.status = currentStatus;
                    },
                    error: function (xhr, error, code) {
                        console.log('Ajax error:', xhr.responseJSON);
                    }
                },
                columns: [
                    { data: 'customer_name', name: 'customer_name', orderable: true, searchable: true },
                    { data: 'product_name', name: 'product_name', orderable: true, searchable: true },
                    { data: 'formatted_amount', name: 'amount', orderable: true, searchable: true },
                    { data: 'interest_display', name: 'interest', orderable: true, searchable: true },
                    { data: 'formatted_total', name: 'amount_total', orderable: true, searchable: true },
                    { data: 'period', name: 'period', orderable: true, searchable: true },
                    { data: 'status_badge', name: 'status', orderable: false, searchable: true },
                    { data: 'branch_name', name: 'branch_name', orderable: true, searchable: true },
                    { data: 'formatted_date', name: 'date_applied', orderable: true, searchable: true },
                    { data: 'comment', name: 'comment', orderable: false, searchable: true },
                    { data: 'actions', name: 'actions', orderable: false, searchable: false, className: 'text-center' }
                ],
                order: [[8, 'desc']], // Order by date applied descending (column index 8)
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                // Performance optimizations
                scrollY: false, // Disable virtual scrolling for better performance
                scrollCollapse: false,
                pagingType: 'simple_numbers', // Simpler pagination for faster rendering
                language: {
                    search: "",
                    searchPlaceholder: "Search loans by customer, product, amount, status, etc...",
                    processing: '<div class="d-flex justify-content-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>',
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                columnDefs: [
                    {
                        targets: [0, 1, 2, 3, 4, 5, 6, 7, 8],
                        responsivePriority: 1,
                        searchable: true
                    },
                    {
                        targets: [-1],
                        responsivePriority: 1,
                        orderable: false,
                        searchable: false
                    }
                ],
                drawCallback: function (settings) {
                    // Update total count
                    $('#totalLoansCount').text(settings.json.recordsTotal || 0);

                    // Reinitialize delete buttons
                    $('.delete-btn').off('click').on('click', function () {
                        const loanId = $(this).data('id');
                        const loanName = $(this).data('name');
                        deleteLoan(loanId, loanName);
                    });

                    // Reinitialize approval buttons
                    $('.approve-btn').off('click').on('click', function () {
                        const loanId = $(this).data('id');
                        const action = $(this).data('action');
                        const level = $(this).data('level');
                        openApprovalModal(loanId, action, level);
                    });

                    // Add search enhancement
                    const searchInput = $('.dataTables_filter input');
                    if (searchInput.length) {
                        searchInput.attr('title', 'Search across all loan data including customer names, amounts, status, etc.');

                        // Add clear button functionality
                        if (searchInput.val()) {
                            if (!searchInput.next('.search-clear').length) {
                                searchInput.after('<button type="button" class="btn btn-sm btn-outline-secondary search-clear ms-2" title="Clear search"><i class="bx bx-x"></i></button>');
                            }
                        }
                    }

                    // Handle clear search button
                    $('.search-clear').off('click').on('click', function () {
                        searchInput.val('').trigger('keyup');
                        $(this).remove();
                    });
                }
            });

            // Add search input event handlers for better UX
            $(document).on('input', '.dataTables_filter input', function () {
                const searchInput = $(this);
                const clearBtn = searchInput.next('.search-clear');

                if (searchInput.val().length > 0) {
                    if (!clearBtn.length) {
                        searchInput.after('<button type="button" class="btn btn-sm btn-outline-secondary search-clear ms-2" title="Clear search"><i class="bx bx-x"></i></button>');
                    }
                } else {
                    clearBtn.remove();
                }
            });

            // Global error handler for Ajax requests
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });

            // Handle import form submission
            $('#importForm').on('submit', function (e) {
                e.preventDefault();

                const formData = new FormData(this);
                const submitBtn = $(this).find('button[type="submit"]');
                const originalText = submitBtn.html();

                // Validate required fields
                if (!$('#loan_type').val() || !$('#import_file').val() || !$('#branch_id').val() ||
                    !$('#product_id').val() || !$('#account_id').val()) {
                    Swal.fire({
                        title: 'Validation Error',
                        text: 'Please fill in all required fields before importing.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                    return;
                }

                // Disable submit button and show loading
                submitBtn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin"></i> Importing...');

                // Show progress modal
                const progressModal = `
                    <div class="modal fade" id="importProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Importing Loans...</h5>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Progress</span>
                                            <span id="progressText">0%</span>
                                        </div>
                                        <div class="progress" style="height: 25px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                                 role="progressbar" 
                                                 id="progressBar" 
                                                 style="width: 0%"
                                                 aria-valuenow="0" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                0%
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <p class="mb-1"><strong>Processing:</strong> <span id="currentRow">0</span> / <span id="totalRows">0</span> rows</p>
                                        <p class="mb-1 text-success"><strong>Success:</strong> <span id="successCount">0</span></p>
                                        <p class="mb-1 text-danger"><strong>Failed:</strong> <span id="failedCount">0</span></p>
                                        <p class="mb-0 text-warning"><strong>Skipped:</strong> <span id="skippedCount">0</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Remove existing modal if any
                $('#importProgressModal').remove();
                $('body').append(progressModal);
                const modal = new bootstrap.Modal(document.getElementById('importProgressModal'));
                modal.show();

                let importId = null;
                let progressInterval = null;

                // Submit form via Ajax
                $.ajax({
                    url: $(this).attr('action'),
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        // Clear progress interval
                        if (progressInterval) {
                            clearInterval(progressInterval);
                        }

                        // Get import ID and start polling for progress
                        if (typeof response === 'object' && response !== null && response.import_id) {
                            importId = response.import_id;
                            
                            // Check progress immediately
                            $.ajax({
                                url: '{{ route("loans.import-progress") }}',
                                method: 'GET',
                                data: { import_id: importId },
                                success: function(progress) {
                                    if (progress.status === 'completed' || progress.status === 'error') {
                                        $('#importProgressModal').modal('hide');
                                        handleImportComplete(response, progress);
                                    } else {
                                        // Update initial progress
                                        const percentage = progress.percentage || 0;
                                        $('#progressBar').css('width', percentage + '%').attr('aria-valuenow', percentage).text(percentage + '%');
                                        $('#progressText').text(percentage + '%');
                                        $('#currentRow').text(progress.current || 0);
                                        $('#totalRows').text(progress.total || 0);
                                        $('#successCount').text(progress.success || 0);
                                        $('#failedCount').text(progress.failed || 0);
                                        $('#skippedCount').text(progress.skipped || 0);
                                        
                                        // Start polling for progress updates
                                        progressInterval = setInterval(function() {
                                            $.ajax({
                                                url: '{{ route("loans.import-progress") }}',
                                                method: 'GET',
                                                data: { import_id: importId },
                                                success: function(progress) {
                                                    if (progress.status === 'completed' || progress.status === 'error') {
                                                        clearInterval(progressInterval);
                                                        $('#importProgressModal').modal('hide');
                                                        handleImportComplete(response, progress);
                                                    } else if (progress.status === 'processing') {
                                                        // Update progress bar
                                                        const percentage = progress.percentage || 0;
                                                        $('#progressBar').css('width', percentage + '%').attr('aria-valuenow', percentage).text(percentage + '%');
                                                        $('#progressText').text(percentage + '%');
                                                        $('#currentRow').text(progress.current || 0);
                                                        $('#totalRows').text(progress.total || 0);
                                                        $('#successCount').text(progress.success || 0);
                                                        $('#failedCount').text(progress.failed || 0);
                                                        $('#skippedCount').text(progress.skipped || 0);
                                                    }
                                                },
                                                error: function() {
                                                    // Continue polling even on error
                                                }
                                            });
                                        }, 500); // Poll every 500ms
                                    }
                                }
                            });
                            
                            return; // Don't show success/error yet, wait for progress completion
                        }

                        // If controller returns JSON, use it; otherwise fallback to generic success
                        if (typeof response === 'object' && response !== null && 'success' in response) {
                            if (response.success) {
                                handleImportComplete(response);
                            } else {
                                // Show SweetAlert with errors/logs/tips
                                const errors = Array.isArray(response.errors) ? response.errors : [];
                                const logs = Array.isArray(response.logs) ? response.logs : [];
                                const tips = Array.isArray(response.tips) ? response.tips : [];

                                function escapeHtml(str) {
                                    return String(str)
                                        .replace(/&/g, '&amp;')
                                        .replace(/</g, '&lt;')
                                        .replace(/>/g, '&gt;')
                                        .replace(/\"/g, '&quot;')
                                        .replace(/'/g, '&#039;');
                                }

                                let html = '';
                                let primaryTitle = 'Error';
                                if (tips.length) {
                                    const firstTip = String(tips[0]);
                                    const idx = firstTip.indexOf(':');
                                    if (idx > 0) {
                                        primaryTitle = 'Error: ' + firstTip.slice(0, idx);
                                    }
                                }
                                if (errors.length) {
                                    html += '<div style="text-align:left; margin-bottom:10px;"><strong>Errors:</strong><ul style="max-height:200px; overflow:auto; padding-left:18px; margin-top:6px;">';
                                    errors.forEach(function (e) { html += '<li>' + escapeHtml(e) + '</li>'; });
                                    html += '</ul></div>';
                                }
                                if (logs.length) {
                                    html += '<div style="text-align:left;"><strong>Logs:</strong><pre style="white-space:pre-wrap; max-height:200px; overflow:auto; margin-top:6px;">';
                                    logs.forEach(function (l) { html += escapeHtml(l) + '\n'; });
                                    html += '</pre></div>';
                                }
                                if (tips.length) {
                                    html += '<div style="text-align:left; margin-top:10px;"><strong>What you must correct:</strong><ul style="max-height:200px; overflow:auto; padding-left:18px; margin-top:6px;">';
                                    tips.forEach(function (t) { html += '<li>fix: ' + escapeHtml(t) + '</li>'; });
                                    html += '</ul></div>';
                                }

                                let summary = (response.message || '').trim();
                                const counts = [];
                                if (typeof response.imported === 'number') counts.push(`Imported: ${response.imported}`);
                                if (typeof response.skipped === 'number') counts.push(`Skipped: ${response.skipped}`);
                                if (typeof response.failed === 'number') counts.push(`Failed: ${response.failed}`);
                                if (typeof response.errors_count === 'number') counts.push(`Errors in list: ${response.errors_count}`);
                                if (typeof response.logs_count === 'number') counts.push(`Log lines: ${response.logs_count}`);
                                if (counts.length) {
                                    summary = counts.join(' • ');
                                }

                                Swal.fire({
                                    icon: 'error',
                                    title: primaryTitle,
                                    html: (summary ? `<p style=\"margin:0 0 8px 0;\">${escapeHtml(summary)}</p>` : '') + html,
                                    width: 900,
                                    showCloseButton: true,
                                    confirmButtonText: 'OK'
                                });
                            }
                            return;
                        }

                        // Fallback (non-JSON response)
                        Swal.fire({
                            title: 'Import Successful',
                            text: 'Loans have been imported successfully.',
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            $('#importModal').modal('hide');
                            $('#loansTable').DataTable().ajax.reload();
                            $('#importForm')[0].reset();
                            $('#account_id').prop('disabled', true).html('<option value="">Select loan type first</option>');
                        });
                    },
                    error: function (xhr) {
                        let errorMessage = 'An error occurred during import.';

                        if (xhr.responseJSON && xhr.responseJSON.errors) {
                            const errors = xhr.responseJSON.errors;
                            errorMessage = Object.values(errors).flat().join('\n');
                        } else if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMessage = xhr.responseJSON.message;
                        }

                        Swal.fire({
                            title: 'Import Failed',
                            text: errorMessage,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    },
                    complete: function () {
                        // Re-enable submit button (will be disabled again if progress modal is shown)
                        if (!importId) {
                            submitBtn.prop('disabled', false).html(originalText);
                        }
                    }
                });

                // Function to handle import completion
                function handleImportComplete(response, progress) {
                    const hasFailedRecords = (response.failed_export_url || (progress && progress.failed > 0));
                    
                    if (response.success) {
                        let html = '<p>' + (response.message || 'Loans have been imported successfully.') + '</p>';
                        
                        if (hasFailedRecords && response.failed_export_url) {
                            html += '<p class="mt-3"><strong>Some records failed to import.</strong></p>';
                            html += '<a href="' + response.failed_export_url + '" class="btn btn-danger btn-sm mt-2" download>';
                            html += '<i class="bx bx-download"></i> Download Failed Records (Excel)';
                            html += '</a>';
                        }
                        
                        Swal.fire({
                            title: 'Import Successful',
                            html: html,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            $('#importModal').modal('hide');
                            $('#loansTable').DataTable().ajax.reload();
                            $('#importForm')[0].reset();
                            $('#account_id').prop('disabled', true).html('<option value="">Select loan type first</option>');
                        });
                    } else {
                        // Handle errors with failed records download
                        const errors = Array.isArray(response.errors) ? response.errors : [];
                        const tips = Array.isArray(response.tips) ? response.tips : [];
                        
                        function escapeHtml(str) {
                            return String(str)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                        }
                        
                        let html = '';
                        if (errors.length) {
                            html += '<div style="text-align:left; margin-bottom:10px;"><strong>Errors:</strong><ul style="max-height:200px; overflow:auto; padding-left:18px; margin-top:6px;">';
                            errors.slice(0, 10).forEach(function (e) { html += '<li>' + escapeHtml(e) + '</li>'; });
                            if (errors.length > 10) {
                                html += '<li><em>... and ' + (errors.length - 10) + ' more errors</em></li>';
                            }
                            html += '</ul></div>';
                        }
                        
                        if (hasFailedRecords && response.failed_export_url) {
                            html += '<div class="mt-3 p-3 bg-light rounded">';
                            html += '<p class="mb-2"><strong>Download failed records with full details:</strong></p>';
                            html += '<a href="' + response.failed_export_url + '" class="btn btn-danger btn-sm" download>';
                            html += '<i class="bx bx-download"></i> Download Failed Records (Excel)';
                            html += '</a>';
                            html += '</div>';
                        }
                        
                        if (tips.length) {
                            html += '<div style="text-align:left; margin-top:10px;"><strong>What you must correct:</strong><ul style="max-height:200px; overflow:auto; padding-left:18px; margin-top:6px;">';
                            tips.forEach(function (t) { html += '<li>fix: ' + escapeHtml(t) + '</li>'; });
                            html += '</ul></div>';
                        }
                        
                        let summary = (response.message || '').trim();
                        const counts = [];
                        if (typeof response.imported === 'number') counts.push(`Imported: ${response.imported}`);
                        if (typeof response.skipped === 'number') counts.push(`Skipped: ${response.skipped}`);
                        if (typeof response.failed === 'number') counts.push(`Failed: ${response.failed}`);
                        if (counts.length) {
                            summary = counts.join(' • ');
                        }
                        
                        Swal.fire({
                            icon: 'error',
                            title: 'Import Completed with Errors',
                            html: (summary ? `<p style="margin:0 0 8px 0;">${escapeHtml(summary)}</p>` : '') + html,
                            width: 900,
                            showCloseButton: true,
                            confirmButtonText: 'OK'
                        });
                    }
                    
                    // Re-enable submit button
                    submitBtn.prop('disabled', false).html(originalText);
                }
            });

            // Handle loan type change to load appropriate chart accounts
            $('#loan_type').on('change', function () {
                const loanType = $(this).val();
                const chartAccountSelect = $('#account_id');
                const helpText = $('#chart_account_help');

                if (!loanType) {
                    chartAccountSelect.prop('disabled', true).html('<option value="">Select loan type first</option>');
                    helpText.text('Select loan type to see bank accounts');
                    return;
                }

                // Enable the select and show loading
                chartAccountSelect.prop('disabled', false).html('<option value="">Loading accounts...</option>');
                helpText.text('Loading bank accounts...');

                // Fetch chart accounts via Ajax
                $.ajax({
                    url: '{{ route("loans.chart-accounts", ":type") }}'.replace(':type', loanType),
                    method: 'GET',
                    success: function (response) {
                        if (response.success && response.accounts) {
                            let options = '<option value="">Select Bank Account</option>';

                            response.accounts.forEach(function (account) {
                                let displayName = account.name;
                                if (account.account_number) {
                                    displayName = `${account.account_number} - ${account.name}`;
                                }
                                if (account.chart_account) {
                                    displayName += ` (${account.chart_account})`;
                                }
                                options += `<option value="${account.id}">${displayName}</option>`;
                            });

                            chartAccountSelect.html(options);
                            helpText.text(`${response.type} available for selection`);
                        } else {
                            chartAccountSelect.html('<option value="">No accounts found</option>');
                            helpText.text('No bank accounts found for this loan type');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Error fetching chart accounts:', error);
                        chartAccountSelect.html('<option value="">Error loading accounts</option>');
                        helpText.text('Error loading chart accounts. Please try again.');

                        // Show error message
                        Swal.fire({
                            title: 'Error',
                            text: 'Failed to load chart accounts. Please check your connection and try again.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                });
            });
        });

        function deleteLoan(encodedId, customerName) {
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete the loan for ${customerName}. This action cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = `/loans/${encodedId}`;

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
        }

        function openApprovalModal(encodedId, action, level) {
            const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
            const message = document.getElementById('approvalMessage');
            const form = document.getElementById('approvalForm');
            const dateWrapper = document.getElementById('disburse_date_wrapper');
            const dateField = document.getElementById('approval_disbursement_date');
            const bankWrapper = document.getElementById('disburse_bank_wrapper');
            const bankSelect = document.getElementById('approval_bank_account_id');
            const commentsField = document.getElementById('approval_comments');

            // Set action messages based on action type
            const actionMessages = {
                'check': 'Are you sure you want to check this loan? This will mark the loan as checked for first level approval.',
                'approve': 'Are you sure you want to approve this loan? This will change the loan status to approved.',
                'authorize': 'Are you sure you want to authorize this loan? This will mark the loan as authorized for final approval.',
                'disburse': 'Are you sure you want to disburse this loan? This will mark the loan as disbursed and activate the repayment schedule.'
            };

            message.textContent = actionMessages[action] || 'Are you sure you want to proceed with this action?';

            // Set form action URL
            form.action = `/loans/${encodedId}/approve`;

            // Show/hide date and bank selection for disbursement
            if (action === 'disburse') {
                // Show date field
                if (dateWrapper) dateWrapper.style.display = '';
                if (dateField) {
                    dateField.setAttribute('required', 'required');
                    // Set default to today if not already set
                    if (!dateField.value) {
                        dateField.value = new Date().toISOString().split('T')[0];
                    }
                }
                // Show bank selection
                if (bankWrapper) bankWrapper.style.display = '';
                if (bankSelect) bankSelect.setAttribute('required', 'required');
            } else {
                // Hide date field
                if (dateWrapper) dateWrapper.style.display = 'none';
                if (dateField) dateField.removeAttribute('required');
                // Hide bank selection
                if (bankWrapper) bankWrapper.style.display = 'none';
                if (bankSelect) bankSelect.removeAttribute('required');
            }

            // Clear comments field
            if (commentsField) commentsField.value = '';

            modal.show();
        }

        // Handle approval form submission via AJAX
        $('#approvalForm').on('submit', function (e) {
            e.preventDefault();

            const form = $(this);
            const submitBtn = form.find('button[type="submit"]');
            const originalText = submitBtn.html();

            // Disable submit button and show loading
            submitBtn.prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-1"></i>Processing...');

            $.ajax({
                url: form.attr('action'),
                method: 'POST',
                data: form.serialize(),
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function (response) {
                    $('#approvalModal').modal('hide');

                    // Show success SweetAlert
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Loan approval action completed successfully!',
                        timer: 3000,
                        showConfirmButton: false
                    });

                    // Reload DataTable to reflect changes
                    $('#loansTable').DataTable().ajax.reload(null, false);
                },
                error: function (xhr) {
                    let errorMessage = 'Failed to process approval action.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.responseJSON && xhr.responseJSON.errors) {
                        const errors = Object.values(xhr.responseJSON.errors).flat();
                        errorMessage = errors.join(', ');
                    } else if (xhr.responseText) {
                        // Try to extract error from HTML response
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(xhr.responseText, 'text/html');
                        const errorElement = doc.querySelector('.error, .alert-danger, .errors');
                        if (errorElement) {
                            errorMessage = errorElement.textContent.trim();
                        }
                    }

                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: errorMessage,
                        confirmButtonText: 'OK'
                    });
                },
                complete: function () {
                    // Re-enable submit button
                    submitBtn.prop('disabled', false).html(originalText);
                }
            });
        });
    </script>
@endpush
