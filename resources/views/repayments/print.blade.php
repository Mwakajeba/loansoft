<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $receiptData['receipt_number'] ?? '' }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: "Courier New", monospace;
            font-size: 11px;
            margin: 0;
            padding: 6px;
            width: 280px;
        }
        .header { text-align: center; margin-bottom: 8px; }
        .title { font-size: 14px; font-weight: bold; margin-bottom: 4px; }
        .subtitle { font-size: 11px; margin-bottom: 6px; }
        .divider { border-top: 1px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; margin: 2px 0; }
        .label { font-weight: bold; }
        .section-title { font-weight: bold; margin-top: 6px; text-decoration: underline; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 6px; }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <div class="title">LOAN REPAYMENT RECEIPT</div>
        <div class="subtitle">{{ $receiptData['branch'] ?? '' }}</div>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span class="label">Receipt #:</span>
        <span>{{ $receiptData['receipt_number'] ?? 'N/A' }}</span>
    </div>
    <div class="row">
        <span class="label">Date:</span>
        <span>{{ \Carbon\Carbon::parse($receiptData['date'])->format('d/m/Y') }}</span>
    </div>
    <div class="row">
        <span class="label">Customer:</span>
        <span>{{ $receiptData['customer_name'] ?? '' }}</span>
    </div>
    <div class="row">
        <span class="label">Loan #:</span>
        <span>{{ $receiptData['loan_number'] ?? '' }}</span>
    </div>

    <div class="divider"></div>

    <div class="section-title">PAYMENT</div>
    <div class="row">
        <span>Principal</span>
        <span>{{ number_format($receiptData['payment_breakdown']['principal'] ?? 0, 2) }}</span>
    </div>
    <div class="row">
        <span>Interest</span>
        <span>{{ number_format($receiptData['payment_breakdown']['interest'] ?? 0, 2) }}</span>
    </div>
    <div class="row">
        <span>Penalty</span>
        <span>{{ number_format($receiptData['payment_breakdown']['penalty'] ?? 0, 2) }}</span>
    </div>
    <div class="row">
        <span>Fee</span>
        <span>{{ number_format($receiptData['payment_breakdown']['fee'] ?? 0, 2) }}</span>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span class="label">Total Paid</span>
        <span class="label">{{ number_format($receiptData['amount_paid'] ?? 0, 2) }}</span>
    </div>

    <div class="divider"></div>

    <div class="section-title">SCHEDULE STATUS</div>
    <div class="row">
        <span>Schedule #:</span>
        <span>{{ $receiptData['schedule_number'] ?? '' }}</span>
    </div>
    <div class="row">
        <span>Due Date:</span>
        <span>{{ \Carbon\Carbon::parse($receiptData['due_date'])->format('d/m/Y') }}</span>
    </div>
    <div class="row">
        <span>Remaining Schedules:</span>
        <span>{{ $receiptData['remaining_schedules_count'] ?? 0 }}</span>
    </div>
    <div class="row">
        <span>Remaining Amount:</span>
        <span>{{ number_format($receiptData['remaining_schedules_amount'] ?? 0, 2) }}</span>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span>Bank A/C:</span>
        <span>{{ $receiptData['bank_account'] ?? 'N/A' }}</span>
    </div>
    <div class="row mt-2">
        <span>Received By:</span>
        <span>{{ $receiptData['received_by'] ?? '' }}</span>
    </div>

    <div class="divider"></div>

    <div class="text-center mt-2">
        <div>Thank you for your payment.</div>
    </div>
</body>
</html>

