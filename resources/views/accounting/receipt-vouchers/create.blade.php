@extends('layouts.main')

@section('title', 'New Receipt Voucher')

@section('content')
    <div class="page-wrapper">
        <div class="page-content">
            <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Receipt Vouchers', 'url' => route('accounting.receipt-vouchers.index'), 'icon' => 'bx bx-receipt'],
            ['label' => 'Create Voucher', 'url' => '#', 'icon' => 'bx bx-plus-circle']
        ]" />

            <div class="row">
                <div class="col-12">
                    <div class="card radius-10">
                        <div class="card-header bg-secondary text-white">
                            <div class="d-flex align-items-center">
                                <div>
                                    <h5 class="mb-0 text-white">
                                        <i class="bx bx-receipt me-2"></i>New Receipt Voucher
                                    </h5>
                                    <p class="mb-0 opacity-75">Create a new receipt voucher entry</p>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            @if ($errors->any())
                                <div class="alert alert-danger">
                                    <strong>Please fix the following errors:</strong>
                                    <ul class="mb-0 mt-2">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <form id="receiptVoucherForm" action="{{ route('accounting.receipt-vouchers.store') }}"
                                method="POST" enctype="multipart/form-data">
                                @csrf
                                <input type="hidden" name="loan_id" id="loan_id" value="">

                                <!-- Header Section -->
                                <div class="row mb-4">
                                    <div class="col-lg-6">
                                        <div class="mb-3">
                                            <label for="date" class="form-label fw-bold">
                                                <i class="bx bx-calendar me-1"></i>Date <span class="text-danger">*</span>
                                            </label>
                                            <input type="date" class="form-control @error('date') is-invalid @enderror"
                                                id="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required>
                                            @error('date')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-lg-6">
                                        <div class="mb-3">
                                            <label for="reference" class="form-label fw-bold">
                                                <i class="bx bx-hash me-1"></i>Reference Number
                                            </label>
                                            <input type="text" class="form-control @error('reference') is-invalid @enderror"
                                                id="reference" name="reference" value="{{ old('reference') }}"
                                                placeholder="Enter reference number">
                                            @error('reference')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>


                                <!-- Bank Account Section -->
                                <div class="row mb-4">
                                    <div class="col-lg-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <i class="bx bx-wallet me-1"></i>Bank Account <span class="text-danger">*</span>
                                            </label>
                                            <select
                                                class="form-select form-select-lg select2-single mt-2 @error('bank_account_id') is-invalid @enderror"
                                                id="bank_account_id" name="bank_account_id" required>
                                                <option value="">-- Select Bank Account --</option>
                                                @foreach($bankAccounts as $bankAccount)
                                                    <option value="{{ $bankAccount->id }}" {{ old('bank_account_id') == $bankAccount->id ? 'selected' : '' }}>
                                                        {{ $bankAccount->name }} - {{ $bankAccount->account_number }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('bank_account_id')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <!-- Payee Section -->
                                <div class="row mb-4">
                                    <div class="col-lg-12">
                                        <div class="card border-primary">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bx bx-user me-2"></i>Payee Information
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-lg-4">
                                                        <div class="mb-3">
                                                            <label for="payee_type" class="form-label fw-bold">
                                                                Payee Type <span class="text-danger">*</span>
                                                            </label>
                                                            <select
                                                                class="form-select select2-single @error('payee_type') is-invalid @enderror"
                                                                id="payee_type" name="payee_type" required>
                                                                <option value="">-- Select Payee Type --</option>
                                                                <option value="customer" {{ old('payee_type') == 'customer' ? 'selected' : '' }}>Customer</option>
                                                                <option value="other" {{ old('payee_type') == 'other' ? 'selected' : '' }}>Other</option>
                                                            </select>
                                                            @error('payee_type')
                                                                <div class="invalid-feedback">{{ $message }}</div>
                                                            @enderror
                                                        </div>
                                                    </div>

                                                    <!-- Customer Selection (shown when payee_type is customer) -->
                                                    <div class="col-lg-8" id="customerSection" style="display: none;">
                                                        <div class="mb-3">
                                                            <label for="customer_id" class="form-label fw-bold">
                                                                Select Customer <span class="text-danger">*</span>
                                                            </label>
                                                            <select
                                                                class="form-select select2-single @error('customer_id') is-invalid @enderror"
                                                                id="customer_id" name="customer_id">
                                                                <option value="">-- Select Customer --</option>
                                                                @foreach($customers as $customer)
                                                                    <option value="{{ $customer->id }}" {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                                                                        {{ $customer->name }} ({{ $customer->customerNo }})
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                            @error('customer_id')
                                                                <div class="invalid-feedback">{{ $message }}</div>
                                                            @enderror
                                                        </div>
                                                    </div>

                                                    <!-- Other Payee Name (shown when payee_type is other) -->
                                                    <div class="col-lg-8" id="otherPayeeSection" style="display: none;">
                                                        <div class="mb-3">
                                                            <label for="payee_name" class="form-label fw-bold">
                                                                Payee Name <span class="text-danger">*</span>
                                                            </label>
                                                            <input type="text"
                                                                class="form-control @error('payee_name') is-invalid @enderror"
                                                                id="payee_name" name="payee_name"
                                                                value="{{ old('payee_name') }}"
                                                                placeholder="Enter payee name">
                                                            @error('payee_name')
                                                                <div class="invalid-feedback">{{ $message }}</div>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Customer Loans Section (shown when customer is selected) -->
                                <div class="row mb-4" id="customerLoansSection" style="display: none;">
                                    <div class="col-12">
                                        <div class="card border-info">
                                            <div class="card-header bg-info text-white">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bx bx-credit-card me-2"></i>Customer Loans
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div id="loansLoading" style="display: none;">
                                                    <div class="text-center">
                                                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                                                            <span class="visually-hidden">Loading...</span>
                                                        </div>
                                                        <span class="ms-2">Loading loans...</span>
                                                    </div>
                                                </div>
                                                <div id="loansContainer">
                                                    <!-- Loans will be displayed here -->
                                                </div>
                                                <div id="selectedLoanInfo" class="mt-3" style="display: none;">
                                                    <div class="alert alert-success mb-0 d-flex align-items-center justify-content-between">
                                                        <span><i class="bx bx-check-circle me-2"></i>Repaying loan: <strong id="selectedLoanNo"></strong></span>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearLoanBtn">Change loan</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Repayment line items (when a loan is selected) -->
                                <div class="row mb-4" id="repaymentLinesSection" style="display: none;">
                                    <div class="col-12">
                                        <div class="card border-success">
                                            <div class="card-header bg-success text-white">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bx bx-calendar-check me-2"></i>Repayment line items – select schedule and amount to pay
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <p class="text-muted small mb-3">Add one or more lines: choose an unpaid schedule and enter the amount to pay for it.</p>
                                                <div id="repaymentLinesContainer">
                                                    <!-- Repayment lines added here -->
                                                </div>
                                                <div class="text-left mt-3">
                                                    <button type="button" class="btn btn-success" id="addRepaymentLineBtn">
                                                        <i class="bx bx-plus me-2"></i>Add line
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mb-4">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="description" class="form-label fw-bold">
                                                <i class="bx bx-message-square-detail me-1"></i>Transaction Description
                                            </label>
                                            <textarea class="form-control @error('description') is-invalid @enderror"
                                                id="description" name="description" rows="3"
                                                placeholder="Enter transaction description">{{ old('description') }}</textarea>
                                            @error('description')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="attachment" class="form-label fw-bold">
                                                <i class="bx bx-paperclip me-1"></i>Attachment (Optional)
                                            </label>
                                            <input type="file"
                                                class="form-control @error('attachment') is-invalid @enderror"
                                                id="attachment" name="attachment" accept=".pdf">
                                            <div class="form-text">Supported format: PDF only (Max: 2MB)</div>
                                            @error('attachment')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <!-- Line Items Section (chart accounts – when no loan selected) -->
                                <div class="row mb-4" id="lineItemsSection">
                                    <div class="col-12">
                                        <div class="card border-primary">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0 fw-bold">
                                                    <i class="bx bx-list-ul me-2"></i>Line Items
                                                </h6>
                                            </div>
                                            <div class="card-body">
                                                <div id="lineItemsContainer">
                                                    <!-- Line items will be added here dynamically -->
                                                </div>

                                                <div class="text-left mt-3">
                                                    <button type="button" class="btn btn-success" id="addLineBtn">
                                                        <i class="bx bx-plus me-2"></i>Add Line
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Total and Actions -->
                                <div class="row">
                                    <div class="col-lg-6">
                                        <div class="d-flex justify-content-start">
                                            <a href="{{ route('accounting.receipt-vouchers.index') }}"
                                                class="btn btn-secondary me-2">
                                                <i class="bx bx-arrow-back me-2"></i>Cancel
                                            </a>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="d-flex justify-content-end align-items-center">
                                            <div class="me-4">
                                                <h4 class="mb-0 text-danger fw-bold">
                                                    Total Amount: <span id="totalAmount">0.00</span>
                                                </h4>
                                            </div>
                                            @can('create receipt voucher')
                                            <button type="submit" class="btn btn-primary" id="saveBtn">
                                                <i class="bx bx-plus-circle me-2"></i>Create Voucher
                                            </button>
                                            @endcan
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .form-control-lg,
        .form-select-lg {
            font-size: 1.1rem;
            padding: 0.75rem 1rem;
        }

        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1.1rem;
        }

        .line-item-row {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .line-item-row:hover {
            background: #e9ecef;
            border-color: #adb5bd;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .line-item-row .form-label {
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .line-item-row .form-select,
        .line-item-row .form-control {
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .line-item-row {
                padding: 15px;
            }

            .line-item-row .col-md-4,
            .line-item-row .col-md-3 {
                margin-bottom: 15px;
            }

            .line-item-row .col-md-1 {
                margin-bottom: 15px;
                text-align: center;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        $(document).ready(function () {
            let lineItemCount = 0;

            // Initialize Select2 for all select fields
            $('.select2-single').select2({
                placeholder: 'Select an option',
                allowClear: true,
                width: '100%',
                theme: 'bootstrap-5'
            });

            // Handle payee type change
            $('#payee_type').change(function () {
                const payeeType = $(this).val();

                if (payeeType === 'customer') {
                    $('#customerSection').show();
                    $('#otherPayeeSection').hide();
                    $('#customer_id').prop('required', true);
                    $('#payee_name').prop('required', false);
                    // Load loans if customer is already selected
                    if ($('#customer_id').val()) {
                        loadCustomerLoans($('#customer_id').val());
                    }
                } else if (payeeType === 'other') {
                    $('#customerSection').hide();
                    $('#customerLoansSection').hide();
                    $('#otherPayeeSection').show();
                    $('#customer_id').prop('required', false);
                    $('#payee_name').prop('required', true);
                } else {
                    $('#customerSection').hide();
                    $('#customerLoansSection').hide();
                    $('#otherPayeeSection').hide();
                    $('#customer_id').prop('required', false);
                    $('#payee_name').prop('required', false);
                }
            });

            // Handle customer selection change
            $('#customer_id').on('change', function () {
                const customerId = $(this).val();
                if (customerId) {
                    loadCustomerLoans(customerId);
                } else {
                    $('#customerLoansSection').hide();
                }
            });

            // Function to load customer loans
            function loadCustomerLoans(customerId) {
                $('#customerLoansSection').show();
                $('#loansLoading').show();
                $('#loansContainer').html('');

                $.ajax({
                    url: '{{ route("accounting.receipt-vouchers.customer-loans") }}',
                    method: 'GET',
                    data: {
                        customer_id: customerId
                    },
                    success: function (response) {
                        $('#loansLoading').hide();
                        if (response.success && response.loans.length > 0) {
                            let loansHtml = '<div class="table-responsive"><table class="table table-hover table-sm">';
                            loansHtml += '<thead class="table-light"><tr>';
                            loansHtml += '<th>Loan Number</th>';
                            loansHtml += '<th>Product</th>';
                            loansHtml += '<th>Amount</th>';
                            loansHtml += '<th>Status</th>';
                            loansHtml += '<th>Date Applied</th>';
                            loansHtml += '<th>Disbursed On</th>';
                            loansHtml += '<th>Branch</th>';
                            loansHtml += '<th>Action</th>';
                            loansHtml += '</tr></thead><tbody>';

                            response.loans.forEach(function (loan) {
                                loansHtml += '<tr>';
                                loansHtml += '<td><strong>' + loan.loanNo + '</strong></td>';
                                loansHtml += '<td>' + loan.product_name + '</td>';
                                loansHtml += '<td>TZS ' + loan.amount + '</td>';
                                loansHtml += '<td><span class="badge bg-' + (loan.status === 'Active' ? 'success' : 'warning') + '">' + loan.status + '</span></td>';
                                loansHtml += '<td>' + loan.date_applied + '</td>';
                                loansHtml += '<td>' + loan.disbursed_on + '</td>';
                                loansHtml += '<td>' + loan.branch_name + '</td>';
                                loansHtml += '<td><button type="button" class="btn btn-sm btn-primary select-loan-btn" data-loan-id="' + loan.id + '" data-loan-no="' + (loan.loanNo || '') + '"><i class="bx bx-check me-1"></i>Select for repayment</button></td>';
                                loansHtml += '</tr>';
                            });

                            loansHtml += '</tbody></table></div>';
                            $('#loansContainer').html(loansHtml);
                        } else {
                            $('#loansContainer').html('<div class="alert alert-info mb-0"><i class="bx bx-info-circle me-2"></i>No loans found for this customer.</div>');
                        }
                    },
                    error: function (xhr) {
                        $('#loansLoading').hide();
                        $('#loansContainer').html('<div class="alert alert-danger mb-0"><i class="bx bx-error-circle me-2"></i>Error loading loans. Please try again.</div>');
                        console.error('Error loading loans:', xhr);
                    }
                });
            }

            // Load loans if customer is pre-selected (from old input)
            if ($('#customer_id').val() && $('#payee_type').val() === 'customer') {
                loadCustomerLoans($('#customer_id').val());
            }

            // Trigger change event on page load if value exists
            if ($('#payee_type').val()) {
                $('#payee_type').trigger('change');
            }

            let loanSchedules = [];
            let repaymentLineCount = 0;

            // Select loan for repayment
            $(document).on('click', '.select-loan-btn', function () {
                const loanId = $(this).data('loan-id');
                const loanNo = $(this).data('loan-no');

                // Force payee type to customer when using loan repayment mode
                $('#payee_type').val('customer').trigger('change');

                // Disable required on normal line items so hidden fields don't block HTML5 validation
                $('.chart-account-select, .amount-input').prop('required', false);

                $('#loan_id').val(loanId);
                $('#selectedLoanNo').text(loanNo || 'Loan #' + loanId);
                $('#selectedLoanInfo').show();
                $('#lineItemsSection').hide();
                $('#repaymentLinesSection').show();
                $('#repaymentLinesContainer').empty();
                repaymentLineCount = 0;
                calculateTotal();
                $.get('{{ route("accounting.receipt-vouchers.loan-schedules") }}', { loan_id: loanId }, function (res) {
                    if (res.success && res.schedules && res.schedules.length) {
                        loanSchedules = res.schedules;
                        addRepaymentLineRow();
                    } else {
                        $('#repaymentLinesContainer').html('<div class="alert alert-warning mb-0">No unpaid schedules for this loan.</div>');
                    }
                }).fail(function () {
                    $('#repaymentLinesContainer').html('<div class="alert alert-danger mb-0">Failed to load schedules.</div>');
                });
            });

            $('#clearLoanBtn').on('click', function () {
                $('#loan_id').val('');
                $('#selectedLoanInfo').hide();
                $('#repaymentLinesSection').hide();
                $('#repaymentLinesContainer').empty();
                $('#lineItemsSection').show();

                // Re-enable required on normal line items when leaving repayment mode
                $('.chart-account-select, .amount-input').prop('required', true);

                loanSchedules = [];
                repaymentLineCount = 0;
                calculateTotal();
            });

            function addRepaymentLineRow() {
                repaymentLineCount++;
                let options = '<option value="">-- Select schedule --</option>';
                loanSchedules.forEach(function (s) {
                    options += '<option value="' + s.id + '" data-remaining="' + s.remaining + '">#' + s.schedule_number + ' Due ' + s.due_date + ' (Remaining: ' + parseFloat(s.remaining).toFixed(2) + ')</option>';
                });
                const row = '<div class="line-item-row repayment-line-row" id="repaymentLine_' + repaymentLineCount + '">' +
                    '<div class="row align-items-end">' +
                    '<div class="col-lg-5"><label class="form-label fw-bold">Schedule <span class="text-danger">*</span></label>' +
                    '<select class="form-select schedule-select" name="repayment_lines[' + (repaymentLineCount - 1) + '][schedule_id]" required>' + options + '</select></div>' +
                    '<div class="col-lg-4"><label class="form-label fw-bold">Amount to pay <span class="text-danger">*</span></label>' +
                    '<input type="number" class="form-control repayment-amount-input" name="repayment_lines[' + (repaymentLineCount - 1) + '][amount]" step="0.01" min="0.01" placeholder="0.00" required></div>' +
                    '<div class="col-lg-2"><label class="form-label">&nbsp;</label><button type="button" class="btn btn-outline-danger d-block remove-repayment-line" data-row="' + repaymentLineCount + '"><i class="bx bx-trash"></i> Remove</button></div>' +
                    '</div></div>';
                $('#repaymentLinesContainer').append(row);
                calculateTotal();
            }

            $('#addRepaymentLineBtn').on('click', function () {
                if (loanSchedules.length) addRepaymentLineRow();
            });

            $(document).on('click', '.remove-repayment-line', function () {
                const row = $(this).data('row');
                $('#repaymentLine_' + row).remove();
                recalcRepaymentLineNames();
                calculateTotal();
            });

            function recalcRepaymentLineNames() {
                let idx = 0;
                $('.repayment-line-row').each(function () {
                    $(this).find('.schedule-select').attr('name', 'repayment_lines[' + idx + '][schedule_id]');
                    $(this).find('.repayment-amount-input').attr('name', 'repayment_lines[' + idx + '][amount]');
                    idx++;
                });
            }

            // Add line item
            $('#addLineBtn').click(function () {
                addLineItem();
            });

            // Add initial line item
            addLineItem();
            
            // Initialize Select2 for existing chart account selects
            $('.chart-account-select').select2({
                placeholder: 'Select Chart Account',
                allowClear: true,
                width: '100%',
                theme: 'bootstrap-5'
            });

            function addLineItem() {
                lineItemCount++;
                const lineItemHtml = `
                                                    <div class="line-item-row" id="lineItem_${lineItemCount}">
                                                        <div class="row">
                                                            <div class="col-lg-5">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Chart Account <span class="text-danger">*</span></label>
                                                                    <select class="form-select chart-account-select select2-single" name="line_items[${lineItemCount}][chart_account_id]" required>
                                                                        <option value="">-- Select Chart Account --</option>
                                                                        @foreach($chartAccounts as $chartAccount)
                                                                            <option value="{{ $chartAccount->id }}">{{ $chartAccount->account_name }} ({{ $chartAccount->account_code }})</option>
                                                                        @endforeach
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="col-lg-3">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                                                                    <input type="number" class="form-control amount-input" name="line_items[${lineItemCount}][amount]" 
                                                                           step="0.01" min="0.01" placeholder="0.00" required>
                                                                </div>
                                                            </div>
                                                            <div class="col-lg-3">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Description</label>
                                                                    <input type="text" class="form-control" name="line_items[${lineItemCount}][description]" 
                                                                           placeholder="Optional description">
                                                                </div>
                                                            </div>
                                                            <div class="col-lg-1">
                                                                <div class="mb-3">
                                                                    <label class="form-label">&nbsp;</label>
                                                                    <button type="button" class="btn remove-line-btn" onclick="removeLineItem(${lineItemCount})">
                                                                        <i class="bx bx-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                `;
                $('#lineItemsContainer').append(lineItemHtml);
                
                // Initialize Select2 for the new chart account select
                setTimeout(function() {
                    $('#lineItemsContainer .chart-account-select').last().select2({
                        placeholder: 'Select Chart Account',
                        allowClear: true,
                        width: '100%',
                        theme: 'bootstrap-5'
                    });
                }, 100);
            }

            // Remove line item
            window.removeLineItem = function (index) {
                if ($('.line-item-row').length > 1) {
                    $(`#lineItem_${index}`).remove();
                    calculateTotal();
                } else {
                    alert('At least one line item is required.');
                }
            };

            // Calculate total
            function calculateTotal() {
                let total = 0;
                if ($('#repaymentLinesSection').is(':visible')) {
                    $('.repayment-amount-input').each(function () {
                        total += parseFloat($(this).val()) || 0;
                    });
                } else {
                    $('.amount-input').each(function () {
                        total += parseFloat($(this).val()) || 0;
                    });
                }
                $('#totalAmount').text(total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            }

            // Handle amount input changes
            $(document).on('input', '.amount-input', function () {
                calculateTotal();
            });
            $(document).on('input', '.repayment-amount-input', function () {
                calculateTotal();
            });

            // On submit, just show loading state and let backend validation handle errors
            $('#receiptVoucherForm').on('submit', function () {
                $('#saveBtn').prop('disabled', true).html('<i class="bx bx-loader-alt bx-spin me-2"></i>Saving...');
            });
        });
    </script>
@endpush