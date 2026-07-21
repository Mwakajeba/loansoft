@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch_name ?? 'All Branches');
    $reportInfo .= ' | <strong>Group:</strong> ' . ($group_name ?? 'All Groups');
    $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loan_officer_name ?? 'All Officers');
    $reportInfo .= '<br><strong>Period:</strong> ' . \Carbon\Carbon::parse($start_date)->format('d/m/Y') . ' - ' . \Carbon\Carbon::parse($end_date)->format('d/m/Y');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . ($generated_date ?? \Carbon\Carbon::now()->format('d/m/Y H:i:s'));
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Expected vs Collected Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
])

    <table>
        <thead>
            <tr>
                <th>S/N</th>
                <th>Customer</th>
                <th>Customer No</th>
                <th>Phone</th>
                <th>Loan Amount</th>
                <th>Disbursed Date</th>
                <th>Loan Officer</th>
                <th>Instalment due date(s)</th>
                <th>Outstanding Fees</th>
                <th>Arrears (before period)</th>
                <th>Due Instalment</th>
                <th>Accrued Penalties</th>
                <th>Total Instalment due</th>
                <th>Amount paid</th>
                <th>Balance Due</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; $totals = array_fill_keys(['outstanding_fees','arrears_before_period','due_instalment','accrued_penalties','total_instalment_due','amount_paid','balance_due'], 0); @endphp
            @forelse($report_data as $index => $row)
                @php
                    $count++;
                    foreach ($totals as $k => $v) { $totals[$k] += (float)($row[$k] ?? 0); }
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-right">{{ number_format($row['loan_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['disbursed_date'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-center" style="font-size: 7px;">{{ $row['instalment_due_dates'] ?? '—' }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['arrears_before_period'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['due_instalment'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['accrued_penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_instalment_due'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['amount_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['balance_due'] ?? 0, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="15" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td colspan="8" class="text-center"><strong>TOTAL ({{ $count }} records)</strong></td>
                @foreach($totals as $val)
                    <td class="text-right"><strong>{{ number_format($val, 2) }}</strong></td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
