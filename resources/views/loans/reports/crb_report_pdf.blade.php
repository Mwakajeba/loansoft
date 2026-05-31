<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>CRB Report - {{ $reportingDate }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 7px;
            margin: 0;
            color: #222;
        }
        .container {
            width: 100%;
        }
        .header {
            margin-bottom: 8px;
            border-bottom: 2px solid #333;
            padding-bottom: 6px;
        }
        .company-name {
            font-size: 13px;
            font-weight: bold;
            color: #333;
            text-transform: uppercase;
        }
        .report-title {
            font-size: 11px;
            font-weight: bold;
            color: #555;
            margin-top: 2px;
        }
        .report-info {
            font-size: 8px;
            color: #666;
            margin-top: 2px;
        }
        .summary-section {
            margin: 8px 0;
            padding: 6px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        .summary-item {
            display: inline-block;
            margin-right: 16px;
            margin-bottom: 3px;
        }
        .summary-label {
            font-weight: bold;
            color: #555;
        }
        .summary-value {
            color: #000;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 6px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 2px 3px;
            text-align: left;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.2;
        }
        th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
            font-size: 6px;
        }
        td {
            font-size: 6px;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .text-end {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .badge {
            padding: 1px 3px;
            border-radius: 2px;
            font-size: 6px;
            font-weight: bold;
            white-space: nowrap;
        }
        .badge-success {
            background-color: #28a745;
            color: white;
        }
        .badge-danger {
            background-color: #dc3545;
            color: white;
        }
        .badge-primary {
            background-color: #007bff;
            color: white;
        }
        .nowrap {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="report-title">Credit Reference Bureau (CRB) Report</div>
            <div class="report-info">Generated on {{ now()->format('d/m/Y H:i') }}</div>
            <div class="report-info">
                Reporting Date: {{ \Carbon\Carbon::parse($reportingDate)->format('d/m/Y') }}
            @if($branch)
                | Branch: {{ $branch->name }}
            @endif
            @if($loanOfficer)
                | Loan Officer: {{ $loanOfficer->name }}
            @endif
            </div>
        </div>

    <div class="summary-section">
        <div class="summary-item">
            <span class="summary-label">Total Loans:</span>
            <span class="summary-value">{{ number_format($summary['total_loans']) }}</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Loan Amount:</span>
            <span class="summary-value">TZS {{ number_format($summary['total_loan_amount'], 2) }}</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Outstanding:</span>
            <span class="summary-value">TZS {{ number_format($summary['total_outstanding'], 2) }}</span>
        </div>
        <div class="summary-item">
            <span class="summary-label">Total Past Due:</span>
            <span class="summary-value">TZS {{ number_format($summary['total_past_due'], 2) }}</span>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Full Name</th>
                <th>Contract Code</th>
                <th>Customer Code</th>
                <th>Branch</th>
                <th>Status</th>
                <th>Type</th>
                <th>Purpose</th>
                <th class="nowrap">Rate</th>
                <th class="text-end">Total Loan</th>
                <th class="text-end">Loan Taken</th>
                <th class="text-end">Installment</th>
                <th class="text-center">No. Install</th>
                <th class="text-center">Outstanding</th>
                <th class="text-end">Outstanding Amt</th>
                <th class="text-end">Past Due</th>
                <th class="text-center">Days</th>
                <th class="text-center">Due Install</th>
                <th>Last Payment</th>
                <th class="text-end">Monthly Paid</th>
                <th>Periodicity</th>
                <th>Start</th>
                <th>End</th>
                <th>Real End</th>
                <th>Collateral</th>
                <th class="text-end">Coll. Value</th>
                <th>Role</th>
                <th class="nowrap">Currency</th>
            </tr>
        </thead>
        <tbody>
            @forelse($crbData as $data)
                <tr>
                    <td>{{ $data['reporting_date'] }}</td>
                    <td>{{ $data['fullname'] }}</td>
                    <td>{{ $data['contract_code'] }}</td>
                    <td>{{ $data['customer_code'] }}</td>
                    <td>{{ $data['branch'] }}</td>
                    <td>
                        <span class="badge badge-{{ $data['loan_status'] == 'Active' ? 'success' : ($data['loan_status'] == 'Defaulted' ? 'danger' : 'primary') }}">
                            {{ $data['loan_status'] }}
                        </span>
                    </td>
                    <td>{{ $data['type_of_contract'] }}</td>
                    <td>{{ $data['loan_purpose'] }}</td>
                    <td>{{ $data['interest_rate'] }}%</td>
                    <td class="text-end">{{ number_format($data['total_loan'], 2) }}</td>
                    <td class="text-end">{{ number_format($data['total_loan_taken'], 2) }}</td>
                    <td class="text-end">{{ number_format($data['installment_amount'], 2) }}</td>
                    <td class="text-center">{{ $data['number_of_installments'] }}</td>
                    <td class="text-center">{{ $data['number_of_outstanding_installments'] }}</td>
                    <td class="text-end">{{ number_format($data['outstanding_amount'], 2) }}</td>
                    <td class="text-end">{{ number_format($data['past_due_amount'], 2) }}</td>
                    <td class="text-center">
                        @if($data['past_due_days'] > 0)
                            <span class="badge badge-danger">{{ $data['past_due_days'] }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-center">{{ $data['number_of_due_installments'] }}</td>
                    <td>{{ $data['date_of_last_payment'] ? \Carbon\Carbon::parse($data['date_of_last_payment'])->format('d-m-Y') : 'N/A' }}</td>
                    <td class="text-end">{{ number_format($data['total_monthly_payment'], 2) }}</td>
                    <td>{{ $data['payment_periodicity'] }}</td>
                    <td>{{ $data['start_date'] ? \Carbon\Carbon::parse($data['start_date'])->format('d-m-Y') : 'N/A' }}</td>
                    <td>{{ $data['end_date'] ? \Carbon\Carbon::parse($data['end_date'])->format('d-m-Y') : 'N/A' }}</td>
                    <td>{{ $data['real_end_date'] ? \Carbon\Carbon::parse($data['real_end_date'])->format('d-m-Y') : 'N/A' }}</td>
                    <td>{{ $data['collateral_type'] }}</td>
                    <td class="text-end">{{ number_format($data['collateral_value'], 2) }}</td>
                    <td>{{ $data['role_of_customer'] }}</td>
                    <td>{{ $data['currency'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="28" class="text-center">No loan data available.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</body>
</html>
