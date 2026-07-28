@php
    use Vinkla\Hashids\Facades\Hashids;
@endphp

@extends('layouts.main')

@section('title', 'Group Repayment')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <x-breadcrumbs-with-icons :links="[
                ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
                ['label' => 'Groups', 'url' => route('groups.index'), 'icon' => 'bx bx-group'],
                ['label' => 'Group Details', 'url' => route('groups.show', Hashids::encode($group->id)), 'icon' => 'bx bx-group'],
                ['label' => 'Group Repayments', 'url' => '#', 'icon' => 'bx bx-info-circle']
            ]" />
        </div>
        <h6 class="mb-0 text-uppercase">GROUP REPAYMENT FOR {{ $group->name }}</h6>
        <hr />

        <div class="card radius-10 mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="shared_payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                        <input type="date"
                               id="shared_payment_date"
                               class="form-control @error('payment_date') is-invalid @enderror"
                               value="{{ old('payment_date', date('Y-m-d')) }}"
                               max="{{ date('Y-m-d') }}"
                               required>
                        @error('payment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card radius-10 mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <h6 class="mb-2"><i class="bx bx-spreadsheet me-1"></i> Excel Import / Export</h6>
                        <p class="text-muted small mb-0">
                            Download the template with members, due dates, and instalment amounts. Edit <strong>amount_to_pay</strong> in Excel, then import to process repayments.
                            Do not change <strong>customer_id</strong>, <strong>loan_id</strong>, or <strong>schedule_id</strong>.
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        @if($totalAmountToPay > 0)
                            <a href="{{ route('groups.payment.export', Hashids::encode($group->id)) }}" class="btn btn-success btn-sm me-1">
                                <i class="bx bx-download me-1"></i> Download Excel
                            </a>
                        @else
                            <button type="button" class="btn btn-success btn-sm me-1" disabled>
                                <i class="bx bx-download me-1"></i> Download Excel
                            </button>
                        @endif
                    </div>
                </div>

                <form action="{{ route('groups.payment.import', Hashids::encode($group->id)) }}" method="POST" enctype="multipart/form-data" class="row g-3 align-items-end mt-2" id="groupRepaymentImportForm">
                    @csrf
                    <input type="hidden" name="payment_date" id="import_payment_date" value="{{ old('payment_date', date('Y-m-d')) }}">
                    <div class="col-md-8">
                        <label for="import_file" class="form-label">Import Excel</label>
                        <input type="file" name="import_file" id="import_file" class="form-control @error('import_file') is-invalid @enderror" accept=".xlsx,.xls,.csv" required>
                        @error('import_file') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4 text-md-end">
                        <button type="submit" class="btn btn-warning">
                            <i class="bx bx-upload me-1"></i> Import &amp; Process
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card radius-10">
            <div class="card-body">
                <form action="{{ route('groups.groupStore', Hashids::encode($group->id)) }}" method="POST" id="groupRepaymentForm">
                    @csrf
                    <input type="hidden" name="payment_date" id="form_payment_date" value="{{ old('payment_date', date('Y-m-d')) }}">

                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                        <h4 class="card-title mb-0">Repayment Schedule Details</h4>
                        <div class="d-flex gap-3 align-items-center flex-wrap">
                            <h5 class="mb-0">Total Amount to Pay: <strong id="total-amount-display">{{ number_format($totalAmountToPay, 2) }}</strong></h5>
                            <button
                                type="submit"
                                class="btn btn-primary"
                                @if($totalAmountToPay <=0) disabled @endif>
                                <i class="bx bx-save"></i> Generate Repayment
                            </button>
                        </div>
                    </div>

                    @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    @endif

                    @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-striped table-bordered" id="repayment-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Customer</th>
                                    <th>Due Date</th>
                                    <th>Installment Amount</th>
                                    <th>Fee</th>
                                    <th>Penalty</th>
                                    <th>Already Paid</th>
                                    <th>Total Due</th>
                                    <th>Amount to Pay</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($repaymentData as $customerData)
                                @foreach($customerData['loans'] as $loanData)
                                <tr id="customer-row-{{ $customerData['customer']->id }}-loan-{{ $loanData['loan']->id }}"
                                    data-original-amount="{{ $loanData['amount_to_pay'] }}">

                                    <td>{{ $loop->parent->index + 1 }}</td>
                                    <td>
                                        @if($loop->first)
                                        <strong>{{ $customerData['customer']->name }}</strong><br>
                                        <small class="text-muted">{{ $customerData['customer']->customerNo }}</small>
                                        @endif
                                    </td>
                                    <td>{{ Carbon\Carbon::parse($loanData['schedule']->due_date)->format('d M, Y') }}</td>
                                    <td>{{ number_format($loanData['installment_amount'], 2) }}</td>
                                    <td>{{ number_format($loanData['fee_amount'], 2) }}</td>
                                    <td>{{ number_format($loanData['penalty_amount'], 2) }}</td>
                                    <td>{{ number_format($loanData['amount_already_paid'], 2) }}</td>
                                    <td>{{ number_format($loanData['total_due'], 2) }}</td>
                                    <td>
                                        <input type="hidden" name="repayments[{{ $customerData['customer']->id }}][{{ $loanData['loan']->id }}][schedule_id]" value="{{ $loanData['schedule']->id }}">
                                        <input type="hidden" name="repayments[{{ $customerData['customer']->id }}][{{ $loanData['loan']->id }}][customer_id]" value="{{ $customerData['customer']->id }}">
                                        <input type="hidden" name="repayments[{{ $customerData['customer']->id }}][{{ $loanData['loan']->id }}][loan_id]" value="{{ $loanData['loan']->id }}">

                                        <input type="number" step="0.01" name="repayments[{{ $customerData['customer']->id }}][{{ $loanData['loan']->id }}][amount_paid]"
                                            class="form-control amount-input"
                                            value="{{ old('repayments.'.$customerData['customer']->id.'.'.$loanData['loan']->id.'.amount_paid', number_format($loanData['amount_to_pay'], 2, '.', '')) }}"
                                            min="0" max="{{ number_format($loanData['amount_to_pay'], 2, '.', '') }}">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-danger btn-sm remove-customer" data-row-id="customer-row-{{ $customerData['customer']->id }}-loan-{{ $loanData['loan']->id }}">
                                            <i class="bx bx-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                                @empty
                                <tr>
                                    <td colspan="10" class="text-center">No unpaid schedules found for this group.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        border: none;
    }

    .form-control,
    .form-select {
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
    }

    .btn {
        border-radius: 0.5rem;
        padding: 0.75rem 1.5rem;
    }

    .alert {
        border-radius: 0.5rem;
        border: none;
    }

    .table-responsive {
        overflow-x: auto;
    }

    th,
    td {
        white-space: nowrap;
    }
</style>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const repaymentTable = document.querySelector('#repayment-table');
        const totalAmountDisplay = document.querySelector('#total-amount-display');
        const sharedPaymentDate = document.getElementById('shared_payment_date');
        const formPaymentDate = document.getElementById('form_payment_date');
        const importPaymentDate = document.getElementById('import_payment_date');

        function syncPaymentDate() {
            if (!sharedPaymentDate) {
                return;
            }
            if (formPaymentDate) {
                formPaymentDate.value = sharedPaymentDate.value;
            }
            if (importPaymentDate) {
                importPaymentDate.value = sharedPaymentDate.value;
            }
        }

        sharedPaymentDate?.addEventListener('change', syncPaymentDate);
        syncPaymentDate();

        function updateTotalAmount() {
            let total = 0;
            document.querySelectorAll('.amount-input').forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            totalAmountDisplay.textContent = total.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        repaymentTable.addEventListener('click', function(event) {
            if (event.target.closest('.remove-customer')) {
                const button = event.target.closest('.remove-customer');
                const rowId = button.getAttribute('data-row-id');
                const row = document.getElementById(rowId);
                if (row) {
                    row.remove();
                    updateTotalAmount();
                }
            }
        });

        repaymentTable.addEventListener('input', function(event) {
            if (event.target.classList.contains('amount-input')) {
                updateTotalAmount();
            }
        });

        updateTotalAmount();
    });
</script>
@endpush
