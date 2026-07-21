@php
    $reportInfo = '<strong>Branch:</strong> ' . ($branch_name ?? 'All Branches');
    $reportInfo .= ' | <strong>Group:</strong> ' . ($group_name ?? 'All Groups');
    $reportInfo .= ' | <strong>Loan Officer:</strong> ' . ($loan_officer_name ?? 'All Officers');
    $reportInfo .= '<br><strong>As of Date:</strong> ' . \Carbon\Carbon::parse($as_of_date)->format('d/m/Y');
    $reportInfo .= ' | <strong>Report Date:</strong> ' . ($generated_date ?? \Carbon\Carbon::now()->format('d/m/Y H:i:s'));
    $reportInfo .= '<br><span class="note">Days in arrears = as-of date minus due date of the oldest instalment that is not fully paid (partial payments still count from that instalment).</span>';
    $extraStyles = 'body { font-size: 7px; line-height: 1.2; }
th, td { font-size: 6px; padding: 2px 1px; }
.note { font-size: 6px; color: #333; font-style: italic; margin-top: 4px; }
.footer { margin-top: 10px; padding-top: 6px; font-size: 7px; }
.digital-signature { font-size: 6px; }';
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Portfolio at Risk (PAR ' . $par_days . ') Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
    'extraStyles' => $extraStyles,
])

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Loan No</th>
                <th>Borrower</th>
                <th>Branch</th>
                <th>Officer</th>
                <th>Product</th>
                <th>Disbursement</th>
                <th>Maturity</th>
                <th>Principal O/s</th>
                <th>Interest O/s</th>
                <th>Total O/s</th>
                <th>Instalment</th>
                <th>Amount Due</th>
                <th>Amount Paid</th>
                <th>Arrears</th>
                <th>Days</th>
                <th>PAR Cat.</th>
                <th>Last Payment</th>
                <th>At Risk Amt</th>
                <th>At Risk?</th>
            </tr>
        </thead>
        <tbody>
            @php
                $count = 0;
                $tp = $ti = $tt = $tinst = $tdue = $tpaid = $tarr = $tarisk = 0;
            @endphp
            @forelse($report_data as $index => $row)
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

@include('loans.reports.partials.pdf_report_layout_close')
