<?php

namespace App\Services;

use App\Helpers\SmsHelper;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Loan;
use App\Models\LoanBill;
use App\Services\DcbEpgAuthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LoanSmsNotificationService
{
    public function sendDisbursementNotification(Loan $loan, ?string $customerFallbackMessage = null): void
    {
        if (!SmsHelper::isEventEnabled('loan_disbursement')) {
            return;
        }

        try {
            $loan->loadMissing(['customer', 'schedule', 'product', 'branch.company']);
            $customer = $loan->customer;

            if (!$customer || empty($customer->phone1)) {
                Log::info('Skipping disbursement SMS — customer phone missing', ['loan_id' => $loan->id]);

                return;
            }

            $firstSchedule = $loan->schedule->sortBy('due_date')->first();
            if (!$firstSchedule) {
                Log::info('Skipping disbursement SMS — no schedule', ['loan_id' => $loan->id]);

                return;
            }

            $company = $this->resolveCompany($loan, $customer);
            $companyName = $company?->name ?? 'SMARTFINANCE';
            $companyPhone = $this->normalizePhone($company?->phone ?? '');

            $paymentAmount = ($firstSchedule->principal ?? 0)
                + ($firstSchedule->interest ?? 0)
                + ($firstSchedule->fee_amount ?? 0)
                + ($firstSchedule->penalty_amount ?? 0);

            $firstRepaymentDate = Carbon::parse($firstSchedule->due_date);
            $loanDate = Carbon::parse($loan->disbursed_on ?? $loan->date_applied)->format('d/m/Y');
            $cycleSwahili = $this->cycleToSwahili(
                $loan->product->repayment_cycle ?? $loan->interest_cycle ?? 'monthly'
            );

            $dcbPaymentNote = $this->dcbLoanPaymentNote($loan);

            $templateVars = [
                'customer_name' => $customer->name,
                'amount' => number_format((float) $loan->amount, 0),
                'loan_date' => $loanDate,
                'repayment_start_date' => $firstRepaymentDate->format('d/m/Y'),
                'payment_amount' => number_format($paymentAmount, 0),
                'cycle' => $cycleSwahili,
                'company_name' => $companyName,
                'company_phone' => $companyPhone,
                'loan_no' => $loan->loanNo ?? (string) $loan->id,
                'dcb_payment_note' => $dcbPaymentNote,
            ];

            $configuredTemplate = (string) config('services.sms.templates.loan_disbursement', '');

            $customerMessage = SmsHelper::resolveTemplate('loan_disbursement', $templateVars);
            if ($customerMessage === null) {
                $customerMessage = $customerFallbackMessage ?? "Umepokea mkopo wa Tsh {$templateVars['amount']} tarehe {$loanDate}, Marejesho yako yataanza {$templateVars['repayment_start_date']} na utakuwa unalipa Tsh {$templateVars['payment_amount']} {$cycleSwahili}. Asante. Ujumbe umetoka {$companyName}";
                if ($companyPhone !== '' && $customerFallbackMessage === null) {
                    $customerMessage .= " kwa mawasiliano piga {$companyPhone}";
                }
            }

            // .env / settings custom templates: auto-append DCB pay instruction unless template uses {dcb_payment_note}
            $customerMessage = $this->appendDcbNoteToDisbursementMessage(
                $customerMessage,
                $dcbPaymentNote,
                $configuredTemplate
            );

            $companyTemplateVars = $templateVars;
            $companyMessage = SmsHelper::resolveTemplate('loan_disbursement_company', $companyTemplateVars);
            if ($companyMessage === null) {
                $companyMessage = "Taarifa: Mkopo {$templateVars['loan_no']} wa Tsh {$templateVars['amount']} umetolewa kwa {$customer->name} tarehe {$loanDate}. {$companyName}";
            }

            $this->sendToCustomerAndCompany(
                $this->normalizePhone($customer->phone1),
                $companyPhone,
                $customerMessage,
                $companyMessage,
                'loan_disbursement',
                $loan->id,
                $this->shouldNotifyCompanyOnDisbursement()
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send disbursement SMS', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendRepaymentNotification(Loan $loan, float $amount, $paymentDate = null): void
    {
        if (!SmsHelper::isEventEnabled('loan_repayment')) {
            return;
        }

        try {
            $loan->refresh();
            $loan->loadMissing(['customer', 'branch.company', 'schedule.repayments', 'product']);
            $customer = $loan->customer;

            if (!$customer || empty($customer->phone1)) {
                Log::warning('Skipping repayment SMS — customer phone missing', ['loan_id' => $loan->id]);

                return;
            }

            $company = $this->resolveCompany($loan, $customer);
            $companyName = $company?->name ?? 'SMARTFINANCE';
            $companyPhone = $this->normalizePhone($company?->phone ?? '');
            $customerName = $customer->name ?? 'Mteja';
            $paymentDateFormatted = $paymentDate
                ? Carbon::parse($paymentDate)->format('d/m/Y')
                : now()->format('d/m/Y');
            $loanNo = $loan->loanNo ?? 'N/A';

            $balanceVars = $this->resolveRepaymentAmountVars($loan);

            $templateVars = [
                'customer_name' => $customerName,
                'amount' => number_format($amount, 0),
                'payment_date' => $paymentDateFormatted,
                'loan_no' => $loanNo,
                'company_name' => $companyName,
                'company_phone' => $companyPhone,
                'next_schedule_amount' => $balanceVars['next_schedule_amount'],
                'outstanding_amount' => $balanceVars['outstanding_amount'],
            ];

            $customerMessage = SmsHelper::resolveTemplate('loan_repayment', $templateVars);
            if ($customerMessage === null) {
                $customerMessage = "Habari! {$customerName}, Tumepokea marejesho ya Tsh {$templateVars['amount']} tarehe {$paymentDateFormatted} kutoka kwenye mkopo namba {$loanNo}. Asante. Ujumbe umetoka {$companyName}";
                if ($companyPhone !== '') {
                    $customerMessage .= " kwa mawasiliano tupigie {$companyPhone}";
                }
            }

            $companyMessage = SmsHelper::resolveTemplate('loan_repayment_company', $templateVars);
            if ($companyMessage === null) {
                $companyMessage = "Taarifa: {$customerName} amelipa Tsh {$templateVars['amount']} kwa mkopo {$loanNo} tarehe {$paymentDateFormatted}. {$companyName}";
            }

            $this->sendToCustomerAndCompany(
                $this->normalizePhone($customer->phone1),
                $companyPhone,
                $customerMessage,
                $companyMessage,
                'loan_repayment',
                $loan->id,
                $this->shouldNotifyCompanyOnRepayment()
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send repayment SMS', [
                'loan_id' => $loan->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * SMS when a follow-up / other loan bill is created.
     */
    public function sendLoanBillNotification(Loan $loan, LoanBill $bill): void
    {
        try {
            $loan->loadMissing(['customer', 'branch.company', 'product']);
            $customer = $loan->customer;

            if (! $customer || empty($customer->phone1)) {
                Log::info('Skipping loan bill SMS — customer phone missing', [
                    'loan_id' => $loan->id,
                    'bill_id' => $bill->id,
                ]);

                return;
            }

            $loan->unsetRelation('bills');
            $totalOutstanding = $loan->getTotalOutstandingAmount();
            $message = $this->buildLoanBillMessage(
                $loan,
                (float) $bill->amount,
                (string) $bill->description,
                $totalOutstanding
            );

            $phone = $this->normalizePhone($customer->phone1);
            $smsResult = SmsHelper::send($phone, $message);

            if (is_array($smsResult) && ($smsResult['success'] ?? false)) {
                Log::info('Loan bill SMS sent', [
                    'loan_id' => $loan->id,
                    'bill_id' => $bill->id,
                    'phone' => $phone,
                ]);
            } else {
                Log::warning('Loan bill SMS failed', [
                    'loan_id' => $loan->id,
                    'bill_id' => $bill->id,
                    'phone' => $phone,
                    'error' => is_array($smsResult) ? ($smsResult['error'] ?? 'Unknown') : $smsResult,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send loan bill SMS', [
                'loan_id' => $loan->id ?? null,
                'bill_id' => $bill->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Loan bill SMS body. Also used to preview the message before the bill is created.
     */
    public function buildLoanBillMessage(
        Loan $loan,
        float $amount,
        string $description,
        ?float $totalOutstanding = null
    ): string {
        $loan->loadMissing(['customer', 'branch.company']);
        $customer = $loan->customer;

        $company = $this->resolveCompany($loan, $customer);
        $companyName = $company?->name ?? 'SAFCO FINTECH';
        $companyPhone = $this->normalizePhone($company?->phone ?? '');
        $controlNo = trim((string) ($loan->loanNo ?? ''));

        $billAmount = number_format($amount, 0);
        $description = trim($description);
        $customerName = strtoupper(trim((string) ($customer->name ?? '')));

        $message = "{$customerName}, una bili ya Tsh {$billAmount}";
        if ($description !== '') {
            $message .= " ({$description})";
        }

        $loanAmount = number_format((float) $loan->amount, 0);
        $loanDateValue = $loan->disbursed_on ?? $loan->date_applied ?? $loan->created_at;
        $loanDate = $loanDateValue ? Carbon::parse($loanDateValue)->format('d/m/Y') : '—';
        $message .= " kwa mkopo wa kiasi cha Tsh {$loanAmount} wa tarehe {$loanDate}.";

        $totalOutstanding ??= $loan->getTotalOutstandingAmount() + $amount;
        $message .= ' Jumla ya salio la mkopo pamoja na bili hii ni Tsh '
            .number_format($totalOutstanding, 0).'.';

        if ($controlNo !== '' && ! str_starts_with($controlNo, 'TMP-')) {
            $message .= " Lipa kwa kutumia namba {$controlNo} kupitia benki zote au mitandao ya simu.";
        }

        $message .= " Asante. Ujumbe umetoka {$companyName}";
        if ($companyPhone !== '') {
            $message .= " kwa mawasiliano piga {$companyPhone}";
        }

        return $message;
    }

    /**
     * SMS after a partial or full loan bill payment.
     */
    public function sendLoanBillPaymentNotification(LoanBill $bill, float $paidAmount): void
    {
        try {
            $bill->loadMissing(['loan.customer', 'loan.branch.company']);
            $loan = $bill->loan;
            $customer = $loan?->customer;

            if (! $loan || ! $customer || empty($customer->phone1)) {
                Log::info('Skipping loan bill payment SMS — customer phone missing', [
                    'loan_bill_id' => $bill->id,
                    'loan_id' => $loan?->id,
                ]);

                return;
            }

            $message = $this->buildLoanBillPaymentMessage($bill, $paidAmount);
            $phone = $this->normalizePhone($customer->phone1);
            $smsResult = SmsHelper::send($phone, $message);

            if (is_array($smsResult) && ($smsResult['success'] ?? false)) {
                Log::info('Loan bill payment SMS sent', [
                    'loan_bill_id' => $bill->id,
                    'loan_id' => $loan->id,
                    'phone' => $phone,
                    'paid_amount' => $paidAmount,
                    'remaining_amount' => $bill->remaining_amount,
                ]);
            } else {
                Log::warning('Loan bill payment SMS failed', [
                    'loan_bill_id' => $bill->id,
                    'loan_id' => $loan->id,
                    'phone' => $phone,
                    'error' => is_array($smsResult) ? ($smsResult['error'] ?? 'Unknown') : $smsResult,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send loan bill payment SMS', [
                'loan_bill_id' => $bill->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function buildLoanBillPaymentMessage(LoanBill $bill, float $paidAmount): string
    {
        $bill->loadMissing(['loan.customer', 'loan.branch.company']);
        $loan = $bill->loan;
        $customer = $loan?->customer;
        $company = $loan ? $this->resolveCompany($loan, $customer) : null;
        $companyName = $company?->name ?? 'SAFCO FINTECH';
        $companyPhone = $this->normalizePhone($company?->phone ?? '');

        $billAmount = number_format((float) $bill->amount, 0);
        $paid = number_format($paidAmount, 0);
        $remaining = number_format((float) $bill->remaining_amount, 0);
        $description = trim((string) $bill->description);

        $message = "Bill ya Tsh {$billAmount}";
        if ($description !== '') {
            $message .= " ({$description})";
        }
        $message .= " imelipiwa kiasi cha Tsh {$paid} na imebaki Tsh {$remaining}.";
        $message .= " Asante. Ujumbe umetoka {$companyName}";
        if ($companyPhone !== '') {
            $message .= " kwa mawasiliano piga {$companyPhone}";
        }

        return $message;
    }

    private function sendToCustomerAndCompany(
        string $customerPhone,
        string $companyPhone,
        string $customerMessage,
        string $companyMessage,
        string $event,
        int $loanId,
        bool $notifyCompany = true
    ): void {
        if ($customerPhone !== '') {
            SmsHelper::send($customerPhone, $customerMessage, $event);
            Log::info('Loan SMS sent to customer', [
                'loan_id' => $loanId,
                'event' => $event,
                'phone' => $customerPhone,
            ]);
        }

        if (!$notifyCompany) {
            return;
        }

        if ($companyPhone === '' || $companyPhone === $customerPhone) {
            if ($companyPhone === '') {
                Log::info('Loan SMS company copy skipped — company phone not set', [
                    'loan_id' => $loanId,
                    'event' => $event,
                ]);
            }

            return;
        }

        SmsHelper::send($companyPhone, $companyMessage, $event);
        Log::info('Loan SMS sent to company', [
            'loan_id' => $loanId,
            'event' => $event,
            'phone' => $companyPhone,
        ]);
    }

    private function resolveCompany(Loan $loan, $customer): ?Company
    {
        if ($loan->relationLoaded('branch') && $loan->branch?->company) {
            return $loan->branch->company;
        }

        if ($loan->branch_id) {
            $branch = Branch::with('company')->find($loan->branch_id);
            if ($branch?->company) {
                return $branch->company;
            }
        }

        if ($customer->company_id ?? null) {
            $company = Company::find($customer->company_id);
            if ($company) {
                return $company;
            }
        }

        if (function_exists('current_company')) {
            return current_company();
        }

        return auth()->user()?->company;
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', (string) $phone);
    }

    private function cycleToSwahili(string $cycle): string
    {
        return match (strtolower($cycle)) {
            'daily' => 'kila siku',
            'weekly' => 'kila wiki',
            'bi_weekly', 'bimonthly' => 'kila wiki mbili',
            'quarterly' => 'kila robo mwaka',
            'semi_annually' => 'kila nusu mwaka',
            'annually' => 'kila mwaka',
            default => 'kila mwezi',
        };
    }

    /**
     * Remaining due on the next unpaid installment and total outstanding across all schedules (after payment).
     */
    private function resolveRepaymentAmountVars(Loan $loan): array
    {
        $schedules = $loan->schedule
            ->filter(fn ($schedule) => ($schedule->status ?? '') !== 'restructured')
            ->sortBy('due_date');

        $outstanding = 0.0;
        $nextScheduleAmount = 0.0;

        foreach ($schedules as $schedule) {
            $remaining = (float) $schedule->remaining_amount;
            if ($remaining <= 0) {
                continue;
            }

            $outstanding += $remaining;
            if ($nextScheduleAmount <= 0) {
                $nextScheduleAmount = $remaining;
            }
        }

        return [
            'next_schedule_amount' => number_format($nextScheduleAmount, 0),
            'outstanding_amount' => number_format($outstanding, 0),
        ];
    }

    /**
     * Append DCB payment line for custom .env/UI templates that omit {dcb_payment_note}.
     */
    private function appendDcbNoteToDisbursementMessage(
        string $message,
        string $dcbPaymentNote,
        string $configuredTemplate
    ): string {
        if ($dcbPaymentNote === '') {
            return $message;
        }

        if ($configuredTemplate !== '' && str_contains($configuredTemplate, '{dcb_payment_note}')) {
            return $message;
        }

        if (preg_match('/namba\s+(\S+)/u', trim($dcbPaymentNote), $matches)
            && str_contains($message, $matches[1])) {
            return $message;
        }

        return $message.$dcbPaymentNote;
    }

    /**
     * Swahili payment instruction when DCB EPG is enabled (loan number as control no).
     */
    private function dcbLoanPaymentNote(Loan $loan): string
    {
        if (! app(DcbEpgAuthService::class)->isEnabled()) {
            return '';
        }

        $loanNo = trim((string) ($loan->loanNo ?? $loan->id));
        if ($loanNo === '' || str_starts_with($loanNo, 'TMP-')) {
            return '';
        }

        return " Lipa kwa kutumia namba {$loanNo} kupitia benki zote au mitandao ya simu.";
    }

    private function shouldNotifyCompanyOnDisbursement(): bool
    {
        return config('services.sms.loan_disbursement_recipients', 'customer') === 'customer_and_company';
    }

    private function shouldNotifyCompanyOnRepayment(): bool
    {
        return config('services.sms.loan_repayment_recipients', 'customer') === 'customer_and_company';
    }
}
