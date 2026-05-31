<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Portfolio at Risk Report</title>
    <style>
        @page { size: A3 landscape; margin: 8mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 7px; color: #000; line-height: 1.2; }
        .header { text-align: center; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 2px solid #000; }
        .logo { max-height: 40px; margin-bottom: 4px; }
        .company-name { font-size: 14px; font-weight: bold; color: #000; margin: 2px 0; }
        .company-details { font-size: 8px; color: #000; margin: 2px 0; }
        .report-title { font-size: 11px; font-weight: bold; color: #000; margin: 6px 0 2px 0; text-transform: uppercase; }
        .report-info { font-size: 7px; color: #000; margin: 2px 0; }
        .note { font-size: 6px; color: #333; font-style: italic; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #000; padding: 2px 1px; text-align: left; font-size: 6px; color: #000; }
        th { background-color: #000; color: #fff; font-weight: bold; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { background-color: #f0f0f0; font-weight: bold; }
        .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #000; text-align: center; font-size: 7px; color: #000; }
        .footer p { margin: 2px 0; }
        .digital-signature { margin-top: 4px; font-size: 6px; color: #000; font-style: italic; }
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
        <div class="report-title">Portfolio at Risk (PAR {{ $par_days }}) Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch_name ?? 'All Branches' }} | <strong>Group:</strong> {{ $group_name ?? 'All Groups' }} | <strong>Loan Officer:</strong> {{ $loan_officer_name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>As of Date:</strong> {{ \Carbon\Carbon::parse($as_of_date)->format('d/m/Y') }} | <strong>Report Date:</strong> {{ $generated_date ?? \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
        <p class="note">Days in arrears = as-of date minus due date of the oldest instalment that is not fully paid (partial payments still count from that instalment).</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Loan No</th>
                <th>Borrower</th>
                <th>Branch</th>
                <th>Officer</th>
                <th>Product</th>
                <th>Disb.</th>
                <th>Maturity</th>
                <th>Princ. O/s</th>
                <th>Int. O/s</th>
                <th>Total O/s</th>
                <th>Inst.</th>
                <th>Due</th>
                <th>Paid</th>
                <th>Arrears</th>
                <th>DIA</th>
                <th>PAR Cat.</th>
                <th>Last Pay</th>
                <th>At risk</th>
                <th>PAR{{ $par_days }}</th>
            </tr>
        </thead>
        <tbody>
            @php
                $tp = $ti = $tt = $tinst = $tdue = $tpaid = $tarr = $tarisk = 0;
                $count = 0;
            @endphp
            @forelse($par_data as $index => $row)
                @php
                    $count++;
                    $tp += $row['principal_outstanding'] ?? 0;
                    $ti += $row['interest_outstanding'] ?? 0;
                    $tt += $row['total_outstanding'] ?? 0;
                    $tinst += $row['installment_amount'] ?? 0;
                    $tdue += $row['amount_due'] ?? 0;
                    $tpaid += $row['amount_paid'] ?? 0;
                    $tarr += $row['arrears_amount'] ?? 0;
                    $tarisk += $row['at_risk_amount'] ?? 0;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td>{{ $row['borrower_name'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td>{{ $row['loan_product'] }}</td>
                    <td class="text-center">{{ $row['disbursement_date'] }}</td>
                    <td class="text-center">{{ $row['maturity_date'] }}</td>
                    <td class="text-right">{{ number_format($row['principal_outstanding'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['interest_outstanding'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_outstanding'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['installment_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['amount_due'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['amount_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['arrears_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['days_in_arrears'] }}</td>
                    <td class="text-center">{{ $row['par_category'] }}</td>
                    <td class="text-center">{{ $row['last_payment_date'] }}</td>
                    <td class="text-right">{{ number_format($row['at_risk_amount'], 2) }}</td>
                    <td class="text-center">{{ $row['is_at_risk'] ? 'Y' : 'N' }}</td>
                </tr>
            @empty
                <tr><td colspan="20" class="text-center">No records found</td></tr>
            @endforelse
            <tr class="total-row">
                <td class="text-center" colspan="8"><strong>TOTALS ({{ $count }} loans)</strong></td>
                <td class="text-right"><strong>{{ number_format($tp, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($ti, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($tt, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($tinst, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($tdue, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($tpaid, 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($tarr, 2) }}</strong></td>
                <td class="text-center">—</td>
                <td class="text-center">—</td>
                <td class="text-center">—</td>
                <td class="text-right"><strong>{{ number_format($tarisk, 2) }}</strong></td>
                <td class="text-center"><strong>{{ $count }} loans</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>
</body>
</html>
