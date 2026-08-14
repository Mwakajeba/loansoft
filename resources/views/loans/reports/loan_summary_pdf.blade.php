@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch->name ?? 'All Branches');
    $reportInfo .= ' | <strong>Group:</strong> ' . ($group->name ?? 'All Groups');
    $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loanOfficer->name ?? 'All Officers');
    $reportInfo .= '<br><strong>As of Date:</strong> ' . \Carbon\Carbon::parse($asOfDate)->format('d/m/Y');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
    $extraStyles = '.title-row { background: #ffff00; font-weight: bold; text-align: center; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A4 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr class="title-row">
                <th colspan="8">LOAN REPORT</th>
            </tr>
            <tr>
                <th>Customer Name</th>
                <th>Group Name</th>
                <th>Phone Number</th>
                <th>Loan Amount</th>
                <th>Total Received</th>
                <th>Remain Balance</th>
                <th>Overdue Amount</th>
                <th>Loan End Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['customer_name'] }}</td>
                    <td>{{ $row['group_name'] }}</td>
                    <td>{{ $row['phone_number'] }}</td>
                    <td class="text-right">{{ number_format($row['loan_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_received'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['remain_balance'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['overdue_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['loan_end_date'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center">No records found</td></tr>
            @endforelse
            @if(!empty($rows))
            <tr class="total-row">
                <td colspan="3" class="text-center"><strong>TOTALS</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['loan_amount'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_received'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['remain_balance'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['overdue_amount'] ?? 0, 2) }}</strong></td>
                <td></td>
            </tr>
            @endif
        </tbody>
    </table>

@include('loans.reports.partials.pdf_report_layout_close')
