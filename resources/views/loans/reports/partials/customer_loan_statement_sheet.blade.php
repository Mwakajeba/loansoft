@php
    $fmt = fn ($v) => $v !== null && $v !== '' ? number_format((float) $v, 2) : '';
    $fmtDate = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '';
@endphp

<table class="statement-table" cellspacing="0" cellpadding="0">
    <tr>
        <td colspan="11" class="client-name-cell">
            Client Name: {{ $reportData['client_name'] }}
        </td>
    </tr>
    <tr><td colspan="11" style="height:8px;"></td></tr>

    {{-- Loan summary --}}
    <tr>
        <td class="summary-label">Loan Details</td>
        <td class="summary-label text-center" colspan="2">Amount</td>
        <td class="summary-label text-center" colspan="8">Remarks</td>
    </tr>
    <tr>
        <td>Principal</td>
        <td class="amount" colspan="2">{{ $fmt($reportData['summary']['principal']) }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Interest</td>
        <td class="amount" colspan="2">{{ $fmt($reportData['summary']['interest']) }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Total loan (P+I)</td>
        <td class="amount" colspan="2">{{ $fmt($reportData['summary']['total_pi']) }}</td>
        <td colspan="8"></td>
    </tr>
    @forelse($reportData['fee_rows'] as $feeRow)
        <tr>
            <td>{{ $feeRow['label'] }}</td>
            <td class="amount" colspan="2">{{ $feeRow['amount'] !== null ? $fmt($feeRow['amount']) : '-' }}</td>
            <td colspan="8" class="text-center fee-remarks fee-remarks-{{ $feeRow['status'] }}">{{ $feeRow['remarks'] }}</td>
        </tr>
    @empty
    @endforelse
    <tr>
        <td>Tenure</td>
        <td class="amount" colspan="2">{{ $reportData['summary']['tenure'] }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Monthly Instalment</td>
        <td class="amount" colspan="2">{{ $fmt($reportData['summary']['monthly_instalment']) }}</td>
        <td colspan="8"></td>
    </tr>
    <tr>
        <td>Disbursement Date</td>
        <td class="amount" colspan="2">{{ $fmtDate($reportData['summary']['disbursement_date']) }}</td>
        <td colspan="8"></td>
    </tr>

    <tr><td colspan="11" style="height:14px;"></td></tr>

    {{-- Schedule header --}}
    <tr class="schedule-head">
        <th>SN</th>
        <th>Date</th>
        <th>Principal</th>
        <th>Interest</th>
        <th>Instalment</th>
        <th>Penalty</th>
        <th>Amount Due</th>
        <th>Paid</th>
        <th>Outstanding Balance</th>
        <th>Days in Arrears</th>
        <th>Remarks</th>
    </tr>

    @foreach($reportData['schedule_rows'] as $row)
        <tr>
            <td class="text-center">{{ $row['sn'] }}</td>
            <td class="text-center">{{ $fmtDate($row['date']) }}</td>
            <td class="amount">{{ $fmt($row['principal']) }}</td>
            <td class="amount">{{ $fmt($row['interest']) }}</td>
            <td class="amount">{{ $fmt($row['instalment']) }}</td>
            <td class="amount">{{ $row['penalty'] > 0 ? $fmt($row['penalty']) : '' }}</td>
            <td class="amount">{{ $fmt($row['amount_due']) }}</td>
            <td class="amount">{{ $row['paid'] > 0 ? $fmt($row['paid']) : '' }}</td>
            <td class="amount outstanding-cell outstanding-{{ $row['row_class'] }}">
                {{ $row['outstanding_balance'] !== null ? $fmt($row['outstanding_balance']) : '' }}
            </td>
            <td class="text-center">{{ $row['days_in_arrears'] > 0 ? number_format($row['days_in_arrears'], 2) : '' }}</td>
            <td class="text-center">{{ $row['remarks'] }}</td>
        </tr>
    @endforeach

    @php $t = $reportData['totals']; @endphp
    <tr class="totals-row">
        <td colspan="2" class="text-center"><strong>Totals</strong></td>
        <td class="amount"><strong>{{ $fmt($t['principal']) }}</strong></td>
        <td class="amount"><strong>{{ $fmt($t['interest']) }}</strong></td>
        <td class="amount"><strong>{{ $fmt($t['instalment']) }}</strong></td>
        <td class="amount"><strong>{{ $fmt($t['penalty']) }}</strong></td>
        <td class="amount"><strong>{{ $fmt($t['amount_due']) }}</strong></td>
        <td class="amount"><strong>{{ $fmt($t['paid']) }}</strong></td>
        <td class="amount"><strong>{{ $fmt($t['outstanding_balance']) }}</strong></td>
        <td></td>
        <td></td>
    </tr>

    <tr><td colspan="11" style="height:14px;"></td></tr>

    {{-- Settlements --}}
    <tr>
        <td colspan="2" class="settlements-header">Settlements</td>
        <td colspan="9"></td>
    </tr>
    <tr>
        <td colspan="2" class="settlement-label settlement-outstanding">Outstanding Amount</td>
        <td colspan="2" class="amount settlement-outstanding">{{ $fmt($reportData['settlements']['outstanding_amount']) }}</td>
        <td colspan="7"></td>
    </tr>
    <tr>
        <td colspan="2" class="settlement-label settlement-loan">Loan Settlement amount</td>
        <td colspan="2" class="amount settlement-loan">{{ $fmt($reportData['settlements']['loan_settlement_amount']) }}</td>
        <td colspan="7"></td>
    </tr>
</table>
