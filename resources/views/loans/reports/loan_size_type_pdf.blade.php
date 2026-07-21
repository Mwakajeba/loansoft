@php
    $reportInfo = '<strong>Period:</strong> ' . (($startDate && $endDate) ? ($startDate . ' - ' . $endDate) : 'All Time');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Size Type Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 12%;">Loan Size Type</th>
                <th style="width: 7%;">No. of Loans</th>
                <th style="width: 10%;">Loan Amount</th>
                <th style="width: 9%;">Interest</th>
                <th style="width: 10%;">Total Loan</th>
                <th style="width: 10%;">Outstanding</th>
                <th style="width: 7%;">Arrears Count</th>
                <th style="width: 10%;">Arrears Amount</th>
                <th style="width: 7%;">Delayed Count</th>
                <th style="width: 9%;">Delayed Amount</th>
                <th style="width: 9%;">Out in Delayed</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; @endphp
            @forelse($rows as $index => $r)
                @php $count++; @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $r['label'] }}</td>
                    <td class="text-center">{{ number_format($r['count']) }}</td>
                    <td class="text-right">{{ number_format($r['loan_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['total_loan'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['total_outstanding'], 2) }}</td>
                    <td class="text-center">{{ number_format($r['arrears_count']) }}</td>
                    <td class="text-right">{{ number_format($r['arrears_amount'], 2) }}</td>
                    <td class="text-center">{{ number_format($r['delayed_count']) }}</td>
                    <td class="text-right">{{ number_format($r['delayed_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['outstanding_in_delayed'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>GRAND TOTAL</strong></td>
                <td class="text-center"><strong>{{ number_format($grand['count']) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['loan_amount'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['interest'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['total_loan'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['total_outstanding'], 2) }}</strong></td>
                <td class="text-center"><strong>{{ number_format($grand['arrears_count']) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['arrears_amount'], 2) }}</strong></td>
                <td class="text-center"><strong>{{ number_format($grand['delayed_count']) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['delayed_amount'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($grand['outstanding_in_delayed'], 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
