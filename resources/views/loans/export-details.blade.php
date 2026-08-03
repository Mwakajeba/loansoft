<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Repayment Schedule</title>
    <style>
        @page {
            margin: 15mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .header {
            display: flex;
            justify-content: space-between;
            border-bottom: 3px solid #006400;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .logo-container {
            margin-bottom: 10px;
        }
        .logo-container img {
            max-height: 100px;
            max-width: 300px;
            display: block;
            margin-bottom: 5px;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #006400;
            margin-top: 5px;
        }
        .bank-address {
            font-size: 10px;
            margin-top: 5px;
            color: #444;
        }
        .title-section {
            text-align: right;
        }
        .title-section h2 {
            margin: 0;
            color: #006400;
            font-size: 18px;
            font-weight: bold;
        }
        .loan-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            border: 2px solid #006400;
            border-left: 4px solid #006400;
            padding: 12px;
            background: #f9fafb;
        }
        .loan-details-left,
        .loan-details-right {
            width: 48%;
        }
        .loan-details-left p,
        .loan-details-right p {
            margin: 5px 0;
            font-size: 11px;
        }
        .loan-details-left p strong,
        .loan-details-right p strong {
            display: inline-block;
            width: 140px;
        }
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10px;
        }
        .schedule-table th {
            background-color: #006400;
            color: #fff;
            border: 1px solid #006400;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
        }
        .schedule-table td {
            border: 1px solid #000;
            padding: 6px 5px;
            text-align: right;
        }
        .schedule-table td:first-child {
            text-align: center;
        }
        .schedule-table tfoot td {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: right;
        }
        .schedule-table tfoot td:first-child {
            text-align: center;
        }
        .footer {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }
        .loan-officer {
            font-size: 10px;
        }
        .stamp {
            text-align: center;
            border: 2px solid #006400;
            border-radius: 50%;
            width: 100px;
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            padding: 5px;
            box-sizing: border-box;
        }
        .stamp-content {
            text-align: center;
        }
        .payment-breakdown {
            margin-bottom: 20px;
            border: 2px solid #006400;
            border-left: 4px solid #006400;
            padding: 12px;
            background: #f9fafb;
        }
        .payment-breakdown-title {
            font-size: 14px;
            font-weight: bold;
            color: #006400;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .payment-breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px dotted #dee2e6;
        }
        .payment-breakdown-item:last-child {
            border-bottom: none;
        }
        .payment-breakdown-label {
            font-weight: 600;
            color: #555;
        }
        .payment-breakdown-value {
            font-weight: bold;
            color: #006400;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
        <div class="logo-container">
            @php
                $logoBase64 = null;
                // DomPDF requires the PHP GD extension to render images
                if (extension_loaded('gd')) {
                    $logoPath = null;
                    if ($company && $company->logo) {
                        $companyLogoPath = storage_path('app/public/' . $company->logo);
                        if (file_exists($companyLogoPath)) {
                            $logoPath = $companyLogoPath;
                        } else {
                            $companyLogoPath = public_path('storage/' . $company->logo);
                            if (file_exists($companyLogoPath)) {
                                $logoPath = $companyLogoPath;
                            }
                        }
                    }
                    if (!$logoPath) {
                        $defaultLogoPath = public_path('assets/images/logo.png');
                        if (file_exists($defaultLogoPath)) {
                            $logoPath = $defaultLogoPath;
                        }
                    }
                    if ($logoPath && file_exists($logoPath)) {
                        $logoData = file_get_contents($logoPath);
                        $logoBase64 = 'data:' . mime_content_type($logoPath) . ';base64,' . base64_encode($logoData);
                    }
                }
            @endphp
            @if($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="{{ $company->name ?? 'Company' }} Logo" />
                @endif
                <div class="company-name">{{ $company->name ?? 'Company' }}</div>
            </div>
            <div class="bank-address">
                @if(!empty($company->address))
                    {{ $company->address }}<br>
                @endif
                @if(!empty($company->phone) || !empty($company->email))
                    @if(!empty($company->phone))Tel: {{ $company->phone }}@endif
                    @if(!empty($company->phone) && !empty($company->email)) | @endif
                    @if(!empty($company->email)){{ $company->email }}@endif
                @endif
                </div>
        </div>
        <div class="title-section">
            <h2>Loan Statement</h2>
    </div>
    </div>

    <div class="loan-details">
        <div class="loan-details-left">
            <p><strong>Branch:</strong> {{ $loan->branch->name ?? 'N/A' }}</p>
            <p><strong>Loan Number:</strong> {{ $loan->loanNo ?? 'N/A' }}</p>
            <p><strong>Customer Name:</strong> {{ $loan->customer->name ?? 'N/A' }}</p>
            <p><strong>Customer No:</strong> {{ $loan->customer->customerNo ?? 'N/A' }}</p>
            @if($loan->group)
            <p><strong>Group:</strong> {{ $loan->group->name ?? 'N/A' }}</p>
            @endif
                </div>
        <div class="loan-details-right">
            <p><strong>Loan Amount:</strong> {{ number_format($loan->amount, 2) }}</p>
            <p><strong>Total Amount:</strong> {{ number_format($loan->amount_total ?? $loan->amount, 2) }}</p>
            <p><strong>Term:</strong> {{ $loan->period }} {{ $loan->period == 1 ? 'Month' : 'Months' }}</p>
            <p><strong>Interest Rate:</strong> {{ number_format($loan->interest, 2) }}%</p>
            <p><strong>Interest Method:</strong> 
                        @php
                            $method = strtolower($loan->product->interest_method ?? '');
                            $methodLabel = match($method) {
                                'reducing_balance_with_equal_installment' => 'Reducing Balance with Equal Installment',
                                'reducing_balance_with_equal_principal' => 'Reducing Balance with Equal Principal',
                                'flat_rate' => 'Flat Rate',
                                default => $loan->product->interest_method ?? 'N/A'
                            };
                        @endphp
                        {{ $methodLabel }}
            </p>
            <p><strong>Value Date:</strong> {{ ($loan->disbursed_on ?? $loan->date_applied) ? \Carbon\Carbon::parse($loan->disbursed_on ?? $loan->date_applied)->format('d/m/Y') : 'N/A' }}</p>
            @if(isset($totalPaid))
            <p><strong>Total Paid:</strong> <span style="color: #006400; font-weight: bold;">TZS {{ number_format($totalPaid, 2) }}</span></p>
            @endif
            @if(isset($remainingBalance))
            <p><strong>Running Balance:</strong> <span style="color: #dc3545; font-weight: bold;">TZS {{ number_format($remainingBalance, 2) }}</span></p>
            @endif
            @if(isset($totalOutstandingPenalty) && $totalOutstandingPenalty > 0)
            <p><strong>Outstanding Penalty:</strong> <span style="color: #dc3545; font-weight: bold;">TZS {{ number_format($totalOutstandingPenalty, 2) }}</span></p>
            @endif
                </div>
            </div>

    <!-- Payment Breakdown Section -->
    <div class="payment-breakdown">
        <div class="payment-breakdown-title">Payment Breakdown</div>
        @if(isset($totalPrincipalPaid))
        <div class="payment-breakdown-item">
            <span class="payment-breakdown-label">Principal Paid:</span>
            <span class="payment-breakdown-value">TZS {{ number_format($totalPrincipalPaid, 2) }}</span>
                </div>
                @endif
        @if(isset($totalInterestPaid))
        <div class="payment-breakdown-item">
            <span class="payment-breakdown-label">Interest Paid:</span>
            <span class="payment-breakdown-value">TZS {{ number_format($totalInterestPaid, 2) }}</span>
                </div>
                @endif
        @if(isset($totalPenaltyCharged) && $totalPenaltyCharged > 0)
        <div class="payment-breakdown-item">
            <span class="payment-breakdown-label">Penalty Charged:</span>
            <span class="payment-breakdown-value">TZS {{ number_format($totalPenaltyCharged, 2) }}</span>
        </div>
        @endif
        @if(isset($totalOutstandingPenalty) && $totalOutstandingPenalty > 0)
        <div class="payment-breakdown-item">
            <span class="payment-breakdown-label">Outstanding Penalty:</span>
            <span class="payment-breakdown-value" style="color: #dc3545;">TZS {{ number_format($totalOutstandingPenalty, 2) }}</span>
        </div>
        @endif
        @if(isset($totalPenaltiesPaid))
        <div class="payment-breakdown-item">
            <span class="payment-breakdown-label">Penalty Paid:</span>
            <span class="payment-breakdown-value">TZS {{ number_format($totalPenaltiesPaid, 2) }}</span>
                </div>
                @endif
        @if(isset($totalFeesPaid))
        <div class="payment-breakdown-item">
            <span class="payment-breakdown-label">Fees Paid:</span>
            <span class="payment-breakdown-value">TZS {{ number_format($totalFeesPaid, 2) }}</span>
        </div>
        @endif
    </div>

    <table class="schedule-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Opening Balance</th>
                <th>Principal</th>
                <th>Interest</th>
                <th>Penalty</th>
                <th>Installment</th>
                <th>Principal Paid</th>
                <th>Interest Paid</th>
                <th>Penalty Paid</th>
                <th>Closing Balance</th>
            </tr>
        </thead>
        <tbody>
            @php
                $openingBalance = $loan->amount;
                $totalInterest = 0;
                $totalPrincipal = 0;
                $totalInstallment = 0;
                $totalPrincipalPaid = 0;
                $totalInterestPaid = 0;
                $totalPenaltyPaid = 0;
                $totalPenaltyChargedRows = 0;
            @endphp
            @foreach($loan->schedule->sortBy('due_date') as $schedule)
                @php
                    // Get payments for this schedule from repayments
                    $principalPaidForSchedule = 0;
                    $interestPaidForSchedule = 0;
                    $penaltyPaidForSchedule = 0;
                    
                    if ($schedule->relationLoaded('repayments') && $schedule->repayments) {
                        $principalPaidForSchedule = $schedule->repayments->sum('principal');
                        $interestPaidForSchedule = $schedule->repayments->sum('interest');
                        $penaltyPaidForSchedule = $schedule->repayments->sum('penalt_amount');
                    } else {
                        // Fallback: query repayments if not loaded
                        $repayments = \App\Models\Repayment::where('loan_schedule_id', $schedule->id)->get();
                        $principalPaidForSchedule = $repayments->sum('principal');
                        $interestPaidForSchedule = $repayments->sum('interest');
                        $penaltyPaidForSchedule = $repayments->sum('penalt_amount');
                    }
                    
                    // Get scheduled amounts
                    $scheduledPrincipal = $schedule->principal;
                    $interestForPeriod = $schedule->accrued_interest ?? $schedule->interest;
                    $penaltyForPeriod = (float) ($schedule->penalty_amount ?? 0);
                    $feeForPeriod = (float) ($schedule->fee_amount ?? 0);
                    $installmentAmount = $scheduledPrincipal + $interestForPeriod + $penaltyForPeriod + $feeForPeriod;
                    
                    // Calculate closing balance (outstanding principal after payment)
                    $closingBalance = $openingBalance - $principalPaidForSchedule;
                    
                    // Accumulate totals
                    $totalPrincipal += $scheduledPrincipal;
                    $totalInterest += $interestForPeriod;
                    $totalInstallment += $installmentAmount;
                    $totalPrincipalPaid += $principalPaidForSchedule;
                    $totalInterestPaid += $interestPaidForSchedule;
                    $totalPenaltyPaid += $penaltyPaidForSchedule;
                    $totalPenaltyChargedRows += $penaltyForPeriod;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($schedule->due_date)->format('d/m/Y') }}</td>
                    <td>{{ number_format($openingBalance, 2) }}</td>
                    <td>{{ number_format($scheduledPrincipal, 2) }}</td>
                    <td>{{ number_format($interestForPeriod, 2) }}</td>
                    <td>{{ number_format($penaltyForPeriod, 2) }}</td>
                    <td>{{ number_format($installmentAmount, 2) }}</td>
                    <td>{{ number_format($principalPaidForSchedule, 2) }}</td>
                    <td>{{ number_format($interestPaidForSchedule, 2) }}</td>
                    <td>{{ number_format($penaltyPaidForSchedule, 2) }}</td>
                    <td>{{ number_format($closingBalance, 2) }}</td>
                </tr>
                @php
                    // Update opening balance for next period (closing balance becomes next opening balance)
                    $openingBalance = $closingBalance;
                @endphp
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td><strong>-</strong></td>
                <td><strong>{{ number_format($totalPrincipal, 2) }}</strong></td>
                <td><strong>{{ number_format($totalInterest, 2) }}</strong></td>
                <td><strong>{{ number_format($totalPenaltyChargedRows, 2) }}</strong></td>
                <td><strong>{{ number_format($totalInstallment, 2) }}</strong></td>
                <td><strong>{{ number_format($totalPrincipalPaid, 2) }}</strong></td>
                <td><strong>{{ number_format($totalInterestPaid, 2) }}</strong></td>
                <td><strong>{{ number_format($totalPenaltyPaid, 2) }}</strong></td>
                <td><strong>{{ number_format($openingBalance, 2) }}</strong></td>
            </tr>
        </tfoot>
    </table>

    <!-- Repayment History Section -->
    @if($loan->repayments && $loan->repayments->count() > 0)
    <div style="margin-top: 30px; page-break-inside: avoid;">
        <div style="background-color: #006400; color: #fff; padding: 12px 15px; font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">
            Repayment History
        </div>
        <table class="schedule-table">
            <thead>
                <tr>
                    <th>Payment Date</th>
                    <th>Due Date</th>
                    <th>Principal</th>
                    <th>Interest</th>
                    <th>Penalty</th>
                    <th>Fees</th>
                    <th>Total Amount</th>
                    <th>Payment Method</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $repaymentTotalPrincipal = 0;
                    $repaymentTotalInterest = 0;
                    $repaymentTotalPenalty = 0;
                    $repaymentTotalFees = 0;
                    $repaymentGrandTotal = 0;
                @endphp
                @foreach($loan->repayments->sortBy('payment_date') as $repayment)
                @php
                        $totalAmount = $repayment->principal + $repayment->interest + $repayment->penalt_amount + $repayment->fee_amount;
                        $repaymentTotalPrincipal += $repayment->principal;
                        $repaymentTotalInterest += $repayment->interest;
                        $repaymentTotalPenalty += $repayment->penalt_amount;
                        $repaymentTotalFees += $repayment->fee_amount;
                        $repaymentGrandTotal += $totalAmount;
                @endphp
                <tr>
                        <td>{{ \Carbon\Carbon::parse($repayment->payment_date)->format('d/m/Y') }}</td>
                        <td>{{ $repayment->due_date ? \Carbon\Carbon::parse($repayment->due_date)->format('d/m/Y') : 'N/A' }}</td>
                        <td>{{ number_format($repayment->principal, 2) }}</td>
                        <td>{{ number_format($repayment->interest, 2) }}</td>
                        <td>{{ number_format($repayment->penalt_amount, 2) }}</td>
                        <td>{{ number_format($repayment->fee_amount, 2) }}</td>
                        <td>{{ number_format($totalAmount, 2) }}</td>
                        <td>
                            @php
                                $paymentMethod = 'N/A';
                                if ($repayment->relationLoaded('chartAccount') && $repayment->chartAccount) {
                                    $paymentMethod = $repayment->chartAccount->account_name;
                                } elseif ($repayment->bank_account_id) {
                                    $chartAccount = \App\Models\ChartAccount::find($repayment->bank_account_id);
                                    $paymentMethod = $chartAccount ? $chartAccount->account_name : 'N/A';
                                }
                            @endphp
                            {{ $paymentMethod }}
                        </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><strong>Total</strong></td>
                    <td><strong>{{ number_format($repaymentTotalPrincipal, 2) }}</strong></td>
                    <td><strong>{{ number_format($repaymentTotalInterest, 2) }}</strong></td>
                    <td><strong>{{ number_format($repaymentTotalPenalty, 2) }}</strong></td>
                    <td><strong>{{ number_format($repaymentTotalFees, 2) }}</strong></td>
                    <td><strong>{{ number_format($repaymentGrandTotal, 2) }}</strong></td>
                    <td><strong>-</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif

    <div class="footer">
        <div class="loan-officer">
            <p><strong>Loan Officer:</strong> {{ $loan->loanOfficer->name ?? 'N/A' }}</p>
            <p><strong>Exported:</strong> {{ $exportDate ?? now()->format('Y-m-d H:i:s') }}</p>
        </div>
    </div>
</body>
</html>

