@php
    $reportInfo = '<strong>Period:</strong> ' . (($startDate && $endDate) ? ($startDate . ' - ' . $endDate) : 'All Time');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
    $extraStyles = 'th, td { font-size: 9px; padding: 4px 3px; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Monthly Loan Performance Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A4 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 12%;">Month</th>
                <th style="width: 13%;">Loan Given</th>
                <th style="width: 13%;">Interest</th>
                <th style="width: 13%;">Total Loan + Interest</th>
                <th style="width: 13%;">Total Collected</th>
                <th style="width: 13%;">Outstanding</th>
                <th style="width: 13%;">Actual Interest Collected</th>
                <th style="width: 7%;">Performance %</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalLoanGiven = 0;
                $totalInterest = 0;
                $totalLoan = 0;
                $totalCollected = 0;
                $totalOutstanding = 0;
                $totalActualInterest = 0;
                $count = 0;
            @endphp
            @forelse($rows as $index => $r)
                @php
                    $count++;
                    $totalLoanGiven += $r['loan_given'] ?? 0;
                    $totalInterest += $r['interest'] ?? 0;
                    $totalLoan += $r['total_loan'] ?? 0;
                    $totalCollected += $r['collected'] ?? 0;
                    $totalOutstanding += $r['outstanding'] ?? 0;
                    $totalActualInterest += $r['actual_interest_collected'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $r['month'] }}</td>
                    <td class="text-right">{{ number_format($r['loan_given'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['total_loan'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['collected'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['outstanding'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['actual_interest_collected'], 2) }}</td>
                    <td class="text-center">{{ number_format($r['performance'], 2) }}%</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td class="text-right"><strong>{{ number_format($totalLoanGiven, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalInterest, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalLoan, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalCollected, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalActualInterest, 2) }}</strong></td>
                <td class="text-center"><strong>{{ $totalLoan > 0 ? number_format(($totalCollected / $totalLoan) * 100, 2) : 0 }}%</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
