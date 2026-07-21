@php
    $fmt = fn ($v) => $v !== null && $v !== '' ? number_format((float) $v, 2) : '';
    $fmtDate = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '';
@endphp
<table>
    <tr>
        <td colspan="11" style="text-align:center; font-weight:bold; font-size:13px; border:none;">
            Client Name: {{ $reportData['client_name'] }}
        </td>
    </tr>
    <tr><td colspan="11" style="border:none;"></td></tr>

    <tr>
        <td style="font-weight:bold; background-color:#F3F4F6;">Loan Details</td>
        <td style="font-weight:bold; background-color:#F3F4F6; text-align:center;" colspan="2">Amount</td>
        <td style="font-weight:bold; background-color:#F3F4F6; text-align:center;" colspan="8">Remarks</td>
    </tr>
    <tr>
        <td>Principal</td>
        <td style="text-align:right;" colspan="2">{{ $fmt($reportData['summary']['principal']) }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Interest</td>
        <td style="text-align:right;" colspan="2">{{ $fmt($reportData['summary']['interest']) }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Total loan (P+I)</td>
        <td style="text-align:right;" colspan="2">{{ $fmt($reportData['summary']['total_pi']) }}</td>
        <td colspan="8"></td>
    </tr>
    @foreach($reportData['fee_rows'] as $feeRow)
        <tr>
            <td>{{ $feeRow['label'] }}</td>
            <td style="text-align:right;" colspan="2">{{ $feeRow['amount'] !== null ? $fmt($feeRow['amount']) : '-' }}</td>
            <td colspan="8" style="text-align:center; font-weight:bold; background-color:{{ $feeRow['status'] === 'paid' ? '#92D050' : '#FF0000' }}; color:{{ $feeRow['status'] === 'paid' ? '#000000' : '#FFFFFF' }};">
                {{ $feeRow['remarks'] }}
            </td>
        </tr>
    @endforeach
    <tr>
        <td>Tenure</td>
        <td style="text-align:right;" colspan="2">{{ $reportData['summary']['tenure'] }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Monthly Instalment</td>
        <td style="text-align:right;" colspan="2">{{ $fmt($reportData['summary']['monthly_instalment']) }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Disbursement Date</td>
        <td style="text-align:right;" colspan="2">{{ $fmtDate($reportData['summary']['disbursement_date']) }}</td>
        <td colspan="8"></td>
    </tr>

    <tr><td colspan="11" style="border:none;"></td></tr>

    <tr style="font-weight:bold; text-align:center;">
        <td>SN</td>
        <td>Date</td>
        <td>Principal</td>
        <td>Interest</td>
        <td>Instalment</td>
        <td>Penalty</td>
        <td>Amount Due</td>
        <td>Paid</td>
        <td>Outstanding Balance</td>
        <td>Days in Arrears</td>
        <td>Remarks</td>
    </tr>

    @foreach($reportData['schedule_rows'] as $row)
        @php
            $outBg = match($row['row_class']) {
                'paid' => '#92D050',
                'overdue' => '#FF0000',
                default => '#FFFFFF',
            };
            $outColor = $row['row_class'] === 'overdue' ? '#FFFFFF' : '#000000';
        @endphp
        <tr>
            <td style="text-align:center;">{{ $row['sn'] }}</td>
            <td style="text-align:center;">{{ $fmtDate($row['date']) }}</td>
            <td style="text-align:right;">{{ $fmt($row['principal']) }}</td>
            <td style="text-align:right;">{{ $fmt($row['interest']) }}</td>
            <td style="text-align:right;">{{ $fmt($row['instalment']) }}</td>
            <td style="text-align:right;">{{ $row['penalty'] > 0 ? $fmt($row['penalty']) : '' }}</td>
            <td style="text-align:right;">{{ $fmt($row['amount_due']) }}</td>
            <td style="text-align:right;">{{ $row['paid'] > 0 ? $fmt($row['paid']) : '' }}</td>
            <td style="text-align:right; background-color:{{ $outBg }}; color:{{ $outColor }}; font-weight:{{ $row['row_class'] === 'overdue' ? 'bold' : 'normal' }};">
                {{ $row['outstanding_balance'] !== null ? $fmt($row['outstanding_balance']) : '' }}
            </td>
            <td style="text-align:center;">{{ $row['days_in_arrears'] > 0 ? number_format($row['days_in_arrears'], 2) : '' }}</td>
            <td style="text-align:center;">{{ $row['remarks'] }}</td>
        </tr>
    @endforeach

    @php $t = $reportData['totals']; @endphp
    <tr style="font-weight:bold; background-color:#F9FAFB;">
        <td colspan="2" style="text-align:center;">Totals</td>
        <td style="text-align:right;">{{ $fmt($t['principal']) }}</td>
        <td style="text-align:right;">{{ $fmt($t['interest']) }}</td>
        <td style="text-align:right;">{{ $fmt($t['instalment']) }}</td>
        <td style="text-align:right;">{{ $fmt($t['penalty']) }}</td>
        <td style="text-align:right;">{{ $fmt($t['amount_due']) }}</td>
        <td style="text-align:right;">{{ $fmt($t['paid']) }}</td>
        <td style="text-align:right;">{{ $fmt($t['outstanding_balance']) }}</td>
        <td></td>
        <td></td>
    </tr>

    <tr><td colspan="11" style="border:none;"></td></tr>

    <tr>
        <td colspan="2" style="background-color:#4472C4; color:#FFFFFF; font-weight:bold; text-align:center;">Settlements</td>
        <td colspan="9"></td>
    </tr>
    <tr>
        <td colspan="2" style="background-color:#FF0000; color:#FFFFFF; font-weight:bold;">Outstanding Amount</td>
        <td colspan="2" style="text-align:right; background-color:#FF0000; color:#FFFFFF; font-weight:bold;">{{ $fmt($reportData['settlements']['outstanding_amount']) }}</td>
        <td colspan="7"></td>
    </tr>
    <tr>
        <td colspan="2" style="background-color:#FFFF00; color:#000000; font-weight:bold;">Loan Settlement amount</td>
        <td colspan="2" style="text-align:right; background-color:#FFFF00; color:#000000; font-weight:bold;">{{ $fmt($reportData['settlements']['loan_settlement_amount']) }}</td>
        <td colspan="7"></td>
    </tr>
</table>
