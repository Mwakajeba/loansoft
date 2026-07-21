@php
    $reportInfo = '<strong>As of Date:</strong> ' . (isset($asOfDate) ? \Carbon\Carbon::parse($asOfDate)->format('d/m/Y') : \Carbon\Carbon::now()->format('d/m/Y'));
    if (isset($branchId) && $branchId) {
        $reportInfo .= ' | <strong>Branch:</strong> ' . (\App\Models\Branch::find($branchId)->name ?? 'N/A');
    }
    if (isset($loanOfficerId) && $loanOfficerId) {
        $reportInfo .= ' | <strong>Loan Officer:</strong> ' . (\App\Models\User::find($loanOfficerId)->name ?? 'N/A');
    }
    $reportInfo .= '<br><strong>Report Date:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y H:i:s');
@endphp

@include('loans.reports.partials.pdf_report_layout_open', [
    'company' => $company,
    'reportTitle' => 'Non Performing Loan (NPL) Report',
    'reportInfo' => $reportInfo,
    'pageSize' => 'A3 landscape',
])

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">S/N</th>
                <th style="width: 7%;">Date</th>
                <th style="width: 9%;">Branch</th>
                <th style="width: 9%;">Loan Officer</th>
                <th style="width: 7%;">Loan ID</th>
                <th style="width: 12%;">Borrower</th>
                <th style="width: 9%;">Outstanding</th>
                <th style="width: 6%;">DPD</th>
                <th style="width: 9%;">Classification</th>
                <th style="width: 7%;">Provision %</th>
                <th style="width: 9%;">Provision Amt</th>
                <th style="width: 8%;">Collateral</th>
                <th style="width: 6%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @php
                $totalOutstanding = 0;
                $totalProvision = 0;
                $count = 0;
            @endphp
            @if(isset($nplData) && count($nplData))
                @foreach($nplData as $index => $row)
                    @php
                        $count++;
                        $totalOutstanding += $row['outstanding'] ?? 0;
                        $totalProvision += $row['provision_amount'] ?? 0;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td class="text-center">{{ $row['date_of'] }}</td>
                        <td>{{ $row['branch'] }}</td>
                        <td>{{ $row['loan_officer'] }}</td>
                        <td class="text-center">{{ $row['loan_id'] }}</td>
                        <td>{{ $row['borrower'] }}</td>
                        <td class="text-right">{{ number_format($row['outstanding'], 0) }}</td>
                        <td class="text-center">{{ $row['dpd'] }}</td>
                        <td class="text-center">{{ $row['classification'] }}</td>
                        <td class="text-center">{{ $row['provision_percent'] }}</td>
                        <td class="text-right">{{ number_format($row['provision_amount'], 0) }}</td>
                        <td>{{ $row['collateral'] }}</td>
                        <td class="text-center">{{ $row['status'] }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td class="text-center" colspan="2"><strong>TOTAL</strong></td>
                    <td colspan="4" class="text-right"><strong>{{ number_format($count) }} Records</strong></td>
                    <td class="text-right"><strong>{{ number_format($totalOutstanding, 0) }}</strong></td>
                    <td colspan="3"></td>
                    <td class="text-right"><strong>{{ number_format($totalProvision, 0) }}</strong></td>
                    <td colspan="2"></td>
                </tr>
            @else
                <tr><td colspan="13" class="text-center">No NPL records found</td></tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        <p><strong>&copy; {{ date('Y') }} {{ $company->name ?? config('app.name', 'SmartFinance') }}. All Rights Reserved.</strong></p>
        <p class="digital-signature">This is a digitally generated document from {{ $company->name ?? config('app.name', 'SmartFinance') }} System. No signature required.</p>
        <p class="digital-signature">Generated on: {{ \Carbon\Carbon::now()->format('d/m/Y H:i:s') }} | Document ID: {{ strtoupper(uniqid('DOC-')) }}</p>
    </div>

@include('loans.reports.partials.pdf_report_layout_close')
