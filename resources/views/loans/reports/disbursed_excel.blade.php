<table>
    <thead>
        <tr>
            <th colspan="14">LOAN DISBURSEMENT REPORT</th>
        </tr>
        <tr>
            <th colspan="14">{{ $company->name ?? config('app.name', 'SmartFinance') }}</th>
        </tr>
        <tr>
            <th colspan="14">Branch: {{ $branch->name ?? 'All Branches' }}</th>
        </tr>
        <tr>
            <th colspan="14">
                Period: {{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}
            </th>
        </tr>
        <tr>
            <th>S/N</th>
            <th>Customer No</th>
            <th>Customer Name</th>
            <th>Loan No</th>
            <th>Product</th>
            <th>Group</th>
            <th>Loan Officer</th>
            <th>Disbursed Date</th>
            <th>Period</th>
            <th>End Date</th>
            <th>Disbursed Amount</th>
            <th>Interest Amount</th>
            <th>Total Amount</th>
            <th>Rate (%)</th>
        </tr>
    </thead>
    <tbody>
        @php
            $totalDisbursed = 0;
            $totalInterest = 0;
            $totalAmount = 0;
        @endphp
        @forelse($disbursements as $index => $loan)
            @php
                $totalDisbursed += $loan->amount ?? 0;
                $totalInterest += $loan->interest_amount ?? 0;
                $totalAmount += $loan->amount_total ?? 0;
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $loan->customer->customerNo ?? 'N/A' }}</td>
                <td>{{ $loan->customer->name ?? 'N/A' }}</td>
                <td>{{ $loan->loanNo ?? 'N/A' }}</td>
                <td>{{ $loan->product->name ?? 'N/A' }}</td>
                <td>{{ $loan->group->name ?? 'N/A' }}</td>
                <td>{{ $loan->loanOfficer->name ?? 'N/A' }}</td>
                <td>{{ $loan->disbursed_on ? \Carbon\Carbon::parse($loan->disbursed_on)->format('d/m/Y') : 'N/A' }}</td>
                <td>{{ $loan->period ?? 'N/A' }}</td>
                <td>{{ $loan->last_repayment_date ? \Carbon\Carbon::parse($loan->last_repayment_date)->format('d/m/Y') : 'N/A' }}</td>
                <td>{{ $loan->amount ?? 0 }}</td>
                <td>{{ $loan->interest_amount ?? 0 }}</td>
                <td>{{ $loan->amount_total ?? 0 }}</td>
                <td>{{ $loan->interest ?? 0 }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="14">No records found</td>
            </tr>
        @endforelse
        <tr>
            <td colspan="9">TOTAL ({{ number_format($disbursements->count()) }} Records)</td>
            <td></td>
            <td>{{ $totalDisbursed }}</td>
            <td>{{ $totalInterest }}</td>
            <td>{{ $totalAmount }}</td>
            <td></td>
        </tr>
    </tbody>
</table>
