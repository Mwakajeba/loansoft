@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch->name ?? 'All Branches');
    $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loanOfficer->name ?? 'All Officers');
    $reportInfo .= '<br><strong>As of Date:</strong> ' . $asOfDate;
    $reportInfo .= ' | <strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
    $extraStyles = 'thead th { background-color: #999; color: #fff; font-weight: bold; text-align: center; }
tfoot th, tfoot td { background-color: #999; color: #fff; font-weight: bold; text-align: center; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Aging Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr>
                <th>#</th><th>Customer</th><th>Customer No</th><th>Phone</th><th>Loan No</th>
                <th>Disbursed Date</th><th>Loan Amount</th><th>Gender</th><th>Age</th><th>Subsector</th>
                <th>Outstanding principal</th><th>Days In Arrears</th>
                <th>0-5 CURRENT (1%)</th><th>6-30 ESM (5%)</th><th>31-60 SUBSTD (25%)</th>
                <th>61-90 DOUBTFUL (50%)</th><th>MORE 91 LOSS (100%)</th>
                <th>PROVISION RATE %</th><th>PROVISION AMOUNT</th>
            </tr>
        </thead>
        @if(isset($agingData) && count($agingData))
            @php $count = 0; $totals = ['loan_amount'=>0,'outstanding_principal'=>0,'bucket_current'=>0,'bucket_esm'=>0,'bucket_substandard'=>0,'bucket_doubtful'=>0,'bucket_loss'=>0,'provision_amount'=>0]; @endphp
            <tbody>
                @foreach($agingData as $index => $row)
                    @php
                        $count++;
                        foreach ($totals as $k => $v) { $totals[$k] += (float)($row[$k] ?? 0); }
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $row['customer'] }}</td>
                        <td class="text-center">{{ $row['customer_no'] }}</td>
                        <td class="text-center">{{ $row['phone'] }}</td>
                        <td class="text-center">{{ $row['loan_no'] }}</td>
                        <td class="text-center">{{ $row['disbursed_date'] }}</td>
                        <td class="text-right">{{ number_format($row['loan_amount'], 2) }}</td>
                        <td>{{ $row['gender'] ?? '' }}</td>
                        <td>{{ $row['age_category'] ?? '' }}</td>
                        <td>{{ $row['subsector'] ?? '' }}</td>
                        <td class="text-right">{{ number_format($row['outstanding_principal'], 2) }}</td>
                        <td class="text-center">{{ $row['days_in_arrears'] ?? 0 }}</td>
                        <td class="text-right">{{ number_format($row['bucket_current'], 2) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_esm'], 2) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_substandard'], 2) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_doubtful'], 2) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_loss'], 2) }}</td>
                        <td class="text-center">{{ $row['provision_rate'] ?? 0 }}%</td>
                        <td class="text-right">{{ number_format($row['provision_amount'] ?? 0, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" class="text-left">Total ({{ $count }} records)</th>
                    <th class="text-right">{{ number_format($totals['loan_amount'], 2) }}</th>
                    <th colspan="3"></th>
                    <th class="text-right">{{ number_format($totals['outstanding_principal'], 2) }}</th>
                    <th></th>
                    <th class="text-right">{{ number_format($totals['bucket_current'], 2) }}</th>
                    <th class="text-right">{{ number_format($totals['bucket_esm'], 2) }}</th>
                    <th class="text-right">{{ number_format($totals['bucket_substandard'], 2) }}</th>
                    <th class="text-right">{{ number_format($totals['bucket_doubtful'], 2) }}</th>
                    <th class="text-right">{{ number_format($totals['bucket_loss'], 2) }}</th>
                    <th></th>
                    <th class="text-right">{{ number_format($totals['provision_amount'], 2) }}</th>
                </tr>
            </tfoot>
        @else
            <tbody><tr><td colspan="19" class="text-center">No records found</td></tr></tbody>
        @endif
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
