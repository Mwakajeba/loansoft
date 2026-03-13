<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Portfolio Tracking Report</title>
    <style>
        @page { size: A3 landscape; margin: 10mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 9px; color: #000; line-height: 1.3; }
        .header { text-align: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #000; }
        .logo { max-height: 50px; margin-bottom: 5px; }
        .company-name { font-size: 16px; font-weight: bold; color: #000; margin: 3px 0; }
        .company-details { font-size: 9px; color: #000; margin: 2px 0; }
        .report-title { font-size: 12px; font-weight: bold; color: #000; margin: 8px 0 3px 0; text-transform: uppercase; }
        .report-info { font-size: 9px; color: #000; margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #000; padding: 3px 2px; text-align: left; font-size: 7px; color: #000; }
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
    @php
        $logoBase64 = null;
        $logoPath = null;
        if (isset($company) && $company && !empty($company->logo)) {
            $storagePath = public_path('storage/' . $company->logo);
            if (file_exists($storagePath)) { $logoPath = $storagePath; }
        }
        if (!$logoPath && file_exists(public_path('assets/images/logo-img.png'))) {
            $logoPath = public_path('assets/images/logo-img.png');
        }
        if ($logoPath && file_exists($logoPath)) {
            $logoType = pathinfo($logoPath, PATHINFO_EXTENSION);
            $logoData = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $logoType . ';base64,' . base64_encode($logoData);
        }
    @endphp

    <!-- Header -->
    <div class="header">
        @if($logoBase64)<img src="{{ $logoBase64 }}" alt="Logo" class="logo">@endif
        <div class="company-name">{{ $company->name ?? config('app.name', 'SmartFinance') }}</div>
        @if(isset($company) && $company)
            @if($company->address)<div class="company-details">{{ $company->address }}</div>@endif
            <div class="company-details">
                @if($company->phone)Phone: {{ $company->phone }}@endif
                @if($company->phone && $company->email) | @endif
                @if($company->email)Email: {{ $company->email }}@endif
            </div>
        @endif
        <div class="report-title">Loan Portfolio Tracking Report</div>
        <div class="report-info">
            <strong>Period:</strong> {{ \Carbon\Carbon::parse($fromDate)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($toDate)->format('d/m/Y') }}
            @if($groupBy !== 'day') | <strong>Grouped by:</strong> {{ ucfirst($groupBy) }} @endif
        </div>
        <div class="report-info"><strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 2%;">S/N</th>
                <th style="width: 5%;">Group</th>
                @if($groupBy !== 'day')
                <th style="width: 5%;">Date Range</th>
                @endif
                <th style="width: 7%;">Customer</th>
                <th style="width: 6%;">Loan Officer</th>
                <th style="width: 6%;">Product</th>
                <th style="width: 5%;">Loan No</th>
                <th style="width: 5%;">Disb Date</th>
                <th style="width: 5%;">Maturity</th>
                <th style="width: 6%;">Disbursed</th>
                <th style="width: 5%;">Interest</th>
                <th style="width: 6%;">Total Amt</th>
                <th style="width: 5%;">Prin Paid</th>
                <th style="width: 5%;">Int Paid</th>
                <th style="width: 4%;">Pen Paid</th>
                <th style="width: 5%;">Out Prin</th>
                <th style="width: 5%;">Out Int</th>
                <th style="width: 5%;">Overdue</th>
                <th style="width: 3%;">Days</th>
                <th style="width: 4%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalDisbursed = 0;
                $totalInterest = 0;
                $totalAmount = 0;
                $totalPrincipalPaid = 0;
                $totalInterestPaid = 0;
                $totalPenaltiesPaid = 0;
                $totalOutPrincipal = 0;
                $totalOutInterest = 0;
                $totalOverdue = 0;
                $count = 0;
            @endphp
            @forelse($rows as $index => $r)
                @if(!isset($r['is_summary']) || !$r['is_summary'])
                    @php
                        $count++;
                        $totalDisbursed += $r['amount_disbursed'] ?? 0;
                        $totalInterest += $r['interest'] ?? 0;
                        $totalAmount += $r['total_amount'] ?? 0;
                        $totalPrincipalPaid += $r['principal_paid'] ?? 0;
                        $totalInterestPaid += $r['interest_paid'] ?? 0;
                        $totalPenaltiesPaid += $r['penalties_paid'] ?? 0;
                        $totalOutPrincipal += $r['outstanding_principal'] ?? 0;
                        $totalOutInterest += $r['outstanding_interest'] ?? 0;
                        $totalOverdue += $r['amount_overdue'] ?? 0;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $count }}</td>
                        <td>{{ $r['group'] }}</td>
                        @if($groupBy !== 'day')
                        <td class="text-center">{{ $r['date_range'] ?? '' }}</td>
                        @endif
                        <td>{{ $r['customer_name'] }}</td>
                        <td>{{ $r['loan_officer'] }}</td>
                        <td>{{ $r['loan_product'] }}</td>
                        <td class="text-center">{{ $r['loan_account_no'] }}</td>
                        <td class="text-center">{{ $r['disbursement_date'] }}</td>
                        <td class="text-center">{{ $r['maturity_date'] }}</td>
                        <td class="text-right">{{ number_format($r['amount_disbursed'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['interest'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['total_amount'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['principal_paid'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['interest_paid'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['penalties_paid'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['outstanding_principal'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['outstanding_interest'], 0) }}</td>
                        <td class="text-right">{{ number_format($r['amount_overdue'], 0) }}</td>
                        <td class="text-center">{{ $r['days_in_arrears'] }}</td>
                        <td class="text-center">{{ ucfirst($r['loan_status']) }}</td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="{{ $groupBy !== 'day' ? 20 : 19 }}" class="text-center">No records found</td></tr>
            @endforelse
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                @if($groupBy !== 'day')
                <td></td>
                @endif
                <td colspan="6" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalDisbursed, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalInterest, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalAmount, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalPrincipalPaid, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalInterestPaid, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalPenaltiesPaid, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutPrincipal, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutInterest, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOverdue, 0) }}</strong></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <!-- Footer -->
    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>
</body>
</html>
