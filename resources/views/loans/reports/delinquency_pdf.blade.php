@php
    $reportInfo = '<strong>As of Date:</strong> ' . \Carbon\Carbon::parse($asOfDate)->format('d/m/Y');
    $reportInfo .= ' | <strong>Min. Days Delinquent:</strong> ' . $delinquencyDays . '+';
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
    'reportTitle' => 'Loan Delinquency Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 12%;">Customer</th>
                <th style="width: 7%;">Customer No</th>
                <th style="width: 8%;">Phone</th>
                <th style="width: 9%;">Branch</th>
                <th style="width: 9%;">Group</th>
                <th style="width: 9%;">Loan Officer</th>
                <th style="width: 12%;">Outstanding</th>
                <th style="width: 8%;">Days Overdue</th>
                <th style="width: 8%;">Bucket</th>
                <th style="width: 8%;">Severity</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalOutstanding = 0;
                $count = 0;
            @endphp
            @forelse($delinquencyData['loans'] as $index => $loan)
                @php
                    $count++;
                    $totalOutstanding += $loan['outstanding_amount'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $loan['customer'] }}</td>
                    <td class="text-center">{{ $loan['customer_no'] }}</td>
                    <td class="text-center">{{ $loan['phone'] }}</td>
                    <td>{{ $loan['branch'] }}</td>
                    <td>{{ $loan['group'] }}</td>
                    <td>{{ $loan['loan_officer'] }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_amount'], 0) }}</td>
                    <td class="text-center">{{ $loan['days_in_arrears'] }}d</td>
                    <td class="text-center">{{ $loan['delinquency_bucket'] }}</td>
                    <td class="text-center">{{ $loan['severity_level'] }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="5" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 0) }}</strong></td>
                <td colspan="3"></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
