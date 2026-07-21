<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Performance Report</title>
    <style>
        @page { size: A3 landscape; margin: 10mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @include('loans.reports.partials.pdf_page_shell_styles')
        body { font-size: 9px; line-height: 1.3; }
        @include('loans.reports.partials.pdf_company_header_styles')
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #000; padding: 3px 2px; text-align: left; font-size: 8px; color: #000; }
        th { background-color: #000; color: #fff; font-weight: bold; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { background-color: #f0f0f0; font-weight: bold; }
        .footer { margin-top: 15px; padding-top: 8px; border-top: 1px solid #000; text-align: center; font-size: 8px; color: #000; }
        .footer p { margin: 2px 0; }
        .digital-signature { margin-top: 5px; font-size: 7px; color: #000; font-style: italic; }
    </style>
</head>
<body>
    <div class="pdf-page-container">
    @php
        $reportInfo = '<strong>Period:</strong> ' . \Carbon\Carbon::parse($fromDate)->format('d/m/Y') . ' - ' . \Carbon\Carbon::parse($toDate)->format('d/m/Y');
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

    @include('loans.reports.partials.pdf_company_header', [
        'company' => $company,
        'reportTitle' => 'Loan Performance Report',
        'reportInfo' => $reportInfo,
    ])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 12%;">Customer</th>
                <th style="width: 7%;">Customer No</th>
                <th style="width: 9%;">Branch</th>
                <th style="width: 9%;">Group</th>
                <th style="width: 9%;">Loan Officer</th>
                <th style="width: 11%;">Outstanding</th>
                <th style="width: 8%;">Repayment Rate</th>
                <th style="width: 6%;">Arrears Days</th>
                <th style="width: 8%;">Grade</th>
                <th style="width: 8%;">Risk Category</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalOutstanding = 0;
                $count = 0;
            @endphp
            @forelse($performanceData['loans'] as $index => $loan)
                @php
                    $count++;
                    $totalOutstanding += $loan['outstanding_amount'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $loan['customer'] }}</td>
                    <td class="text-center">{{ $loan['customer_no'] }}</td>
                    <td>{{ $loan['branch'] }}</td>
                    <td>{{ $loan['group'] }}</td>
                    <td>{{ $loan['loan_officer'] }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_amount'], 0) }}</td>
                    <td class="text-center">{{ number_format($loan['repayment_rate'], 1) }}%</td>
                    <td class="text-center">{{ $loan['days_in_arrears'] }}d</td>
                    <td class="text-center">{{ $loan['performance_grade'] }}</td>
                    <td class="text-center">{{ $loan['risk_category'] }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="4" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 0) }}</strong></td>
                <td colspan="4"></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>
    </div>
</body>
</html>
