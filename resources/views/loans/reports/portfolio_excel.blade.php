<table>
    <thead>
        <tr>
            <th colspan="18" style="font-size: 16px; font-weight: bold; text-align: center; background-color: #4472C4; color: white;">LOAN PORTFOLIO REPORT</th>
        </tr>
        <tr>
            <th colspan="18" style="background-color: #E7E6E6;">As of: {{ \Carbon\Carbon::parse($portfolioData['summary']['as_of_date'] ?? now())->format('F d, Y') }}</th>
        </tr>
        <tr></tr>
        <tr><th colspan="18" style="background-color: #D9D9D9;">PORTFOLIO SUMMARY</th></tr>
        <tr>
            <th>Total Loans</th><th>Active Loans</th><th>Completed Loans</th>
            <th>Total Outstanding</th><th>Total Paid</th><th>Repayment Rate</th>
            <th>Portfolio at Risk</th><th>PAR Ratio</th>
        </tr>
        <tr>
            <td>{{ number_format($portfolioData['summary']['total_loans']) }}</td>
            <td>{{ number_format($portfolioData['summary']['active_loans']) }}</td>
            <td>{{ number_format($portfolioData['summary']['completed_loans']) }}</td>
            <td>{{ number_format($portfolioData['summary']['total_outstanding'], 2) }}</td>
            <td>{{ number_format($portfolioData['summary']['total_paid'], 2) }}</td>
            <td>{{ number_format($portfolioData['summary']['overall_repayment_rate'], 2) }}%</td>
            <td>{{ number_format($portfolioData['summary']['portfolio_at_risk'], 2) }}</td>
            <td>{{ number_format($portfolioData['summary']['par_ratio'], 2) }}%</td>
        </tr>
        <tr></tr>
        <tr><th colspan="18" style="background-color: #D9D9D9;">PORTFOLIO DETAILS</th></tr>
        <tr>
            <th style="background:#4472C4;color:#fff;">Customer Name</th>
            <th style="background:#4472C4;color:#fff;">Customer No</th>
            <th style="background:#4472C4;color:#fff;">Phone</th>
            <th style="background:#4472C4;color:#fff;">Gender</th>
            <th style="background:#4472C4;color:#fff;">Tenure</th>
            <th style="background:#4472C4;color:#fff;">Subsector</th>
            <th style="background:#4472C4;color:#fff;">Loan Officer</th>
            <th style="background:#4472C4;color:#fff;">Status</th>
            <th style="background:#4472C4;color:#fff;">Disbursed Date</th>
            <th style="background:#4472C4;color:#fff;">Disbursed Amount</th>
            <th style="background:#4472C4;color:#fff;">Management Fees Balance</th>
            <th style="background:#4472C4;color:#fff;">Outstanding principal</th>
            <th style="background:#4472C4;color:#fff;">Accrued/Outstanding Interest</th>
            <th style="background:#4472C4;color:#fff;">Days in Arrears</th>
            <th style="background:#dc3545;color:#fff;">Accrued Penalties</th>
            <th style="background:#fd7e14;color:#fff;">Outstanding Balance</th>
            <th style="background:#4472C4;color:#fff;">Repayment Rate</th>
            <th style="background:#4472C4;color:#fff;">Maturity Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach($portfolioData['loans'] as $loan)
        <tr>
            <td>{{ $loan['customer'] }}</td>
            <td>{{ $loan['customer_no'] }}</td>
            <td>{{ $loan['phone'] }}</td>
            <td>{{ $loan['gender'] ?? '' }}</td>
            <td>{{ $loan['tenure'] ?? '' }}</td>
            <td>{{ $loan['subsector'] ?? '' }}</td>
            <td>{{ $loan['loan_officer'] }}</td>
            <td>{{ ucfirst($loan['status']) }}</td>
            <td>{{ $loan['disbursed_date_iso'] ?? $loan['disbursed_date'] }}</td>
            <td>{{ number_format($loan['disbursed_amount'], 2) }}</td>
            <td>{{ number_format($loan['management_fees_balance'] ?? 0, 2) }}</td>
            <td>{{ number_format($loan['outstanding_principal'] ?? 0, 2) }}</td>
            <td>{{ number_format($loan['outstanding_interest'] ?? 0, 2) }}</td>
            <td>{{ $loan['days_in_arrears'] ?? 0 }}</td>
            <td>{{ number_format($loan['accrued_penalties'] ?? 0, 2) }}</td>
            <td>{{ number_format($loan['outstanding_balance'] ?? 0, 2) }}</td>
            <td>{{ number_format($loan['repayment_rate'], 2) }}%</td>
            <td>{{ $loan['maturity_date'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
