<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Loan Performance Report</title>
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
        th, td { border: 1px solid #000; padding: 4px 3px; text-align: left; font-size: 9px; color: #000; }
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
        <div class="report-title">Monthly Loan Performance Report</div>
        <div class="report-info"><strong>Period:</strong> {{ ($startDate && $endDate) ? ($startDate.' - '.$endDate) : 'All Time' }} | <strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 12%;">Month</th>
                <th style="width: 13%;">Loan Given</th>
                <th style="width: 13%;">Interest</th>
                <th style="width: 13%;">Total Loan + Interest</th>
                <th style="width: 13%;">Total Collected</th>
                <th style="width: 13%;">Outstanding</th>
                <th style="width: 13%;">Actual Interest Collected</th>
                <th style="width: 7%;">Performance %</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalLoanGiven = 0;
                $totalInterest = 0;
                $totalLoan = 0;
                $totalCollected = 0;
                $totalOutstanding = 0;
                $totalActualInterest = 0;
                $count = 0;
            @endphp
            @forelse($rows as $index => $r)
                @php
                    $count++;
                    $totalLoanGiven += $r['loan_given'] ?? 0;
                    $totalInterest += $r['interest'] ?? 0;
                    $totalLoan += $r['total_loan'] ?? 0;
                    $totalCollected += $r['collected'] ?? 0;
                    $totalOutstanding += $r['outstanding'] ?? 0;
                    $totalActualInterest += $r['actual_interest_collected'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $r['month'] }}</td>
                    <td class="text-right">{{ number_format($r['loan_given'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['total_loan'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['collected'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['outstanding'], 2) }}</td>
                    <td class="text-right">{{ number_format($r['actual_interest_collected'], 2) }}</td>
                    <td class="text-center">{{ number_format($r['performance'], 2) }}%</td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center">No records found</td></tr>
            @endforelse
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td class="text-right"><strong>{{ number_format($totalLoanGiven, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalInterest, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalLoan, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalCollected, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalOutstanding, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalActualInterest, 2) }}</strong></td>
                <td class="text-center"><strong>{{ $totalLoan > 0 ? number_format(($totalCollected / $totalLoan) * 100, 2) : 0 }}%</strong></td>
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
