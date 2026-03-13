<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Non Performing Loan Report</title>
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
        $companyModel = isset($company) ? $company : \App\Models\Company::first();
        $logoBase64 = null;
        $logoPath = null;
        if ($companyModel && !empty($companyModel->logo)) {
            $storagePath = public_path('storage/' . $companyModel->logo);
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
        <div class="company-name">{{ $companyModel->name ?? config('app.name', 'SmartFinance') }}</div>
        @if($companyModel)
            @if($companyModel->address)<div class="company-details">{{ $companyModel->address }}</div>@endif
            <div class="company-details">
                @if($companyModel->phone)Phone: {{ $companyModel->phone }}@endif
                @if($companyModel->phone && $companyModel->email) | @endif
                @if($companyModel->email)Email: {{ $companyModel->email }}@endif
            </div>
        @endif
        <div class="report-title">Non Performing Loan (NPL) Report</div>
        <div class="report-info">
            <strong>As of Date:</strong> {{ isset($asOfDate) ? \Carbon\Carbon::parse($asOfDate)->format('d/m/Y') : \Carbon\Carbon::now()->format('d/m/Y') }}
            @if(isset($branchId) && $branchId) | <strong>Branch:</strong> {{ \App\Models\Branch::find($branchId)->name ?? 'N/A' }} @endif
            @if(isset($loanOfficerId) && $loanOfficerId) | <strong>Loan Officer:</strong> {{ \App\Models\User::find($loanOfficerId)->name ?? 'N/A' }} @endif
        </div>
        <div class="report-info"><strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 7%;">Date</th>
                <th style="width: 8%;">Branch</th>
                <th style="width: 8%;">Loan Officer</th>
                <th style="width: 6%;">Loan ID</th>
                <th style="width: 10%;">Borrower</th>
                <th style="width: 10%;">Outstanding</th>
                <th style="width: 5%;">DPD</th>
                <th style="width: 9%;">Classification</th>
                <th style="width: 6%;">Provision %</th>
                <th style="width: 10%;">Provision Amt</th>
                <th style="width: 8%;">Collateral</th>
                <th style="width: 6%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalOutstanding = 0;
                $totalProvision = 0;
                $count = 0;
            @endphp
            @if(isset($nplData) && count($nplData) > 0)
                @foreach($nplData as $index => $row)
                    @php
                        $count++;
                        $totalOutstanding += $row['outstanding'] ?? 0;
                        $totalProvision += $row['provision_amount'] ?? 0;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td class="text-center">{{ $row['date_of'] }}</td>
                        <td>{{ $row['branch'] }}</td>
                        <td>{{ $row['loan_officer'] }}</td>
                        <td class="text-center">{{ $row['loan_id'] }}</td>
                        <td>{{ $row['borrower'] }}</td>
                        <td class="text-right">{{ number_format($row['outstanding'], 0) }}</td>
                        <td class="text-center">{{ $row['dpd'] }}</td>
                        <td class="text-center">{{ $row['classification'] }}</td>
                        <td class="text-center">{{ $row['provision_percent'] }}</td>
                        <td class="text-right">{{ number_format($row['provision_amount'], 0) }}</td>
                        <td>{{ $row['collateral'] }}</td>
                        <td class="text-center">{{ $row['status'] }}</td>
                    </tr>
                @endforeach
                <!-- Total Row -->
                <tr class="total-row">
                    <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                    <td colspan="4" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                    <td class="text-right"><strong>{{ number_format($totalOutstanding, 0) }}</strong></td>
                    <td colspan="3"></td>
                    <td class="text-right"><strong>{{ number_format($totalProvision, 0) }}</strong></td>
                    <td colspan="2"></td>
                </tr>
            @else
                <tr><td colspan="13" class="text-center">No NPL records found</td></tr>
            @endif
        </tbody>
    </table>

    <!-- Footer -->
    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $companyModel->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $companyModel->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>
</body>
</html>
