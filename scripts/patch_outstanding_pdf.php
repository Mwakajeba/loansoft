<?php
// Patch loan_outstanding_pdf table to use new row structure
$file = __DIR__ . '/../resources/views/loans/reports/loan_outstanding_pdf.blade.php';
$content = file_get_contents($file);
$start = strpos($content, '    <!-- Data Table -->');
$end = strpos($content, '    <!-- Footer -->');
if ($start === false || $end === false) {
    fwrite(STDERR, "markers not found\n");
    exit(1);
}
$table = <<<'BLADE'
    <!-- Data Table -->
    <table>
        <thead>
            <tr>
                <th>Customer</th><th>Cust No</th><th>Phone</th><th>Loan No</th><th>Expires</th>
                <th>Branch</th><th>Officer</th><th>Disb Date</th><th>Disb Amt</th><th>Tot Int</th>
                <th>Tot P+I</th><th>Exp Fees</th><th>Tot Pen</th>
                <th>Princ Paid</th><th>Int Paid</th><th>Fees Paid</th><th>Pen Paid</th>
                <th>O/s Princ</th><th>O/s Int</th><th>O/s Fees</th><th>O/s Pen</th><th>Other</th><th>O/s Bal</th>
            </tr>
        </thead>
        <tbody>
            @php $count = 0; @endphp
            @forelse($outstandingData as $index => $row)
                @php $count++; @endphp
                <tr>
                    <td>{{ $row['customer'] }}</td>
                    <td class="text-center">{{ $row['customer_no'] }}</td>
                    <td class="text-center">{{ $row['phone'] }}</td>
                    <td class="text-center">{{ $row['loan_no'] }}</td>
                    <td class="text-center">{{ $row['expires'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td>{{ $row['loan_officer'] }}</td>
                    <td class="text-center">{{ $row['disbursed_date'] }}</td>
                    <td class="text-right">{{ number_format($row['disbursed_amount'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_principal_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['expected_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['total_penalties'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['principal_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['interest_paid'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['fees_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['penalty_paid'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_principal'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_interest'], 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_fees'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_penalty'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['other_outstanding'] ?? 0, 2) }}</td>
                    <td class="text-right">{{ number_format($row['outstanding_balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="23" class="text-center">No records found</td></tr>
            @endforelse
        </tbody>
    </table>

BLADE;
file_put_contents($file, substr($content, 0, $start) . $table . substr($content, $end));
echo "patched loan_outstanding_pdf\n";
