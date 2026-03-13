<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Portfolio at Risk Report</title>
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
        <div class="report-title">Portfolio at Risk (PAR {{ $par_days }}) Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch_name ?? 'All Branches' }} | <strong>Group:</strong> {{ $group_name ?? 'All Groups' }} | <strong>Loan Officer:</strong> {{ $loan_officer_name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>As of Date:</strong> {{ \Carbon\Carbon::parse($as_of_date)->format('d/m/Y') }} | <strong>Report Date:</strong> {{ $generated_date ?? \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 10%;">Customer</th>
                <th style="width: 6%;">Customer No</th>
                <th style="width: 6%;">Phone</th>
                <th style="width: 6%;">Loan No</th>
                <th style="width: 7%;">Loan Amount</th>
                <th style="width: 6%;">Branch</th>
                <th style="width: 6%;">Group</th>
                <th style="width: 7%;">Officer</th>
                <th style="width: 8%;">Outstanding</th>
                <th style="width: 8%;">At Risk</th>
                <th style="width: 5%;">Risk %</th>
                <th style="width: 4%;">Days</th>
                <th style="width: 6%;">Risk Level</th>
                <th style="width: 5%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @php $totalOutstanding = 0; $totalAtRisk = 0; $count = 0; @endphp
            @forelse($par_data as $index => $row)
                @php
                    $count++;
                    $totalOutstanding += $row['outstanding_balance'] ?? 0;
                    $totalAtRisk += $row['at_risk_amount'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-right">{{ number_format($row['loan_amount'], 0) }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['group'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_balance'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['at_risk_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['risk_percentage'] }}%</td>
                    <td class="text-center">{{ $row['days_in_arrears'] }}</td>
                    <td class="text-center">{{ $row['risk_level'] }}</td>
                    <td class="text-center">{{ $row['is_at_risk'] ? 'Risk' : 'Safe' }}</td>
                </tr>
            @empty
                <tr><td colspan="15" class="text-center">No records found</td></tr>
            @endforelse
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="7" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalAtRisk, 2) }}</strong></td>
                <td class="text-center"><strong>{{ $totalOutstanding > 0 ? number_format(($totalAtRisk / $totalOutstanding) * 100, 1) : 0 }}%</strong></td>
                <td colspan="3"></td>
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
