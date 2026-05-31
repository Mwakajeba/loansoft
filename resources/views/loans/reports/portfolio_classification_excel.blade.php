{{-- Plain HTML table consumed by Maatwebsite\Excel FromView --}}
@php
    $s           = $reportData['summary'];
    $hasCls      = $reportData['has_classifications'];
    $clsList     = $reportData['classifications'];
    $bucketCount = $hasCls ? $clsList->count() : 0;
    $totalCols   = 16 + ($hasCls ? $bucketCount + 2 : 0); // +2 = provision rate + provision amount
@endphp
<table>
    {{-- Row 1: Title --}}
    <tr>
        <td colspan="{{ $totalCols }}" style="font-size:14px; font-weight:bold; text-align:center; background-color:#4472C4; color:#FFFFFF;">
            LOAN PORTFOLIO CLASSIFICATION REPORT
        </td>
    </tr>
    {{-- Row 2: Report meta --}}
    <tr>
        <td colspan="{{ $totalCols }}" style="font-weight:bold; background-color:#E7E6E6;">
            As of Date: {{ \Carbon\Carbon::parse($s['as_of_date'])->format('d/m/Y') }}
            &nbsp;|&nbsp; Status: {{ ucfirst(str_replace('_',' ',$status)) }}
            &nbsp;|&nbsp; Generated: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }}
        </td>
    </tr>
    {{-- Row 3: spacer --}}
    <tr><td colspan="{{ $totalCols }}"></td></tr>
    {{-- Row 4: Summary header --}}
    <tr>
        <td colspan="3" style="font-weight:bold; background-color:#D9D9D9;">Total Loans</td>
        <td colspan="3" style="font-weight:bold; background-color:#D9D9D9;">Total Disbursed</td>
        <td colspan="3" style="font-weight:bold; background-color:#D9D9D9;">Total Outstanding</td>
        <td colspan="3" style="font-weight:bold; background-color:#D9D9D9;">Principal Collected</td>
        @if($hasCls)
        <td colspan="4" style="font-weight:bold; background-color:#D9D9D9;">Total Provision</td>
        @else
        <td colspan="{{ $totalCols - 12 }}"></td>
        @endif
    </tr>
    {{-- Row 5: Summary values --}}
    <tr>
        <td colspan="3">{{ number_format($s['total_loans']) }}</td>
        <td colspan="3">{{ number_format($s['total_disbursed'], 2) }}</td>
        <td colspan="3">{{ number_format($s['total_outstanding'], 2) }}</td>
        <td colspan="3">{{ number_format($s['total_principal_collected'], 2) }}</td>
        @if($hasCls)
        <td colspan="4">{{ number_format($s['total_provision'], 2) }}</td>
        @else
        <td colspan="{{ $totalCols - 12 }}"></td>
        @endif
    </tr>
    {{-- Row 6-7: spacers --}}
    <tr><td colspan="{{ $totalCols }}"></td></tr>
    <tr><td colspan="{{ $totalCols }}"></td></tr>
    {{-- Row 8: Column headers --}}
    <tr style="background-color:#D9D9D9; font-weight:bold;">
        <td>#</td>
        <td>Disbursement Date</td>
        <td>Customer Name</td>
        <td>Gender</td>
        <td>Age</td>
        <td>Branch / Area</td>
        <td>Loan Product Type</td>
        <td>Principal Disbursed</td>
        <td>Interest Paid</td>
        <td>Due Interest Unpaid</td>
        <td>Fee Unpaid</td>
        <td>Fee Paid</td>
        <td>Principal Collected</td>
        <td>Accrued Interest</td>
        <td>Outstanding Balance</td>
        <td>Past Due Days</td>
        @if($hasCls)
            @foreach($clsList as $cls)
                <td>{{ $cls->bucket_label }} - {{ $cls->status }} ({{ number_format($cls->provision_percentage, 0) }}%)</td>
            @endforeach
            <td>Provision Rate %</td>
            <td>Provision Amount</td>
        @endif
    </tr>
    {{-- Data rows --}}
    @foreach($reportData['loans'] as $loan)
    <tr>
        <td>{{ $loan['serial'] }}</td>
        <td>{{ $loan['disbursement_date'] }}</td>
        <td>{{ $loan['customer_name'] }}</td>
        <td>{{ $loan['gender'] }}</td>
        <td>{{ $loan['age'] ?? '' }}</td>
        <td>{{ $loan['branch'] }}</td>
        <td>{{ $loan['loan_product_type'] }}</td>
        <td>{{ $loan['principal_disbursed'] }}</td>
        <td>{{ $loan['interest_paid'] }}</td>
        <td>{{ $loan['due_interest_unpaid'] }}</td>
        <td>{{ $loan['fee_unpaid'] }}</td>
        <td>{{ $loan['fee_paid'] }}</td>
        <td>{{ $loan['principal_collected'] }}</td>
        <td>{{ $loan['accrued_interest'] }}</td>
        <td>{{ $loan['outstanding_balance'] }}</td>
        <td>{{ $loan['past_due_days'] }}</td>
        @if($hasCls)
            @foreach($clsList as $cls)
                <td>{{ $loan['bucket_amounts'][$cls->id] ?? 0 }}</td>
            @endforeach
            <td>{{ $loan['provision_rate'] > 0 ? $loan['provision_rate'] : '' }}</td>
            <td>{{ $loan['provision_amount'] }}</td>
        @endif
    </tr>
    @endforeach
    {{-- Totals row --}}
    @if(count($reportData['loans']) > 0)
    <tr style="font-weight:bold; background-color:#D9D9D9;">
        <td colspan="7">TOTALS ({{ $s['total_loans'] }} loans)</td>
        <td>{{ $s['total_disbursed'] }}</td>
        <td>{{ $s['total_interest_paid'] }}</td>
        <td>{{ $s['total_due_interest_unpaid'] }}</td>
        <td>{{ $s['total_fee_unpaid'] }}</td>
        <td>{{ $s['total_fee_paid'] }}</td>
        <td>{{ $s['total_principal_collected'] }}</td>
        <td>{{ $s['total_accrued_interest'] }}</td>
        <td>{{ $s['total_outstanding'] }}</td>
        <td></td>
        @if($hasCls)
            @foreach($clsList as $cls)
                <td>{{ $s['bucket_totals'][$cls->id] ?? 0 }}</td>
            @endforeach
            <td></td>
            <td>{{ $s['total_provision'] }}</td>
        @endif
    </tr>
    @endif
</table>
