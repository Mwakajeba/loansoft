@extends('layouts.main')

@section('title', 'Loan Restructuring')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => route('loans.list'), 'icon' => 'bx bx-wallet'],
            ['label' => 'Loan Restructuring', 'url' => '#', 'icon' => 'bx bx-refresh']
        ]" />
        <h4 class="fw-bold text-dark mb-4">Loan Restructuring for {{ $loan->customer->name }}</h4>
        <div class="row">
            <div class="col-lg-7 mb-4">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bx bx-refresh me-2"></i>Restructure Loan</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('loans.restructure.process', $loan->encodedId ?? Hashids::encode($loan->id)) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label fw-bold">Outstanding Principal</label>
                                <input type="text" readonly class="form-control" value="{{ number_format($outstanding['principal'], 2) }}" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Outstanding Interest</label>
                                <input type="text" readonly class="form-control" value="{{ number_format($outstanding['interest'], 2) }}" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Outstanding Penalty</label>
                                <input type="text" readonly class="form-control" value="{{ number_format($outstanding['penalty'], 2) }}" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">New Tenure (Period) <span class="text-danger">*</span></label>
                                <input type="number" name="new_tenure" class="form-control" min="1" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">New Interest Rate (%) <span class="text-danger">*</span></label>
                                <input type="number" name="new_interest_rate" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">New Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="new_start_date" class="form-control" required>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="penalty_waived" id="penalty_waived" value="1">
                                <label class="form-check-label" for="penalty_waived">
                                    Waive Outstanding Penalty
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">Process Restructuring</button>
                            <a href="{{ route('loans.show', $loan->encodedId ?? Hashids::encode($loan->id)) }}" class="btn btn-secondary ms-2">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bx bx-info-circle me-2"></i>Restructuring Guidelines</h6>
                    </div>
                    <div class="card-body">
                        <ul class="mb-2">
                            <li>Paid installments will <strong>never</strong> be changed or deleted.</li>
                            <li>Only the outstanding balance (principal, interest, penalties) is restructured.</li>
                            <li>All unpaid future schedules are <strong>cancelled</strong> (not deleted).</li>
                            <li>New schedule is generated for the outstanding balance and new terms.</li>
                            <li>No new cash is disbursed (not a top-up).</li>
                            <li>Original loan record remains the same (no new loan ID).</li>
                            <li>Schedules are versioned for audit trail.</li>
                            <li>Edge cases handled: partial payments, accrued interest, penalty waiver/carry-forward, flat vs reducing interest.</li>
                        </ul>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="bx bx-error-circle me-2"></i>
                            <strong>Note:</strong> Please review all values before submitting. This action cannot be undone.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
