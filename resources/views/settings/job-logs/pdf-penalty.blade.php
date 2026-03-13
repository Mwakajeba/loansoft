<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Log Details - Penalty Accrual</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #1a237e;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            margin-top: 5px;
            color: #333;
        }
        .job-info {
            margin-bottom: 15px;
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
        }
        .summary-cards {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }
        .summary-card {
            display: table-cell;
            width: 25%;
            padding: 8px;
            text-align: center;
            background: #e3f2fd;
            border: 1px solid #90caf9;
        }
        .summary-card.success {
            background: #e8f5e9;
            border-color: #a5d6a7;
        }
        .summary-card.danger {
            background: #ffebee;
            border-color: #ef9a9a;
        }
        .summary-card.info {
            background: #e0f7fa;
            border-color: #80deea;
        }
        .summary-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
        }
        .summary-value {
            font-size: 12px;
            font-weight: bold;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 5px 6px;
            text-align: left;
        }
        th {
            background: #d32f2f;
            color: white;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
        }
        td {
            font-size: 8px;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        tbody tr:nth-child(even) {
            background: #f9f9f9;
        }
        tfoot td {
            background: #e8e8e8;
            font-weight: bold;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-success {
            background: #4caf50;
            color: white;
        }
        .badge-danger {
            background: #f44336;
            color: white;
        }
    </style>
</head>
<body>
    <div class="header">
        @if($company && $company->logo)
            <img src="{{ public_path('storage/' . $company->logo) }}" alt="Logo" style="height: 40px; margin-bottom: 5px;">
        @endif
        <div class="company-name">{{ $company->name ?? 'Company Name' }}</div>
        <div class="report-title">Job Log Details - Penalty Accrual</div>
    </div>

    <div class="job-info">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="border: none; padding: 2px 5px; width: 15%;"><strong>Job Name:</strong></td>
                <td style="border: none; padding: 2px 5px; width: 35%;">{{ $jobLog->job_name }}</td>
                <td style="border: none; padding: 2px 5px; width: 15%;"><strong>Status:</strong></td>
                <td style="border: none; padding: 2px 5px; width: 35%;">{{ ucfirst($jobLog->status) }}</td>
            </tr>
            <tr>
                <td style="border: none; padding: 2px 5px;"><strong>Started At:</strong></td>
                <td style="border: none; padding: 2px 5px;">{{ $jobLog->started_at ? $jobLog->started_at->format('d-m-Y H:i:s') : 'N/A' }}</td>
                <td style="border: none; padding: 2px 5px;"><strong>Completed At:</strong></td>
                <td style="border: none; padding: 2px 5px;">{{ $jobLog->completed_at ? $jobLog->completed_at->format('d-m-Y H:i:s') : 'N/A' }}</td>
            </tr>
            <tr>
                <td style="border: none; padding: 2px 5px;"><strong>Duration:</strong></td>
                <td style="border: none; padding: 2px 5px;">{{ $jobLog->formatted_duration }}</td>
                <td style="border: none; padding: 2px 5px;"><strong>Generated On:</strong></td>
                <td style="border: none; padding: 2px 5px;">{{ $exportDate }}</td>
            </tr>
        </table>
    </div>

    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-label">Total Processed</div>
            <div class="summary-value">{{ $jobLog->processed }}</div>
        </div>
        <div class="summary-card success">
            <div class="summary-label">Successful</div>
            <div class="summary-value">{{ $jobLog->successful }}</div>
        </div>
        <div class="summary-card danger">
            <div class="summary-label">Failed</div>
            <div class="summary-value">{{ $jobLog->failed }}</div>
        </div>
        <div class="summary-card info">
            <div class="summary-label">Total Penalty</div>
            <div class="summary-value">TZS {{ number_format($jobLog->total_amount ?? 0, 2) }}</div>
        </div>
    </div>

    @if(!empty($details))
        <h3 style="font-size: 11px; margin-bottom: 10px; color: #d32f2f;">
            <strong>Penalty Accrual Details</strong>
        </h3>

        <table>
            <thead>
                <tr>
                    <th style="width: 4%;">#</th>
                    <th style="width: 12%;">Loan No</th>
                    <th style="width: 18%;">Customer Name</th>
                    <th style="width: 10%;">Due Date</th>
                    <th style="width: 12%;" class="text-right">Base Amount</th>
                    <th style="width: 12%;" class="text-center">Penalty Rate</th>
                    <th style="width: 12%;" class="text-center">Deduction Type</th>
                    <th style="width: 12%;" class="text-right">Penalty Amount</th>
                    <th style="width: 8%;" class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @php 
                    $totalPenalty = 0;
                    $deductionLabels = [
                        'over_due_principal_amount' => 'Overdue Principal',
                        'over_due_interest_amount' => 'Overdue Interest',
                        'over_due_principal_and_interest' => 'Principal + Interest',
                        'total_principal_amount_released' => 'Total Principal'
                    ];
                @endphp
                @forelse($details as $index => $detail)
                    @php $totalPenalty += $detail['penalty_amount'] ?? 0; @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $detail['loan_no'] ?? 'N/A' }}</td>
                        <td>{{ $detail['customer_name'] ?? 'N/A' }}</td>
                        <td>{{ isset($detail['due_date']) ? \Carbon\Carbon::parse($detail['due_date'])->format('d-m-Y') : 'N/A' }}</td>
                        <td class="text-right">TZS {{ number_format($detail['base_amount'] ?? 0, 2) }}</td>
                        <td class="text-center">
                            @if(isset($detail['penalty_type']) && $detail['penalty_type'] === 'percentage')
                                {{ number_format($detail['penalty_rate'] ?? 0, 2) }}%
                                <br><small style="font-size: 7px;">{{ $detail['frequency_cycle'] ?? 'monthly' }}</small>
                            @else
                                TZS {{ number_format($detail['penalty_rate'] ?? 0, 2) }}
                                <br><small style="font-size: 7px;">{{ $detail['frequency_cycle'] ?? 'monthly' }}</small>
                            @endif
                        </td>
                        <td class="text-center">
                            <small>{{ $deductionLabels[$detail['deduction_type'] ?? ''] ?? ($detail['deduction_type'] ?? 'N/A') }}</small>
                        </td>
                        <td class="text-right">TZS {{ number_format($detail['penalty_amount'] ?? 0, 2) }}</td>
                        <td class="text-center">
                            @if(isset($detail['error']))
                                <span class="badge badge-danger">Failed</span>
                            @else
                                <span class="badge badge-success">Success</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center">No details available.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" class="text-right"><strong>TOTAL PENALTY ACCRUED:</strong></td>
                    <td class="text-right"><strong>TZS {{ number_format($totalPenalty, 2) }}</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    @else
        <div style="text-align: center; padding: 20px; background: #f5f5f5; border-radius: 5px;">
            <p>No detailed information available for this job.</p>
        </div>
    @endif

    @if($jobLog->error_message)
        <div style="margin-top: 15px; padding: 10px; background: #ffebee; border-left: 4px solid #f44336;">
            <strong style="color: #c62828;">Error Message:</strong>
            <p style="margin-top: 5px; color: #333;">{{ $jobLog->error_message }}</p>
        </div>
    @endif

    <div class="footer">
        <p>Generated by {{ auth()->user()->name ?? 'System' }} on {{ $exportDate }}</p>
        <p>{{ $company->name ?? '' }} - {{ $company->address ?? '' }}</p>
    </div>
</body>
</html>
