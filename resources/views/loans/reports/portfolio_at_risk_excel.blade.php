<table>
    <tr>
        <td colspan="20" style="font-size: 18px; font-weight: bold; text-align: center; background-color: #f8f9fa; color: #fd7e14;">
            PORTFOLIO AT RISK (PAR {{ $par_days }}) REPORT
        </td>
    </tr>

    @if($company)
    <tr>
        <td colspan="20" style="font-weight: bold; text-align: center;">{{ $company->name }}</td>
    </tr>
    @if($company->address)
    <tr>
        <td colspan="20" style="text-align: center;">{{ $company->address }}</td>
    </tr>
    @endif
    @if($company->phone || $company->email)
    <tr>
        <td colspan="20" style="text-align: center;">
            @if($company->phone)Phone: {{ $company->phone }}@endif
            @if($company->phone && $company->email) | @endif
            @if($company->email)Email: {{ $company->email }}@endif
        </td>
    </tr>
    @endif
    @endif

    <tr><td colspan="20"></td></tr>
    <tr>
        <td style="font-weight: bold;">Report Date:</td>
        <td>{{ $generated_date }}</td>
        <td></td>
        <td style="font-weight: bold;">As of Date:</td>
        <td>{{ \Carbon\Carbon::parse($as_of_date)->format('d-m-Y') }}</td>
        <td></td>
        <td style="font-weight: bold;">PAR Days (filter):</td>
        <td>{{ $par_days }} days</td>
        <td></td>
        <td style="font-weight: bold;">Total Loans:</td>
        <td>{{ $total_loans }}</td>
        <td colspan="9"></td>
    </tr>
    <tr>
        <td style="font-weight: bold;">Branch:</td>
        <td>{{ $branch_name }}</td>
        <td></td>
        <td style="font-weight: bold;">Group:</td>
        <td>{{ $group_name }}</td>
        <td></td>
        <td style="font-weight: bold;">Loan Officer:</td>
        <td>{{ $loan_officer_name }}</td>
        <td colspan="12"></td>
    </tr>

    <tr><td colspan="20"></td></tr>
    <tr>
        <td colspan="20" style="font-weight: bold; font-size: 14px; background-color: #e9ecef;">PORTFOLIO AT RISK SUMMARY</td>
    </tr>
    <tr>
        <td style="font-weight: bold; background-color: #f8f9fa;">Total outstanding (P+I):</td>
        <td style="background-color: #f8f9fa;">TZS {{ number_format($total_outstanding, 2) }}</td>
        <td></td>
        <td style="font-weight: bold; background-color: #f8f9fa;">Amount at risk (PAR {{ $par_days }}):</td>
        <td style="background-color: #f8f9fa;">TZS {{ number_format($total_at_risk, 2) }}</td>
        <td colspan="15"></td>
    </tr>
    <tr>
        <td style="font-weight: bold; background-color: #f8f9fa;">PAR {{ $par_days }} ratio:</td>
        <td style="background-color: #f8f9fa;">{{ number_format($par_ratio, 1) }}%</td>
        <td></td>
        <td style="font-weight: bold; background-color: #f8f9fa;">Loans at risk:</td>
        <td style="background-color: #f8f9fa;">{{ $loans_at_risk }} / {{ $total_loans }}</td>
        <td colspan="15"></td>
    </tr>
    <tr>
        <td style="font-weight: bold; background-color: #f8f9fa;">PAR category:</td>
        <td style="background-color: #f8f9fa;">Current: {{ $par_categories['Current'] ?? 0 }}</td>
        <td style="background-color: #f8f9fa;">PAR1: {{ $par_categories['PAR1'] ?? 0 }}</td>
        <td style="background-color: #f8f9fa;">PAR30: {{ $par_categories['PAR30'] ?? 0 }}</td>
        <td style="background-color: #f8f9fa;">PAR90: {{ $par_categories['PAR90'] ?? 0 }}</td>
        <td colspan="15"></td>
    </tr>

    <tr><td colspan="20"></td></tr>
    <tr style="background-color: #495057; color: white; font-weight: bold;">
        <td>#</td>
        <td>Loan No</td>
        <td>Borrower Name</td>
        <td>Branch</td>
        <td>Loan Officer</td>
        <td>Loan Product</td>
        <td>Disbursement Date</td>
        <td>Maturity Date</td>
        <td>Principal Outstanding</td>
        <td>Interest Outstanding</td>
        <td>Total Outstanding</td>
        <td>Installment Amount</td>
        <td>Amount Due</td>
        <td>Amount Paid</td>
        <td>Arrears Amount</td>
        <td>Days in Arrears</td>
        <td>PAR Category</td>
        <td>Last Payment Date</td>
        <td>At risk (PAR {{ $par_days }})</td>
        <td>PAR {{ $par_days }}</td>
    </tr>

    @if(count($par_data) > 0)
        @php
            $tp = $ti = $tt = $tinst = $tdue = $tpaid = $tarr = $tarisk = 0;
        @endphp
        @foreach($par_data as $index => $row)
            @php
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
            <td>{{ $index + 1 }}</td>
            <td>{{ $row['loan_no'] }}</td>
            <td>{{ $row['borrower_name'] }}</td>
            <td>{{ $row['branch'] }}</td>
            <td>{{ $row['loan_officer'] }}</td>
            <td>{{ $row['loan_product'] }}</td>
            <td>{{ $row['disbursement_date'] }}</td>
            <td>{{ $row['maturity_date'] }}</td>
            <td>{{ number_format($row['principal_outstanding'], 2) }}</td>
            <td>{{ number_format($row['interest_outstanding'], 2) }}</td>
            <td>{{ number_format($row['total_outstanding'], 2) }}</td>
            <td>{{ number_format($row['installment_amount'], 2) }}</td>
            <td>{{ number_format($row['amount_due'], 2) }}</td>
            <td>{{ number_format($row['amount_paid'], 2) }}</td>
            <td>{{ number_format($row['arrears_amount'], 2) }}</td>
            <td>{{ $row['days_in_arrears'] }}</td>
            <td>{{ $row['par_category'] }}</td>
            <td>{{ $row['last_payment_date'] }}</td>
            <td style="color: #dc3545; font-weight: bold;">{{ number_format($row['at_risk_amount'], 2) }}</td>
            <td>{{ !empty($row['is_at_risk']) ? 'Y' : 'N' }}</td>
        </tr>
        @endforeach

        <tr style="background-color: #f8f9fa; font-weight: bold;">
            <td colspan="8">TOTALS</td>
            <td>TZS {{ number_format($tp, 2) }}</td>
            <td>TZS {{ number_format($ti, 2) }}</td>
            <td>TZS {{ number_format($tt, 2) }}</td>
            <td>TZS {{ number_format($tinst, 2) }}</td>
            <td>TZS {{ number_format($tdue, 2) }}</td>
            <td>TZS {{ number_format($tpaid, 2) }}</td>
            <td>TZS {{ number_format($tarr, 2) }}</td>
            <td>-</td>
            <td>-</td>
            <td>-</td>
            <td>TZS {{ number_format($tarisk, 2) }}</td>
            <td>{{ count($par_data) }} loans</td>
        </tr>
    @else
        <tr>
            <td colspan="20" style="text-align: center; padding: 20px; color: #28a745; font-weight: bold;">
                No loans found matching the Portfolio at Risk criteria for the selected filters.
            </td>
        </tr>
    @endif

    <tr><td colspan="20"></td></tr>
    <tr>
        <td colspan="20" style="text-align: center; font-size: 10px; color: #666;">
            Report generated on {{ $generated_date }} | PAR {{ $par_days }} as of {{ \Carbon\Carbon::parse($as_of_date)->format('d-m-Y') }}
        </td>
    </tr>
</table>
