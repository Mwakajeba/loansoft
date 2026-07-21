@php
    $s = $reportData['summary'];
    $clsList = $reportData['classifications'];
    $hasCls = $reportData['has_classifications'];

    $reportInfo = '<strong>As of Date:</strong> ' . \Carbon\Carbon::parse($asOfDate)->format('d/m/Y');
    if ($branchId) {
        $reportInfo .= ' | <strong>Branch:</strong> ' . ($branches->find($branchId)->name ?? 'N/A');
    }
    if ($groupId) {
        $reportInfo .= ' | <strong>Group:</strong> ' . ($groups->find($groupId)->name ?? 'N/A');
    }
    if ($loanOfficerId) {
        $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loanOfficers->find($loanOfficerId)->name ?? 'N/A');
    }
    $reportInfo .= ' | <strong>Status:</strong> ' . ucfirst(str_replace('_', ' ', $status));
    $reportInfo .= '<br><strong>Generated:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
    $reportInfo .= ' | <strong>Total Loans:</strong> ' . $s['total_loans'];
    $reportInfo .= ' | <strong>Total Outstanding:</strong> ' . number_format($s['total_outstanding'], 2);
    if ($hasCls) {
        $reportInfo .= ' | <strong>Total Provision:</strong> ' . number_format($s['total_provision'], 2);
    }

    $extraStyles = 'body { font-size: 8px; line-height: 1.3; }
th, td { font-size: 6.5px; padding: 2px 1px; }
.total-row { background-color: #e0e0e0; }
.bucket-header { background-color: #2c5282; color: #fff; font-weight: bold; text-align: center; }
.rate-header { background-color: #276749; color: #fff; font-weight: bold; text-align: center; }
.provision-header { background-color: #7b2d00; color: #fff; font-weight: bold; text-align: center; }
.footer { font-size: 7px; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Loan Portfolio Classification Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
    'extraStyles' => $extraStyles,
])

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

@include('loans.reports.partials.pdf_report_layout_close')
