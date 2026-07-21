@extends('layouts.main')

@section('title', 'Group Repayment Schedule Card')

@push('styles')
<style>
    :root {
        --sheet-margin-top: 18mm;
        --sheet-margin-right: 22mm;
        --sheet-margin-bottom: 20mm;
        --sheet-margin-left: 22mm;
        --sheet-accent: #1a3a5c;
        --sheet-border: #1f2937;
    }

    .report-sheet-wrap {
        display: flex;
        justify-content: center;
        padding: 1.5rem 0 2.5rem;
    }

    .report-sheet {
        background: #fff;
        width: 100%;
        max-width: 1400px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
        padding: var(--sheet-margin-top) var(--sheet-margin-right) var(--sheet-margin-bottom) var(--sheet-margin-left);
    }

    .schedule-card-header {
        text-align: center;
        margin-bottom: 1.25rem;
        padding-bottom: 0.85rem;
        border-bottom: 2px solid var(--sheet-accent);
    }

    .schedule-card-header .company-name {
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #111827;
        margin-bottom: 0.2rem;
    }

    .schedule-card-header .company-meta {
        font-size: 0.78rem;
        color: #4b5563;
        line-height: 1.5;
    }

    .schedule-card-header .group-name {
        font-size: 1rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--sheet-accent);
        margin: 0.75rem 0 0.35rem;
        letter-spacing: 0.03em;
    }

    .schedule-card-header .group-meta,
    .schedule-card-header .period-meta {
        font-size: 0.78rem;
        color: #374151;
        line-height: 1.45;
    }

    .schedule-card-header .period-meta {
        margin-top: 0.35rem;
        font-weight: 600;
    }

    .schedule-card-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
        table-layout: auto;
    }

    .schedule-card-table th,
    .schedule-card-table td {
        border: 1px solid var(--sheet-border);
        padding: 5px 6px;
        vertical-align: middle;
    }

    .schedule-card-table thead th {
        background: var(--sheet-accent);
        color: #fff;
        text-align: center;
        font-weight: 700;
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }

    .schedule-card-table tbody tr:nth-child(even) {
        background: #f8fafc;
    }

    .schedule-card-table tbody tr:hover {
        background: #eef2ff;
    }

    .schedule-card-table tfoot td {
        background: #e5e7eb;
        font-weight: 700;
        font-size: 9px;
    }

    .member-cell {
        text-align: left;
        font-weight: 600;
        white-space: nowrap;
    }

    .text-center { text-align: center; }
    .amount-cell { text-align: right; font-variant-numeric: tabular-nums; }
    .dash-cell { text-align: center; color: #9ca3af; }

    .date-col { min-width: 42px; text-align: center; font-variant-numeric: tabular-nums; }

    .signature-row {
        display: flex;
        justify-content: space-between;
        gap: 3rem;
        margin-top: 2rem;
        padding-top: 0.5rem;
    }

    .signature-box {
        flex: 1;
        max-width: 280px;
        border-top: 1.5px solid #111827;
        padding-top: 0.45rem;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #111827;
    }

    @media print {
        @page {
            size: landscape;
            margin: var(--sheet-margin-top) var(--sheet-margin-right) var(--sheet-margin-bottom) var(--sheet-margin-left);
        }

        body {
            background: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .no-print,
        .sidebar-wrapper,
        .topbar,
        .page-footer,
        .breadcrumb,
        nav,
        header,
        .switcher-wrapper {
            display: none !important;
        }

        .page-wrapper,
        .page-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }

        .report-sheet-wrap {
            padding: 0 !important;
            display: block !important;
        }

        .report-sheet {
            max-width: none !important;
            width: 100% !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        .schedule-card-table tbody tr:hover {
            background: inherit !important;
        }

        .schedule-card-table tbody tr:nth-child(even) {
            background: #f3f4f6 !important;
        }

        .schedule-card-table thead th {
            background: var(--sheet-accent) !important;
            color: #fff !important;
        }

        .schedule-card-table tfoot td {
            background: #e5e7eb !important;
        }
    }
</style>
@endpush

@section('content')
<div class="page-wrapper">
    <div class="page-content">
        <x-breadcrumbs-with-icons :links="[
            ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'bx bx-home'],
            ['label' => 'Reports', 'url' => route('reports.loans'), 'icon' => 'bx bx-file'],
            ['label' => 'Group Repayment Schedule Card', 'url' => '#', 'icon' => 'bx bx-grid-alt']
        ]" />

        <h6 class="mb-0 text-uppercase no-print">Group Repayment Schedule Card</h6>
        <p class="text-muted small mb-0 no-print">Select a group and date range to generate a landscape repayment schedule card for printing or PDF.</p>
        <hr class="no-print" />

        <div class="card mb-4 no-print">
            <div class="card-body">
                <form method="GET" action="{{ route('loans.reports.group_repayment_schedule') }}" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select class="form-select" name="branch_id" id="branch_id">
                            <option value="">All Branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (string) $branchId === (string) $branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Group <span class="text-danger">*</span></label>
                        <select class="form-select" name="group_id" required>
                            <option value="">Select Group</option>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" {{ (string) $groupId === (string) $group->id ? 'selected' : '' }}>
                                    {{ $group->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" value="{{ $startDate }}" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" value="{{ $endDate }}" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bx bx-search me-1"></i> Generate
                        </button>
                    </div>
                    @if($showData && !empty($reportData['rows']))
                    <div class="col-12 d-flex gap-2">
                        <button type="button" class="btn btn-secondary" onclick="window.print()">
                            <i class="bx bx-printer me-1"></i> Print (Landscape)
                        </button>
                        <a href="{{ route('loans.reports.group_repayment_schedule.export_pdf', request()->all()) }}" class="btn btn-danger">
                            <i class="bx bx-file me-1"></i> Download PDF
                        </a>
                    </div>
                    @endif
                </form>
            </div>
        </div>

        @if($showData)
            @if(empty($reportData['rows']))
                <div class="alert alert-info no-print">No active loans found for the selected group in this period.</div>
            @else
                @php
                    $group = $reportData['group'];
                    $loanOfficerName = $group->loanOfficer->name ?? 'N/A';
                    $chairpersonName = $group->groupLeader->name ?? 'N/A';
                @endphp

                <div class="report-sheet-wrap">
                    <div class="report-sheet" id="report-print-area">
                        <div class="schedule-card-header">
                            <div class="company-name">{{ $company->name ?? config('app.name') }}</div>
                            @if(!empty($company?->address))
                                <div class="company-meta">{{ $company->address }}</div>
                            @endif
                            <div class="company-meta">
                                @if(!empty($company?->phone)){{ $company->phone }}@endif
                                @if(!empty($company?->phone) && !empty($company?->email)) / @endif
                                @if(!empty($company?->email))EMAIL: {{ $company->email }}@endif
                            </div>
                            <div class="group-name">{{ $group->name }}</div>
                            <div class="group-meta">CO: {{ $loanOfficerName }}</div>
                            <div class="group-meta">CHAIRPERSON: {{ $chairpersonName }}</div>
                            <div class="period-meta">
                                Period: {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} &ndash; {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="schedule-card-table mb-0">
                                <thead>
                                    <tr>
                                        <th>NO</th>
                                        <th>MEMBER NAME</th>
                                        <th>CYCLE</th>
                                        <th>SECURITY</th>
                                        <th>LOAN NO.</th>
                                        <th>DS AMOUNT</th>
                                        <th>DS DATE</th>
                                        <th>INST. SIZE</th>
                                        <th>C REAL.</th>
                                        <th>OS BAL.</th>
                                        @foreach($reportData['date_keys'] as $dateKey)
                                            <th class="date-col">{{ \Carbon\Carbon::parse($dateKey)->format('d/m') }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($reportData['rows'] as $row)
                                        <tr>
                                            <td class="text-center">{{ $row['no'] }}</td>
                                            <td class="member-cell">{{ $row['member_name'] }}</td>
                                            <td class="text-center">{{ $row['cycle'] }}</td>
                                            <td class="amount-cell">{{ number_format($row['security'], 0) }}</td>
                                            <td class="text-center">{{ $row['loan_no'] }}</td>
                                            <td class="amount-cell">{{ number_format($row['ds_amount'], 0) }}</td>
                                            <td class="text-center">{{ $row['ds_date'] ? \Carbon\Carbon::parse($row['ds_date'])->format('d/m') : '-' }}</td>
                                            <td class="amount-cell">{{ number_format($row['installment_size'], 0) }}</td>
                                            <td class="amount-cell">{{ number_format($row['c_realization'], 0) }}</td>
                                            <td class="amount-cell">{{ number_format($row['os_balance'], 0) }}</td>
                                            @foreach($reportData['date_keys'] as $dateKey)
                                                <td class="date-col {{ isset($row['date_amounts'][$dateKey]) ? 'amount-cell' : 'dash-cell' }}">
                                                    @if(isset($row['date_amounts'][$dateKey]))
                                                        {{ number_format($row['date_amounts'][$dateKey], 0) }}
                                                    @else
                                                        -
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="10" class="text-end">TOTAL</td>
                                        @foreach($reportData['date_keys'] as $dateKey)
                                            <td class="date-col amount-cell">{{ number_format($reportData['column_totals'][$dateKey] ?? 0, 0) }}</td>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="signature-row">
                            <div class="signature-box">JINA LA MUHASIBU</div>
                            <div class="signature-box">SAHIHI YA MUHASIBU</div>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
