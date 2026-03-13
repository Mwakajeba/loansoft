<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Arrears Report</title>
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
        <div class="report-title">Loan Arrears Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch_name ?? 'All Branches' }} | <strong>Group:</strong> {{ $group_name ?? 'All Groups' }} | <strong>Loan Officer:</strong> {{ $loan_officer_name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>Report Date:</strong> {{ $generated_date ?? \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
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
                <th style="width: 7%;">Disbursed Date</th>
                <th style="width: 7%;">Branch</th>
                <th style="width: 7%;">Group</th>
                <th style="width: 8%;">Loan Officer</th>
                <th style="width: 9%;">Arrears Amount</th>
                <th style="width: 5%;">Days</th>
                <th style="width: 5%;">Items</th>
                <th style="width: 6%;">Severity</th>
            </tr>
        </thead>
        <tbody>
            @php $totalArrears = 0; $totalDays = 0; $count = 0; @endphp
            @forelse($arrears_data as $index => $row)
                @php
                    $count++;
                    $totalArrears += $row['arrears_amount'] ?? 0;
                    $totalDays += $row['days_in_arrears'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-right">{{ number_format($row['loan_amount'], 0) }}</td>
                    <td class="text-center">{{ $row['disbursed_date'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['group'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-right">{{ number_format($row['arrears_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['days_in_arrears'] }}</td>
                    <td class="text-center">{{ $row['overdue_schedules_count'] }}</td>
                    <td class="text-center">{{ $row['arrears_severity'] }}</td>
                </tr>
            @empty
                <tr><td colspan="14" class="text-center">No records found</td></tr>
            @endforelse
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="8" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalArrears, 2) }}</strong></td>
                <td class="text-center"><strong>{{ $count > 0 ? number_format($totalDays / $count, 0) : 0 }} Avg</strong></td>
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
