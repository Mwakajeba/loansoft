<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Repayment Schedule</title>
    <style>
        @page {
            margin: 15mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 3px solid #006400;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .logo-container {
            margin-bottom: 10px;
        }
        .logo-container img {
            max-height: 100px;
            max-width: 300px;
            display: block;
            margin-bottom: 5px;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #006400;
            margin-top: 5px;
        }
        .bank-address {
            font-size: 10px;
            margin-top: 5px;
            color: #444;
        }
        .title-section {
            text-align: right;
        }
        .title-section h2 {
            margin: 0;
            color: #006400;
            font-size: 18px;
            font-weight: bold;
        }
        .loan-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            border: 2px solid #006400;
            border-left: 4px solid #006400;
            padding: 12px;
            background: #f9fafb;
        }
        .loan-details-left,
        .loan-details-right {
            width: 48%;
        }
        .loan-details-left p,
        .loan-details-right p {
            margin: 5px 0;
            font-size: 11px;
        }
        .loan-details-left p strong,
        .loan-details-right p strong {
            display: inline-block;
            width: 140px;
        }
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10px;
        }
        .schedule-table th {
            background-color: #006400;
            color: #fff;
            border: 1px solid #006400;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
        }
        .schedule-table td {
            border: 1px solid #000;
            padding: 6px 5px;
            text-align: right;
        }
        .schedule-table td:first-child {
            text-align: center;
        }
        .schedule-table tfoot td {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: right;
        }
        .schedule-table tfoot td:first-child {
            text-align: center;
        }
        .footer {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .loan-officer {
            font-size: 10px;
        }
        .stamp {
            text-align: center;
            border: 2px solid #006400;
            border-radius: 50%;
            width: 100px;
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            padding: 5px;
            box-sizing: border-box;
        }
        .stamp-content {
            text-align: center;
        }
    </style>
</head>
<body>
    @php
        $company = $company ?? null;
        $scheduleSorted = $loan->schedule->sortBy('due_date')->values();
        $totalPrincipal = $scheduleSorted->sum('principal');
        $totalInterest = $scheduleSorted->sum('interest');
        $totalInstallment = $totalPrincipal + $totalInterest;
    @endphp
    <div class="header">
        <div>
            <div class="logo-container">
                @php
                    $logoPath = null;
                    if ($company && !empty($company->logo)) {
                        $companyLogoPath = storage_path('app/public/' . ltrim($company->logo, '/'));
                        if (file_exists($companyLogoPath)) {
                            $logoPath = $companyLogoPath;
                        } else {
                            $companyLogoPath = public_path('storage/' . ltrim($company->logo, '/'));
                            if (file_exists($companyLogoPath)) {
                                $logoPath = $companyLogoPath;
                            }
                        }
                    }
                    if (!$logoPath && file_exists(public_path('assets/images/logo.png'))) {
                        $logoPath = public_path('assets/images/logo.png');
                    }
                    if (!$logoPath && file_exists(public_path('assets/images/logo-img.png'))) {
                        $logoPath = public_path('assets/images/logo-img.png');
                    }
                    $logoBase64 = null;
                    if ($logoPath && file_exists($logoPath)) {
                        $logoData = @file_get_contents($logoPath);
                        if ($logoData !== false) {
                            $mime = @mime_content_type($logoPath) ?: 'image/png';
                            $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($logoData);
                        }
                    }
                @endphp
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="{{ optional($company)->name ?? 'Logo' }}" />
                @endif
                <div class="company-name">{{ optional($company)->name ?? config('app.name', 'SmartFinance') }}</div>
                @if($company && (trim(optional($company)->address ?? '') !== '' || trim(optional($company)->phone ?? '') !== '' || trim(optional($company)->email ?? '') !== ''))
                    <div class="bank-address" style="font-size: 10px; margin-top: 5px; color: #444;">
                        @if(!empty($company->address)){{ $company->address }}<br>@endif
                        @if(!empty($company->phone))Tel: {{ $company->phone }}@endif
                        @if(!empty($company->phone) && !empty($company->email)) | @endif
                        @if(!empty($company->email)){{ $company->email }}@endif
                    </div>
                @endif
            </div>
        </div>
        <div class="title-section">
            <h2>Loan Repayment Schedule</h2>
        </div>
    </div>

    <div class="loan-details">
        <div class="loan-details-left">
            <p><strong>Branch:</strong> {{ optional($loan->branch)->name ?? optional($loan->branch)->branch_id ?? 'N/A' }}</p>
            <p><strong>Account Number:</strong> {{ $loan->loanNo ?? 'N/A' }}</p>
            <p><strong>Customer Name:</strong> {{ optional($loan->customer)->name ?? 'N/A' }}</p>
        </div>
        <div class="loan-details-right">
            <p><strong>Loan Amount:</strong> {{ number_format($loan->amount ?? 0, 2) }}</p>
            <p><strong>Term:</strong> {{ (int) $loan->period }} {{ ($loan->period ?? 0) == 1 ? 'Month' : 'Months' }}</p>
            <p><strong>Interest Rate:</strong> {{ number_format($loan->interest ?? 0, 2) }}%</p>
            <p><strong>Interest Method:</strong>
                @php
                    $interestMethod = optional($loan->product)->interest_method ?? '';
                    $method = strtolower($interestMethod);
                    $methodLabel = match($method) {
                        'reducing_balance_with_equal_installment' => 'Reducing Balance with Equal Installment',
                        'reducing_balance_with_equal_principal' => 'Reducing Balance with Equal Principal',
                        'flat_rate' => 'Flat Rate',
                        default => $interestMethod ?: 'N/A'
                    };
                @endphp
                {{ $methodLabel }}
            </p>
            <p><strong>Value Date:</strong> {{ $loan->disbursed_on ? \Carbon\Carbon::parse($loan->disbursed_on)->format('d/m/Y') : 'N/A' }}</p>
        </div>
    </div>

    <table class="schedule-table">
        <thead>
            <tr>
                <th>Schedule Due Date</th>
                <th>Principal Amount</th>
                <th>Interest Amount</th>
                <th>Installment Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($scheduleSorted as $schedule)
            <tr>
                <td>{{ \Carbon\Carbon::parse($schedule->due_date)->format('d/m/Y') }}</td>
                <td>{{ number_format((float) ($schedule->principal ?? 0), 2) }}</td>
                <td>{{ number_format((float) ($schedule->interest ?? 0), 2) }}</td>
                <td>{{ number_format((float) ($schedule->principal ?? 0) + (float) ($schedule->interest ?? 0), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td><strong>{{ number_format($totalPrincipal, 2) }}</strong></td>
                <td><strong>{{ number_format($totalInterest, 2) }}</strong></td>
                <td><strong>{{ number_format($totalInstallment, 2) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <div class="loan-officer">
            <p><strong>Loan Officer:</strong> {{ optional($loan->loanOfficer)->name ?? 'N/A' }}</p>
        </div>
        <!-- <div class="stamp">
            <div class="stamp-content">
                CURRENT COMPANY LTD<br>
                {{ $loan->branch->name ?? 'Cluster' }}<br>
                {{ $loan->branch->branch_id ?? 'N/A' }}, {{ $loan->branch->location ?? 'Location' }}
            </div>
        </div> -->
    </div>
</body>
</html>

