@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch_name ?? 'All');
    $reportInfo .= ' | <strong>Group:</strong> ' . ($group_name ?? 'All');
    $reportInfo .= ' | <strong>Officer:</strong> ' . ($loan_officer_name ?? 'All');
    $reportInfo .= '<br><strong>Generated:</strong> ' . ($generated_date ?? now()->format('d-m-Y H:i:s'));
    $extraStyles = 'body { font-size: 8px; } th { background: #4472C4; color: #fff; text-align: center; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Arrears Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr>
                <th>#</th><th>Customer</th><th>Customer No</th><th>Phone</th><th>Loan No</th>
                <th>Loan Amount</th><th>Disbursed Date</th><th>Branch</th><th>Group</th><th>Loan Officer</th>
                <th>Loan Fees</th><th>Penalties</th><th>Instalment in Arrears</th><th>Total Balance in Arrears</th>
                <th>Days in Arrears</th><th>First Overdue Date</th><th>No of Instalments</th><th>Severity</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; $totals = ['loan_fees'=>0,'penalties'=>0,'instalment_in_arrears'=>0,'total_balance_in_arrears'=>0]; @endphp
            @forelse($arrears_data as $index => $row)
                @php $count++; foreach ($totals as $k=>$v) { $totals[$k]+=(float)($row[$k]??0); } @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-right">{{ number_format($row['loan_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['disbursed_date'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['group'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-right">{{ number_format($row['loan_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['instalment_in_arrears'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_balance_in_arrears'] ?? 0, 2) }}</td>
                    <td class="text-center">{{ $row['days_in_arrears'] }}</td>
                    <td class="text-center">{{ $row['first_overdue_date'] }}</td>
                    <td class="text-center">{{ $row['no_of_instalments'] ?? 0 }}</td>
                    <td class="text-center">{{ $row['arrears_severity'] }}</td>
                </tr>
            @empty
                <tr><td colspan="18" class="text-center">No records found</td></tr>
            @endforelse
            @if($count)
            <tr style="background:#eee;font-weight:bold;">
                <td colspan="10" class="text-center">TOTAL ({{ $count }} records)</td>
                @foreach($totals as $val)<td class="text-right">{{ number_format($val, 2) }}</td>@endforeach
                <td colspan="4"></td>
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
