@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch->name ?? 'All Branches');
    $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loanOfficer->name ?? 'All Officers');
    $reportInfo .= '<br><strong>As of Date:</strong> ' . \Carbon\Carbon::parse($asOfDate)->format('d/m/Y');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
    $extraStyles = 'th, td { font-size: 6px; padding: 2px 1px; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Outstanding Balance Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr>
                <th>Customer</th><th>Cust No</th><th>Phone</th><th>Loan No</th><th>Expires</th>
                <th>Branch</th><th>Officer</th><th>Disb Date</th><th>Disb Amt</th><th>Tot Int</th>
                <th>Tot P+I</th><th>Exp Fees</th><th>Tot Pen</th>
                <th>Princ Paid</th><th>Int Paid</th><th>Fees Paid</th><th>Pen Paid</th>
                <th>O/s Princ</th><th>O/s Int</th><th>O/s Fees</th><th>O/s Pen</th><th>Other</th><th>O/s Bal</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; @endphp
            @forelse($outstandingData as $index => $row)
                @php $count++; @endphp
                <tr>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-center">{{ $row['expires'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-center">{{ $row['disbursed_date'] }}</td>
                    <td class="text-right">{{ number_format($row['disbursed_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_principal_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['expected_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['principal_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['interest_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['fees_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['penalty_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_principal'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_penalty'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['other_outstanding'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="23" class="text-center">No records found</td></tr>
            @endforelse
            @if(!empty($outstandingData))
            <tr class="total-row">
                <td colspan="8" class="text-center"><strong>TOTALS</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_disbursed'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_interest'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_principal_interest'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_expected_fees'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_penalties'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_principal_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_interest_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_fees_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_penalty_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_principal'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_interest'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_fees'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_penalty'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>0.00</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_balance'] ?? 0, 2) }}</strong></td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
