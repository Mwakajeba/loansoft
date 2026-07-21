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
    'reportTitle' => 'Loan Aging Installment Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A4 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 9%;">Customer</th>
                <th style="width: 5%;">Customer No</th>
                <th style="width: 6%;">Phone</th>
                <th style="width: 5%;">Loan No</th>
                <th style="width: 7%;">Loan Amount</th>
                <th style="width: 7%;">Outstanding principal</th>
                <th style="width: 6%;">Disbursed</th>
                <th style="width: 6%;">Expiry</th>
                <th style="width: 7%;">Branch</th>
                <th style="width: 7%;">Officer</th>
                <th style="width: 5%;">Days in arrears</th>
                <th style="width: 5%;">Current (0–5)</th>
                <th style="width: 5%;">ESM (6–30)</th>
                <th style="width: 5%;">Substd (31–60)</th>
                <th style="width: 5%;">Doubtful (61–90)</th>
                <th style="width: 5%;">Loss (90+)</th>
                <th style="width: 6%;">Total overdue princ.</th>
            </tr>
        </thead>
        @if(isset($agingData) && count($agingData))
            @php
                $totalAmount = $totalOutstandingPrincipal = $total0_5 = $total6_30 = $total31_60 = $total61_90 = $total90Plus = $totalOverdue = 0;
                $count = 0;
            @endphp
            <tbody>
                @foreach($agingData as $index => $row)
                    @php
                        $count++;
                        $totalAmount += $row['amount'] ?? 0;
                        $totalOutstandingPrincipal += $row['outstanding_principal'] ?? 0;
                        $total0_5 += $row['bucket_0_5'] ?? 0;
                        $total6_30 += $row['bucket_6_30'] ?? 0;
                        $total31_60 += $row['bucket_31_60'] ?? 0;
                        $total61_90 += $row['bucket_61_90'] ?? 0;
                        $total90Plus += $row['bucket_90_plus'] ?? 0;
                        $totalOverdue += $row['total_overdue'] ?? 0;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $row['customer'] }}</td>
                        <td class="text-center">{{ $row['customer_no'] }}</td>
                        <td class="text-center">{{ $row['phone'] }}</td>
                        <td class="text-center">{{ $row['loan_no'] }}</td>
                        <td class="text-right">{{ number_format($row['amount'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['outstanding_principal'], 0) }}</td>
                        <td class="text-center">{{ $row['disbursed_no'] }}</td>
                        <td class="text-center">{{ $row['expiry'] }}</td>
                        <td>{{ $row['branch'] }}</td>
                        <td>{{ $row['loan_officer'] }}</td>
                        <td class="text-center">{{ number_format($row['days_in_arrears'] ?? 0, 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_0_5'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_6_30'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_31_60'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_61_90'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_90_plus'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['total_overdue'], 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" style="text-align: left;">Total ({{ $count }} {{ $count === 1 ? 'record' : 'records' }})</th>
                    <th class="text-right">{{ number_format($totalAmount, 0) }}</th>
                    <th class="text-right">{{ number_format($totalOutstandingPrincipal, 0) }}</th>
                    <th colspan="5"></th>
                    <th class="text-right">{{ number_format($total0_5, 0) }}</th>
                    <th class="text-right">{{ number_format($total6_30, 0) }}</th>
                    <th class="text-right">{{ number_format($total31_60, 0) }}</th>
                    <th class="text-right">{{ number_format($total61_90, 0) }}</th>
                    <th class="text-right">{{ number_format($total90Plus, 0) }}</th>
                    <th class="text-right">{{ number_format($totalOverdue, 0) }}</th>
                </tr>
            </tfoot>
        @else
            <tbody>
                <tr><td colspan="18" class="text-center">No records found</td></tr>
            </tbody>
        @endif
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
