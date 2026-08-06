@extends('layouts.main')

@section('title', 'Create Loan Bill')

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans', 'url' => route('loans.list'), 'icon' => 'bx bx-wallet'],
            ['label' => 'Loan Details', 'url' => route('loans.show', $encodedId), 'icon' => 'bx bx-file'],
            ['label' => 'Create Loan Bill', 'url' => '#', 'icon' => 'bx bx-plus-circle']
        ]" />
        <h6 class="mb-0 text-uppercase">CREATE LOAN BILL / FOLLOW-UP COST</h6>
        <hr />

        <div class="card">
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="text-muted small">Customer</div>
                        <div class="fw-bold">{{ $loan->customer->name ?? '—' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Loan No</div>
                        <div class="fw-bold font-monospace text-primary">{{ $loan->loanNo }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Customer Phone</div>
                        <div class="fw-bold">{{ $customerPhone ?: 'Not set — SMS will be skipped' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Current Outstanding</div>
                        <div class="fw-bold text-danger">TZS {{ number_format($loan->getTotalOutstandingAmount(), 2) }}</div>
                    </div>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('loans.bills.store', $encodedId) }}" method="POST">
                    @csrf
                    <div class="row">
                        <div class="col-md-7 mb-3">
                            <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="description" name="description"
                                value="{{ old('description') }}" required maxlength="255"
                                placeholder="e.g. Follow-up visit cost, Legal fee, Field collection cost">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="amount" class="form-label">Amount (TZS) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="amount"
                                name="amount" value="{{ old('amount') }}" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="bill_date" class="form-label">Bill Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="bill_date" name="bill_date"
                                value="{{ old('bill_date', date('Y-m-d')) }}" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="receivable_account_id" class="form-label">
                                Receivable Account (Debit) <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="receivable_account_id"
                                name="receivable_account_id" required>
                                <option value="">Select chart account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}"
                                        {{ (string) old('receivable_account_id', $defaultReceivableAccount?->id) === (string) $account->id ? 'selected' : '' }}>
                                        {{ $account->account_code }} - {{ $account->account_name }}
                                        ({{ $account->accountClassGroup?->accountClass?->name }} /
                                        {{ $account->accountClassGroup?->name }})
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">Default: 1144 - Follow Ups Receivables</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="income_account_id" class="form-label">
                                Income Account (Credit) <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="income_account_id"
                                name="income_account_id" required>
                                <option value="">Select chart account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}"
                                        {{ (string) old('income_account_id', $defaultIncomeAccount?->id) === (string) $account->id ? 'selected' : '' }}>
                                        {{ $account->account_code }} - {{ $account->account_name }}
                                        ({{ $account->accountClassGroup?->accountClass?->name }} /
                                        {{ $account->accountClassGroup?->name }})
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">Default: 4433 - Follow Ups Income</small>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <strong>Double entry:</strong> the selected receivable account is debited and
                        the selected income account is credited. Customer SMS is sent automatically.
                    </div>

                    <div class="card border mb-4" id="sms_preview_card">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><i class="bx bx-message-detail me-1"></i>Message sent automatically to customer</span>
                            <span class="text-muted small">
                                <span id="sms_char_count">0</span> characters ·
                                <span id="sms_part_count">1</span> SMS
                            </span>
                        </div>
                        <div class="card-body py-3">
                            <p class="mb-0 font-monospace small" id="sms_preview_text"></p>
                            <small class="text-muted d-block mt-2">
                                To: {{ $customerPhone ?: 'no phone on file' }}
                            </small>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="bx bx-plus-circle me-1"></i>Create Bill
                        </button>
                        <a href="{{ route('loans.show', $encodedId) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const template = @json($smsTemplate);
        const currentOutstanding = Number(@json($currentOutstanding));
        const descriptionInput = document.getElementById('description');
        const amountInput = document.getElementById('amount');
        const previewText = document.getElementById('sms_preview_text');
        const charCount = document.getElementById('sms_char_count');
        const partCount = document.getElementById('sms_part_count');

        function renderPreview() {
            const amount = Number(amountInput.value || 0);
            const description = descriptionInput.value.trim();
            const totalOutstanding = currentOutstanding + amount;

            let message = template.replace('%AMOUNT%', amount.toLocaleString('en-US', { maximumFractionDigits: 0 }));
            message = message.replace(
                '%OUTSTANDING%',
                totalOutstanding.toLocaleString('en-US', { maximumFractionDigits: 0 })
            );
            message = description
                ? message.replace('%DESCRIPTION%', description)
                : message.replace(' (%DESCRIPTION%)', '');

            previewText.textContent = message;
            charCount.textContent = message.length;
            partCount.textContent = Math.max(1, Math.ceil(message.length / 160));
        }

        [descriptionInput, amountInput].forEach(function (input) {
            input.addEventListener('input', renderPreview);
            input.addEventListener('change', renderPreview);
        });

        renderPreview();
    })();
</script>
@endsection
