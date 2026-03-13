<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Disbursement Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #000;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #000;
        }
        .logo {
            max-height: 60px;
            margin-bottom: 5px;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #000;
            margin: 5px 0;
        }
        .company-details {
            font-size: 10px;
            color: #000;
            margin: 2px 0;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            margin: 10px 0 5px 0;
            text-transform: uppercase;
        }
        .report-info {
            font-size: 10px;
            color: #000;
            margin: 3px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #000;
            padding: 4px 3px;
            text-align: left;
            font-size: 9px;
            color: #000;
        }
        th {
            background-color: #000;
            color: #fff;
            font-weight: bold;
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .total-row {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #000;
            text-align: center;
            font-size: 9px;
            color: #000;
        }
        .footer p {
            margin: 3px 0;
        }
        .digital-signature {
            margin-top: 10px;
            font-size: 8px;
            color: #000;
            font-style: italic;
        }
    </style>
</head>
<body>
    @php
        $logoBase64 = null;
        $logoPath = null;
        if (isset($company) && $company && !empty($company->logo)) {
            $storagePath = public_path('storage/' . $company->logo);
            if (file_exists($storagePath)) {
                $logoPath = $storagePath;
            }
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
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" alt="Logo" class="logo">
        @endif
        <div class="company-name">{{ $company->name ?? config('app.name', 'SmartFinance') }}</div>
        @if(isset($company) && $company)
            @if($company->address)
                <div class="company-details">{{ $company->address }}</div>
            @endif
            <div class="company-details">
                @if($company->phone)Phone: {{ $company->phone }}@endif
                @if($company->phone && $company->email) | @endif
                @if($company->email)Email: {{ $company->email }}@endif
            </div>
        @endif
        <div class="report-title">Loan Disbursement Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch->name ?? 'All Branches' }}</div>
        <div class="report-info"><strong>Period:</strong> {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}</div>
        <div class="report-info"><strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 8%;">Customer No</th>
                <th style="width: 10%;">Customer Name</th>
                <th style="width: 7%;">Loan No</th>
                <th style="width: 8%;">Product</th>
                <th style="width: 6%;">Group</th>
                <th style="width: 7%;">Loan Officer</th>
                <th style="width: 7%;">Disbursed Date</th>
                <th style="width: 5%;">Period</th>
                <th style="width: 7%;">End Date</th>
                <th style="width: 8%;">Disbursed Amount</th>
                <th style="width: 8%;">Interest Amount</th>
                <th style="width: 8%;">Total Amount</th>
                <th style="width: 5%;">Rate (%)</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalDisbursed = 0;
                $totalInterest = 0;
                $totalAmount = 0;
                $count = 0;
            @endphp
            @forelse($disbursements as $index => $loan)
                @php
                    $count++;
                    $totalDisbursed += $loan->amount ?? 0;
                    $totalInterest += $loan->interest_amount ?? 0;
                    $totalAmount += $loan->amount_total ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center">{{ $loan->customer->customerNo ?? 'N/A' }}</td>
                    <td>{{ $loan->customer->name ?? 'N/A' }}</td>
                    <td class="text-center">{{ $loan->loanNo ?? 'N/A' }}</td>
                    <td>{{ $loan->product->name ?? 'N/A' }}</td>
                    <td>{{ $loan->group->name ?? 'N/A' }}</td>
                    <td>{{ $loan->loanOfficer->name ?? 'N/A' }}</td>
                    <td class="text-center">{{ $loan->disbursed_on ? \Carbon\Carbon::parse($loan->disbursed_on)->format('d/m/Y') : 'N/A' }}</td>
                    <td class="text-center">{{ $loan->period ?? 'N/A' }}</td>
                    <td class="text-center">{{ $loan->last_repayment_date ? \Carbon\Carbon::parse($loan->last_repayment_date)->format('d/m/Y') : 'N/A' }}</td>
                    <td class="text-right">{{ number_format($loan->amount ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan->interest_amount ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan->amount_total ?? 0, 2) }}</td>
                    <td class="text-center">{{ number_format($loan->interest ?? 0, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="14" class="text-center">No records found</td>
                </tr>
            @endforelse
            <!-- Total Row -->
            <tr class="total-row">
                <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                <td colspan="8" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                <td class="text-right"><strong>{{ number_format($totalDisbursed, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalInterest, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($totalAmount, 2) }}</strong></td>
                <td></td>
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
