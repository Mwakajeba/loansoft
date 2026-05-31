<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Aging Installment Report</title>
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
        <div class="report-title">Loan Aging Installment Report</div>
        <div class="report-info"><strong>Branch:</strong> {{ $branch->name ?? 'All Branches' }} | <strong>Loan Officer:</strong> {{ $loanOfficer->name ?? 'All Officers' }}</div>
        <div class="report-info"><strong>As of Date:</strong> {{ $asOfDate }} | <strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">#</th>
                <th style="width: 9%;">Customer</th>
                <th style="width: 5%;">Customer No</th>
                <th style="width: 6%;">Phone</th>
                <th style="width: 5%;">Loan No</th>
                <th style="width: 7%;">Loan Amount</th>
                <th style="width: 7%;">Outstanding principal</th>
                <th style="width: 6%;">Disbursed</th>
                <th style="width: 6%;">Expiry</th>
                <th style="width: 7%;">Branch</th>
                <th style="width: 7%;">Officer</th>
                <th style="width: 5%;">Days in arrears</th>
                <th style="width: 5%;">Current (0–5)</th>
                <th style="width: 5%;">ESM (6–30)</th>
                <th style="width: 5%;">Substd (31–60)</th>
                <th style="width: 5%;">Doubtful (61–90)</th>
                <th style="width: 5%;">Loss (90+)</th>
                <th style="width: 6%;">Total overdue princ.</th>
            </tr>
        </thead>
        @if(isset($agingData) && count($agingData))
            @php
                $totalAmount = $totalOutstandingPrincipal = $total0_5 = $total6_30 = $total31_60 = $total61_90 = $total90Plus = $totalOverdue = 0;
                $count = 0;
            @endphp
            <tbody>
                @foreach($agingData as $index => $row)
                    @php
                        $count++;
                        $totalAmount += $row['amount'] ?? 0;
                        $totalOutstandingPrincipal += $row['outstanding_principal'] ?? 0;
                        $total0_5 += $row['bucket_0_5'] ?? 0;
                        $total6_30 += $row['bucket_6_30'] ?? 0;
                        $total31_60 += $row['bucket_31_60'] ?? 0;
                        $total61_90 += $row['bucket_61_90'] ?? 0;
                        $total90Plus += $row['bucket_90_plus'] ?? 0;
                        $totalOverdue += $row['total_overdue'] ?? 0;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $row['customer'] }}</td>
                        <td class="text-center">{{ $row['customer_no'] }}</td>
                        <td class="text-center">{{ $row['phone'] }}</td>
                        <td class="text-center">{{ $row['loan_no'] }}</td>
                        <td class="text-right">{{ number_format($row['amount'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['outstanding_principal'], 0) }}</td>
                        <td class="text-center">{{ $row['disbursed_no'] }}</td>
                        <td class="text-center">{{ $row['expiry'] }}</td>
                        <td>{{ $row['branch'] }}</td>
                        <td>{{ $row['loan_officer'] }}</td>
                        <td class="text-center">{{ number_format($row['days_in_arrears'] ?? 0, 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_0_5'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_6_30'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_31_60'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_61_90'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['bucket_90_plus'], 0) }}</td>
                        <td class="text-right">{{ number_format($row['total_overdue'], 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" style="text-align: left;">Total ({{ $count }} {{ $count === 1 ? 'record' : 'records' }})</th>
                    <th class="text-right">{{ number_format($totalAmount, 0) }}</th>
                    <th class="text-right">{{ number_format($totalOutstandingPrincipal, 0) }}</th>
                    <th colspan="5"></th>
                    <th class="text-right">{{ number_format($total0_5, 0) }}</th>
                    <th class="text-right">{{ number_format($total6_30, 0) }}</th>
                    <th class="text-right">{{ number_format($total31_60, 0) }}</th>
                    <th class="text-right">{{ number_format($total61_90, 0) }}</th>
                    <th class="text-right">{{ number_format($total90Plus, 0) }}</th>
                    <th class="text-right">{{ number_format($totalOverdue, 0) }}</th>
                </tr>
            </tfoot>
        @else
            <tbody>
                <tr><td colspan="18" class="text-center">No records found</td></tr>
            </tbody>
        @endif
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>
</body>
</html>
