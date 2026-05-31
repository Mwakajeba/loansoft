<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Portfolio Classification Report</title>
    <style>
        @page { size: A3 landscape; margin: 5mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 8px; color: #000; line-height: 1.3; }
        .header { text-align: center; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 2px solid #000; }
        .logo { max-height: 45px; margin-bottom: 4px; }
        .company-name { font-size: 14px; font-weight: bold; color: #000; margin: 2px 0; }
        .company-details { font-size: 8px; color: #000; margin: 1px 0; }
        .report-title { font-size: 11px; font-weight: bold; color: #000; margin: 6px 0 2px 0; text-transform: uppercase; }
        .report-info { font-size: 8px; color: #000; margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #000; padding: 2px 1px; text-align: left; font-size: 6.5px; color: #000; }
        th { background-color: #000; color: #fff; font-weight: bold; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { background-color: #e0e0e0; font-weight: bold; }
        .bucket-header { background-color: #2c5282; color: #fff; font-weight: bold; text-align: center; }
        .rate-header { background-color: #276749; color: #fff; font-weight: bold; text-align: center; }
        .provision-header { background-color: #7b2d00; color: #fff; font-weight: bold; text-align: center; }
        .footer { margin-top: 10px; padding-top: 6px; border-top: 1px solid #000; text-align: center; font-size: 7px; }
    </style>
</head>
<body>
    @php
        $logoBase64 = null;
        $logoPath   = null;
        if (isset($company) && $company && !empty($company->logo)) {
            $storagePath = public_path('storage/' . $company->logo);
            if (file_exists($storagePath)) { $logoPath = $storagePath; }
        }
        if (!$logoPath && file_exists(public_path('assets/images/logo-img.png'))) {
            $logoPath = public_path('assets/images/logo-img.png');
        }
        if ($logoPath && file_exists($logoPath)) {
            $logoType   = pathinfo($logoPath, PATHINFO_EXTENSION);
            $logoData   = file_get_contents($logoPath);
            $logoBase64 = 'data:image/' . $logoType . ';base64,' . base64_encode($logoData);
        }
        $s       = $reportData['summary'];
        $clsList = $reportData['classifications'];
        $hasCls  = $reportData['has_classifications'];
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
        <div class="report-title">Loan Portfolio Classification Report</div>
        <div class="report-info">
            <strong>As of Date:</strong> {{ \Carbon\Carbon::parse($asOfDate)->format('d/m/Y') }}
            @if($branchId) | <strong>Branch:</strong> {{ $branches->find($branchId)->name ?? 'N/A' }} @endif
            @if($groupId) | <strong>Group:</strong> {{ $groups->find($groupId)->name ?? 'N/A' }} @endif
            @if($loanOfficerId) | <strong>Loan Officer:</strong> {{ $loanOfficers->find($loanOfficerId)->name ?? 'N/A' }} @endif
            | <strong>Status:</strong> {{ ucfirst(str_replace('_', ' ', $status)) }}
        </div>
        <div class="report-info">
            <strong>Generated:</strong> {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}
            &nbsp;|&nbsp; <strong>Total Loans:</strong> {{ $s['total_loans'] }}
            &nbsp;|&nbsp; <strong>Total Outstanding:</strong> {{ number_format($s['total_outstanding'], 2) }}
            @if($hasCls)
                &nbsp;|&nbsp; <strong>Total Provision:</strong> {{ number_format($s['total_provision'], 2) }}
            @endif
        </div>
    </div>

    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th style="width:2%;">#</th>
                <th style="width:5%;">Disb. Date</th>
                <th style="width:9%;">Customer Name</th>
                <th style="width:3%;">Gender</th>
                <th style="width:2%;">Age</th>
                <th style="width:6%;">Branch</th>
                <th style="width:6%;">Product Type</th>
                <th class="text-right" style="width:5%;">Principal Disbursed</th>
                <th class="text-right" style="width:4%;">Interest Paid</th>
                <th class="text-right" style="width:4%;">Due Int. Unpaid</th>
                <th class="text-right" style="width:4%;">Fee Unpaid</th>
                <th class="text-right" style="width:4%;">Fee Paid</th>
                <th class="text-right" style="width:4%;">Principal Collected</th>
                <th class="text-right" style="width:4%;">Accrued Int.</th>
                <th class="text-right" style="width:5%;">Outstanding Balance</th>
                <th class="text-center" style="width:3%;">Past Due Days</th>
                @if($hasCls)
                    @foreach($clsList as $cls)
                        <th class="bucket-header" style="width:4%;">
                            {{ $cls->bucket_label }}<br>{{ $cls->status }}
                        </th>
                    @endforeach
                    <th class="rate-header" style="width:3%;">Prov. Rate %</th>
                    <th class="provision-header" style="width:4%;">Provision Amt</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($reportData['loans'] as $loan)
                <tr>
                    <td class="text-center">{{ $loan['serial'] }}</td>
                    <td class="text-center">{{ $loan['disbursement_date'] }}</td>
                    <td>{{ $loan['customer_name'] }}</td>
                    <td class="text-center">{{ $loan['gender'] }}</td>
                    <td class="text-center">{{ $loan['age'] ?? '-' }}</td>
                    <td>{{ $loan['branch'] }}</td>
                    <td>{{ $loan['loan_product_type'] }}</td>
                    <td class="text-right">{{ number_format($loan['principal_disbursed'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['interest_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['due_interest_unpaid'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['fee_unpaid'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['fee_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['principal_collected'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['accrued_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($loan['outstanding_balance'], 2) }}</td>
                    <td class="text-center">{{ $loan['past_due_days'] }}</td>
                    @if($hasCls)
                        @foreach($clsList as $cls)
                            <td class="text-right">
                                {{ ($loan['bucket_amounts'][$cls->id] ?? 0) > 0
                                    ? number_format($loan['bucket_amounts'][$cls->id], 2)
                                    : '-' }}
                            </td>
                        @endforeach
                        <td class="text-right">
                            {{ $loan['provision_rate'] > 0 ? number_format($loan['provision_rate'], 2).'%' : '-' }}
                        </td>
                        <td class="text-right">
                            {{ $loan['provision_amount'] > 0 ? number_format($loan['provision_amount'], 2) : '-' }}
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 16 + ($hasCls ? $clsList->count() + 2 : 0) }}"
                        style="text-align:center; padding:6px;">
                        No loans found for the selected filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if(count($reportData['loans']) > 0)
        <tfoot>
            <tr class="total-row">
                <td colspan="7" class="text-right"><strong>TOTALS ({{ $s['total_loans'] }} loans)</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_disbursed'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_interest_paid'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_due_interest_unpaid'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_fee_unpaid'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_fee_paid'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_principal_collected'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_accrued_interest'], 2) }}</strong></td>
                <td class="text-right"><strong>{{ number_format($s['total_outstanding'], 2) }}</strong></td>
                <td></td>
                @if($hasCls)
                    @foreach($clsList as $cls)
                        <td class="text-right"><strong>{{ number_format($s['bucket_totals'][$cls->id] ?? 0, 2) }}</strong></td>
                    @endforeach
                    <td></td>
                    <td class="text-right"><strong>{{ number_format($s['total_provision'], 2) }}</strong></td>
                @endif
            </tr>
        </tfoot>
        @endif
    </table>

    <div class="footer">
        <p>Generated by {{ config('app.name', 'SmartFinance') }} &mdash; {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}</p>
    </div>
</body>
</html>
