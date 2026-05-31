<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Portfolio Report</title>
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
        <div class="report-title">Loan Portfolio Report</div>
        <div class="report-info">
            <strong>As of Date:</strong> {{ \Carbon\Carbon::parse($asOfDate)->format('d/m/Y') }}
            @if($branchId) | <strong>Branch:</strong> {{ $branches->find($branchId)->name ?? 'N/A' }} @endif
            @if($groupId) | <strong>Group:</strong> {{ $groups->find($groupId)->name ?? 'N/A' }} @endif
            @if($loanOfficerId) | <strong>Loan Officer:</strong> {{ $loanOfficers->find($loanOfficerId)->name ?? 'N/A' }} @endif
        </div>
        <div class="report-info"><strong>Report Date:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</div>
    </div>

    <!-- Summary -->
    @if(isset($portfolioData['summary']))
    <table style="margin-bottom:10px;">
        <tr><th colspan="8" style="background:#D9D9D9;">PORTFOLIO SUMMARY</th></tr>
        <tr>
            <th>Total Loans</th><th>Active</th><th>Completed</th><th>Total Outstanding</th>
            <th>Total Paid</th><th>Repayment Rate</th><th>Portfolio at Risk</th><th>PAR Ratio</th>
        </tr>
        <tr>
            <td class="text-center">{{ number_format($portfolioData['summary']['total_loans']) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['active_loans']) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['completed_loans']) }}</td>
            <td class="text-right">{{ number_format($portfolioData['summary']['total_outstanding'], 2) }}</td>
            <td class="text-right">{{ number_format($portfolioData['summary']['total_paid'], 2) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['overall_repayment_rate'], 2) }}%</td>
            <td class="text-right">{{ number_format($portfolioData['summary']['portfolio_at_risk'], 2) }}</td>
            <td class="text-center">{{ number_format($portfolioData['summary']['par_ratio'], 2) }}%</td>
        </tr>
    </table>
    @endif

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th>Customer Name</th><th>Customer No</th><th>Phone</th><th>Gender</th><th>Tenure</th><th>Subsector</th>
                <th>Loan Officer</th><th>Status</th><th>Disbursed Date</th><th>Disbursed Amount</th>
                <th>Mgmt Fees Bal</th><th>Outstanding Principal</th><th>Outstanding Interest</th>
                <th>Days in Arrears</th><th>Accrued Penalties</th><th>Outstanding Balance</th>
                <th>Repayment Rate</th><th>Maturity Date</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; @endphp
            @forelse($portfolioData['loans'] as $index => $loan)
                @php $count++; @endphp
                <tr>
                    <td>{{ $loan['customer'] }}</td>
                    <td class="text-center">{{ $loan['customer_no'] }}</td>
                    <td class="text-center">{{ $loan['phone'] }}</td>
                    <td>{{ $loan['gender'] ?? '' }}</td>
                    <td>{{ $loan['tenure'] ?? '' }}</td>
                    <td>{{ $loan['subsector'] ?? '' }}</td>
                    <td>{{ $loan['loan_officer'] }}</td>
                    <td class="text-center">{{ ucfirst($loan['status']) }}</td>
                    <td class="text-center">{{ $loan['disbursed_date_iso'] ?? $loan['disbursed_date'] }}</td>
                    <td class="text-right">{{ number_format($loan['disbursed_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['management_fees_balance'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_principal'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_interest'] ?? 0, 2) }}</td>
                    <td class="text-center">{{ $loan['days_in_arrears'] ?? 0 }}</td>
                    <td class="text-right">{{ number_format($loan['accrued_penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_balance'] ?? 0, 2) }}</td>
                    <td class="text-center">{{ number_format($loan['repayment_rate'], 2) }}%</td>
                    <td class="text-center">{{ $loan['maturity_date'] }}</td>
                </tr>
            @empty
                <tr><td colspan="18" class="text-center">No records found</td></tr>
            @endforelse
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
