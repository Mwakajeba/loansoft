@extends('layouts.main')

@section('title', 'Customer Loan Statement Report')

@push('styles')
<style>
    .statement-sheet-wrap {
        display: flex;
        justify-content: center;
        padding: 1rem 0 2rem;
    }

    .statement-sheet {
        background: #fff;
        width: 100%;
        max-width: 1200px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        padding: 1.5rem;
        overflow-x: auto;
    }

    .statement-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Calibri, Arial, sans-serif;
        font-size: 12px;
        color: #000;
    }

    .statement-table td,
    .statement-table th {
        border: 1px solid #000;
        padding: 4px 6px;
        vertical-align: middle;
    }

    .client-name-cell {
        text-align: center;
        font-weight: 700;
        font-size: 13px;
        border: none !important;
        padding-bottom: 10px;
    }

    .summary-label {
        font-weight: 700;
        background: #f3f4f6;
    }

    .schedule-head th {
        background: #fff;
        font-weight: 700;
        text-align: center;
        font-size: 11px;
    }

    .amount {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

    .text-center { text-align: center; }

    .fee-remarks-paid {
        background: #92d050 !important;
        color: #000;
        font-weight: 600;
    }

    .fee-remarks-unpaid {
        background: #ff0000 !important;
        color: #fff;
        font-weight: 600;
    }

    .outstanding-paid {
        background: #92d050 !important;
    }

    .outstanding-overdue {
        background: #ff0000 !important;
        color: #fff;
        font-weight: 600;
    }

    .totals-row td {
        background: #f9fafb;
    }

    .settlements-header {
        background: #4472c4 !important;
        color: #fff;
        font-weight: 700;
        text-align: center;
    }

    .settlement-outstanding {
        background: #ff0000 !important;
        color: #fff;
        font-weight: 700;
    }

    .settlement-loan {
        background: #ffff00 !important;
        color: #000;
        font-weight: 700;
    }

    .settlement-label {
        font-weight: 700;
    }

    @media print {
        .no-print { display: none !important; }
        .statement-sheet {
            border: none;
            box-shadow: none;
            max-width: none;
            padding: 0;
        }
    }
</style>
@endpush

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Loans Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-credit-card'],
            ['label' => 'Customer Loan Statement', 'url' => '#', 'icon' => 'bx bx-receipt'],
        ]" />

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-uppercase">Customer Loan Statement Report</h6>
        </div>
        <hr />

        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="GET" action="{{ route('reports.loans.customer_statement') }}">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">As of Date <span class="text-danger">*</span></label>
                            <input type="date" name="as_of_date" class="form-control"
                                   value="{{ $asOfDate ?? now()->format('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select" id="branch_id">
                                <option value="">All Branches</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(($branchId ?? '') == $branch->id)>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Loan <span class="text-danger">*</span></label>
                            <select name="loan_id" id="loan_id" class="form-select select2-loan-search" required>
                                <option value="">Select a loan...</option>
                                @foreach($loans as $loanOption)
                                    <option value="{{ $loanOption->id }}" @selected(($loanId ?? '') == $loanOption->id)>
                                        {{ $loanOption->loanNo }} — {{ $loanOption->customer->name ?? 'N/A' }}
                                        ({{ number_format($loanOption->amount, 0) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bx bx-search me-1"></i> Generate
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if($reportData)
            <div class="d-flex gap-2 mb-3 no-print">
                <form method="GET" action="{{ route('reports.loans.customer_statement.export_excel') }}">
                    <input type="hidden" name="as_of_date" value="{{ $asOfDate }}">
                    <input type="hidden" name="loan_id" value="{{ $loanId }}">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bx bx-spreadsheet me-1"></i> Export Excel
                    </button>
                </form>
                <form method="GET" action="{{ route('reports.loans.customer_statement.export_pdf') }}">
                    <input type="hidden" name="as_of_date" value="{{ $asOfDate }}">
                    <input type="hidden" name="loan_id" value="{{ $loanId }}">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bx bxs-file-pdf me-1"></i> Export PDF
                    </button>
                </form>
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
                    <i class="bx bx-printer me-1"></i> Print
                </button>
            </div>

            <div class="statement-sheet-wrap">
                <div class="statement-sheet">
                    @include('loans.reports.partials.customer_loan_statement_sheet', ['reportData' => $reportData])
                </div>
            </div>
        @elseif($showData && !$reportData)
            <div class="alert alert-warning">
                <i class="bx bx-info-circle me-2"></i>
                Please select a loan and click Generate to view the customer statement.
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(function () {
        $('#loan_id').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Search loan number or customer name...',
            allowClear: true,
            minimumResultsForSearch: 0
        });
    });
</script>
@endpush
