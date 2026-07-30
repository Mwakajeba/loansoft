@php
    $reportInfo = '<strong>As of Date:</strong> ' . \Carbon\Carbon::parse($asOfDate)->format('d/m/Y');
    if ($branchId) {
        $reportInfo .= ' | <strong>Branch:</strong> ' . ($branches->find($branchId)->name ?? 'N/A');
    }
    if ($groupId) {
        $reportInfo .= ' | <strong>Group:</strong> ' . ($groups->find($groupId)->name ?? 'N/A');
    }
    if ($loanOfficerId) {
        $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loanOfficers->find($loanOfficerId)->name ?? 'N/A');
    }
    $reportInfo .= '<br><strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Portfolio Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
])

    @if(isset($portfolioData['summary']))
    <table style="margin-bottom:10px;">
        <tr><th colspan="8" style="background:#D9D9D9;">PORTFOLIO SUMMARY</th></tr>
        <tr>
            <th>Total Loans</th><th>Active</th><th>Completed</th><th>Total Outstanding</th>
            <th>Total Paid</th><th>Repayment Rate</th><th>Portfolio at Risk</th><th>PAR Ratio</th>
        </tr>
        <tr>
            <td class="text-center">{{ number_format($portfolioData['summary']['total_loans']) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['active_loans']) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['completed_loans']) }}</td>
            <td class="text-right">{{ number_format($portfolioData['summary']['total_outstanding'], 2) }}</td>
            <td class="text-right">{{ number_format($portfolioData['summary']['total_paid'], 2) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['overall_repayment_rate'], 2) }}%</td>
            <td class="text-right">{{ number_format($portfolioData['summary']['portfolio_at_risk'], 2) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['par_ratio'], 2) }}%</td>
        </tr>
    </table>
    @endif

    <table>
        <thead>
            <tr>
                <th>Customer Name</th><th>Customer No</th><th>Phone</th><th>Gender</th><th>Tenure</th><th>Subsector</th>
                <th>Loan Officer</th><th>Status</th><th>Disbursed Date</th><th>Disbursed Amount</th>
                <th style="background:#dc3545;">Mgmt Fee Unpaid</th><th style="background:#198754;">Mgmt Fee Paid</th>
                <th>Outstanding Principal</th><th>Outstanding Interest</th>
                <th>Days in Arrears</th><th>Accrued Penalties</th><th>Outstanding Balance</th>
                <th>Repayment Rate</th><th>Maturity Date</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; @endphp
            @forelse($portfolioData['loans'] as $index => $loan)
                @php $count++; @endphp
                <tr>
                    <td>{{ $loan['customer'] }}</td>
                    <td class="text-center">{{ $loan['customer_no'] }}</td>
                    <td class="text-center">{{ $loan['phone'] }}</td>
                    <td>{{ $loan['gender'] ?? '' }}</td>
                    <td>{{ $loan['tenure'] ?? '' }}</td>
                    <td>{{ $loan['subsector'] ?? '' }}</td>
                    <td>{{ $loan['loan_officer'] }}</td>
                    <td class="text-center">{{ ucfirst($loan['status']) }}</td>
                    <td class="text-center">{{ $loan['disbursed_date_iso'] ?? $loan['disbursed_date'] }}</td>
                    <td class="text-right">{{ number_format($loan['disbursed_amount'], 2) }}</td>
                    <td class="text-right" style="color:#dc3545;font-weight:bold;">{{ number_format($loan['management_fees_balance'] ?? 0, 2) }}</td>
                    <td class="text-right" style="color:#198754;font-weight:bold;">{{ number_format($loan['management_fees_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_principal'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_interest'] ?? 0, 2) }}</td>
                    <td class="text-center">{{ $loan['days_in_arrears'] ?? 0 }}</td>
                    <td class="text-right">{{ number_format($loan['accrued_penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_balance'] ?? 0, 2) }}</td>
                    <td class="text-center">{{ number_format($loan['repayment_rate'], 2) }}%</td>
                    <td class="text-center">{{ $loan['maturity_date'] }}</td>
                </tr>
            @empty
                <tr><td colspan="19" class="text-center">No records found</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
