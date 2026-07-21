@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch_name ?? 'All Branches');
    $reportInfo .= ' | <strong>Group:</strong> ' . ($group_name ?? 'All Groups');
    $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loan_officer_name ?? 'All Officers');
    $reportInfo .= '<br><strong>As of Date:</strong> ' . \Carbon\Carbon::parse($as_of_date)->format('d/m/Y');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . ($generated_date ?? \Carbon\Carbon::now()->format('d/m/Y H:i:s'));
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Internal Portfolio Analysis Report (PAR ' . $par_days . ')',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 10%;">Customer</th>
                <th style="width: 6%;">Customer No</th>
                <th style="width: 6%;">Loan No</th>
                <th style="width: 7%;">Branch</th>
                <th style="width: 7%;">Group</th>
                <th style="width: 8%;">Outstanding</th>
                <th style="width: 8%;">Overdue</th>
                <th style="width: 8%;">At Risk</th>
                <th style="width: 6%;">Overdue %</th>
                <th style="width: 6%;">Days</th>
                <th style="width: 8%;">Risk Level</th>
                <th style="width: 8%;">Exposure</th>
                <th style="width: 6%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalOutstanding = 0;
                $totalOverdue = 0;
                $totalAtRisk = 0;
                $count = 0;
            @endphp
            @forelse($report_data as $index => $row)
                @php
                    $count++;
                    $totalOutstanding += $row['outstanding_balance'] ?? 0;
                    $totalOverdue += $row['overdue_amount'] ?? 0;
                    $totalAtRisk += $row['at_risk_amount'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['group'] }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_balance'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['overdue_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['at_risk_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['overdue_ratio'] }}%</td>
                    <td class="text-center">{{ $row['days_in_arrears'] }}</td>
                    <td class="text-center">{{ $row['risk_level'] }}</td>
                    <td class="text-center">{{ $row['exposure_category'] }}</td>
                    <td class="text-center">{{ $row['is_at_risk'] ? 'Risk' : 'Safe' }}</td>
                </tr>
            @empty
                <tr><td colspan="14" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="4" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOverdue, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalAtRisk, 2) }}</strong></td>
                <td class="text-center"><strong>{{ $totalOutstanding > 0 ? number_format(($totalOverdue / $totalOutstanding) * 100, 1) : 0 }}%</strong></td>
                <td colspan="4"></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
