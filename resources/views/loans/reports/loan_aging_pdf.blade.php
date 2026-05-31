<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Aging Report</title>
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
        thead th { background-color: #999; color: #fff; font-weight: bold; text-align: center; }
        tfoot th, tfoot td { background-color: #999; color: #fff; font-weight: bold; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
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
        <div class="report-title">Loan Aging Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch->name ?? 'All Branches' }} | <strong>Loan Officer:</strong> {{ $loanOfficer->name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>As of Date:</strong> {{ $asOfDate }} | <strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

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
</body>
</html>
