<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Outstanding Balance Report</title>
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
        th, td { border: 1px solid #000; padding: 2px 1px; text-align: left; font-size: 6px; color: #000; }
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
        <div class="report-title">Loan Outstanding Balance Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch->name ?? 'All Branches' }} | <strong>Loan Officer:</strong> {{ $loanOfficer->name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>As of Date:</strong> {{ \Carbon\Carbon::parse($asOfDate)->format('d/m/Y') }} | <strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th>Customer</th><th>Cust No</th><th>Phone</th><th>Loan No</th><th>Expires</th>
                <th>Branch</th><th>Officer</th><th>Disb Date</th><th>Disb Amt</th><th>Tot Int</th>
                <th>Tot P+I</th><th>Exp Fees</th><th>Tot Pen</th>
                <th>Princ Paid</th><th>Int Paid</th><th>Fees Paid</th><th>Pen Paid</th>
                <th>O/s Princ</th><th>O/s Int</th><th>O/s Fees</th><th>O/s Pen</th><th>Other</th><th>O/s Bal</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; @endphp
            @forelse($outstandingData as $index => $row)
                @php $count++; @endphp
                <tr>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-center">{{ $row['expires'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-center">{{ $row['disbursed_date'] }}</td>
                    <td class="text-right">{{ number_format($row['disbursed_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_principal_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['expected_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['principal_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['interest_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['fees_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['penalty_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_principal'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_penalty'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['other_outstanding'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="23" class="text-center">No records found</td></tr>
            @endforelse
            @if(!empty($outstandingData))
            <tr class="total-row">
                <td colspan="8" class="text-center"><strong>TOTALS</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_disbursed'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_interest'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_principal_interest'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_expected_fees'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_penalties'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_principal_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_interest_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_fees_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_penalty_paid'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_principal'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_interest'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_fees'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_penalty'] ?? 0, 2) }}</strong></td>
                <td class="text-right"><strong>0.00</strong></td>
                <td class="text-right"><strong>{{ number_format($summary['total_outstanding_balance'] ?? 0, 2) }}</strong></td>
            </tr>
            @endif
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
