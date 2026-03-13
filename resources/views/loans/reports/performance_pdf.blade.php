<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Performance Report</title>
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
        <div class="report-title">Loan Performance Report</div>
        <div class="report-info">
            <strong>Period:</strong> {{ \Carbon\Carbon::parse($fromDate)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($toDate)->format('d/m/Y') }}
            @if($branchId) | <strong>Branch:</strong> {{ $branches->find($branchId)->name ?? 'N/A' }} @endif
            @if($groupId) | <strong>Group:</strong> {{ $groups->find($groupId)->name ?? 'N/A' }} @endif
            @if($loanOfficerId) | <strong>Loan Officer:</strong> {{ $loanOfficers->find($loanOfficerId)->name ?? 'N/A' }} @endif
        </div>
        <div class="report-info"><strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
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
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="4" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 0) }}</strong></td>
                <td colspan="4"></td>
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
