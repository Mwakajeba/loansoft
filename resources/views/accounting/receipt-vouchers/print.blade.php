<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt #{{ $receipt->id }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        body {
            font-family: "Courier New", monospace;
            font-size: 11px;
            margin: 0;
            padding: 6px;
            width: 280px;
        }
        .header { text-align: center; margin-bottom: 8px; }
        .title { font-size: 14px; font-weight: bold; margin-bottom: 3px; }
        .subtitle { font-size: 11px; margin-bottom: 4px; }
        .divider { border-top: 1px dashed #000; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; margin: 2px 0; }
        .label { font-weight: bold; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 6px; }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <div class="title">{{ $company->name ?? 'SMARTFINANCE' }}</div>
        @if(!empty($company?->address))
            <div class="subtitle">{{ $company->address }}</div>
        @endif
        @if(!empty($company?->phone))
            <div class="subtitle">Tel: {{ $company->phone }}</div>
        @endif
        <div class="subtitle">{{ $receipt->branch->name ?? '' }}</div>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span class="label">Receipt #:</span>
        <span>{{ $receipt->reference ?? $receipt->id }}</span>
    </div>
    <div class="row">
        <span class="label">Date:</span>
        <span>{{ $receipt->date?->format('d/m/Y') }}</span>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span class="label">Received From:</span>
        <span>{{ $receipt->payee_display_name }}</span>
    </div>
    <div class="row">
        <span class="label">Bank:</span>
        <span>{{ $receipt->bankAccount->name ?? 'N/A' }}</span>
    </div>

    <div class="divider"></div>

    <div class="row">
        <span class="label">Amount:</span>
        <span class="label">TZS {{ number_format($receipt->amount, 2) }}</span>
    </div>

    @if($receipt->description)
        <div class="divider"></div>
        <div class="row">
            <span class="label">Description:</span>
        </div>
        <div>{{ $receipt->description }}</div>
    @endif

    <div class="divider"></div>

    <div class="row mt-2">
        <span>Received By:</span>
        <span>{{ $receipt->user->name ?? 'System' }}</span>
    </div>

    <div class="divider"></div>

    <div class="text-center mt-2">
        <div>Thank you for your payment.</div>
    </div>
</body>
</html>

