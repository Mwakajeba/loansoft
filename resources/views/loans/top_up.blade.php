@extends('layouts.main')

@section('title', 'Loan Top-Up')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => route('loans.list'), 'icon' => 'bx bx-money'],
            ['label' => 'Loan Details', 'url' => route('loans.show', $loan->encodedId), 'icon' => 'bx bx-detail'],
            ['label' => 'Top-Up', 'url' => '#', 'icon' => 'bx bx-plus']
        ]" />
        <h6 class="mb-0 text-uppercase">LOAN TOP-UP</h6>
        <hr/>
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Loan Top-Up</h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('loans.top_up.store', $loan->encodedId) }}">
                        @csrf
                        @php
                            $outstandingBalance = $loan->schedule->sum('remaining_amount');
                        @endphp
                        <div class="mb-3">
                            <label>Outstanding Balance: </label>
                            <strong>{{ number_format($outstandingBalance, 2) }}</strong>
                            <input type="hidden" name="outstanding_balance" value="{{ $outstandingBalance }}">
                        </div>
                        <div class="mb-3">
                            <label for="new_loan_amount" class="form-label">New Loan Amount</label>
                            <input type="number" step="0.01" min="0" name="new_loan_amount" id="new_loan_amount" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="interest" class="form-label">Interest Rate (%)</label>
                            <input type="number" step="0.01" min="0" name="interest" id="interest" class="form-control" value="{{ old('interest', $loan->interest) }}" required>
                        </div>
                        <div class="mb-3">
                            <label for="period" class="form-label">Additional Period (Months)</label>
                            <input type="number" step="1" min="1" max="60" name="period" id="period" class="form-control" value="{{ old('period', 12) }}" required>
                        </div>
                        <div class="mb-3">
                            <label for="purpose" class="form-label">Purpose of Top-Up</label>
                            <textarea name="purpose" id="purpose" class="form-control" rows="2" required>{{ old('purpose') }}</textarea>
                        </div>
                        <button type="submit" class="btn btn-success">Submit Top-Up</button>
                        <a href="{{ route('loans.show', $loan->encodedId) }}" class="btn btn-secondary ms-2">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
</div>
@endsection
