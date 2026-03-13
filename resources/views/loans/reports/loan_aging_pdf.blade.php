<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Aging Report</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
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
        <div class="report-title">Loan Aging Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch->name ?? 'All Branches' }} | <strong>Loan Officer:</strong> {{ $loanOfficer->name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>As of Date:</strong> {{ $asOfDate }} | <strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 10%;">Customer</th>
                <th style="width: 6%;">Customer No</th>
                <th style="width: 7%;">Phone</th>
                <th style="width: 6%;">Loan No</th>
                <th style="width: 8%;">Loan Amount</th>
                <th style="width: 8%;">Outstanding</th>
                <th style="width: 6%;">Disbursed</th>
                <th style="width: 6%;">Expiry</th>
                <th style="width: 7%;">Branch</th>
                <th style="width: 7%;">Officer</th>
                <th style="width: 5%;">Current</th>
                <th style="width: 5%;">1-30</th>
                <th style="width: 5%;">31-60</th>
                <th style="width: 5%;">61-90</th>
                <th style="width: 5%;">91+</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalAmount = 0; $totalOutstanding = 0; $totalCurrent = 0;
                $total1_30 = 0; $total31_60 = 0; $total61_90 = 0; $total91Plus = 0;
                $count = 0;
            @endphp
            @forelse($agingData as $index => $row)
                @php
                    $count++;
                    $totalAmount += $row['amount'] ?? 0;
                    $totalOutstanding += $row['outstanding_balance'] ?? 0;
                    $totalCurrent += $row['current'] ?? 0;
                    $total1_30 += $row['bucket_1_30'] ?? 0;
                    $total31_60 += $row['bucket_31_60'] ?? 0;
                    $total61_90 += $row['bucket_61_90'] ?? 0;
                    $total91Plus += $row['bucket_91_plus'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-right">{{ number_format($row['amount'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_balance'], 0) }}</td>
                    <td class="text-center">{{ $row['disbursed_no'] }}</td>
                    <td class="text-center">{{ $row['expiry'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-right">{{ number_format($row['current'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['bucket_1_30'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['bucket_31_60'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['bucket_61_90'], 0) }}</td>
                    <td class="text-right">{{ number_format($row['bucket_91_plus'], 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="16" class="text-center">No records found</td></tr>
            @endforelse
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="3" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalAmount, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 0) }}</strong></td>
                <td colspan="4"></td>
                <td class="text-right"><strong>{{ number_format($totalCurrent, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total1_30, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total31_60, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total61_90, 0) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total91Plus, 0) }}</strong></td>
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
