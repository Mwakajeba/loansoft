<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\DcbTransaction;
use App\Models\Loan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DcbPaymentService
{
    public const REF_LOAN_REPAYMENT = 'loan_repayment';

    public function __construct(
        private readonly DcbGatewayService $gateway,
        private readonly LoanDisbursementCompletionService $disbursementCompletion,
        private readonly LoanRepaymentService $loanRepaymentService
    ) {}

    public static function generateClientReference(?string $prefix = 'SF'): string
    {
        $base = strtoupper($prefix) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));

        return substr($base, 0, 32);
    }

    /**
     * DCB client_reference: first 4 chars = business ID, then loan number, then operation + unique suffix (max 32).
     * Example: 1234SF-42R1A2 (business 1234, loan SF-42, repayment R, suffix 1A2).
     */
    public static function generateLoanClientReference(Loan $loan, string $operation = 'R'): string
    {
        $businessId = preg_replace('/[^A-Za-z0-9]/', '', (string) config('services.dcb.business_id', ''));
        $prefix = strtoupper(substr(str_pad($businessId, 4, '0', STR_PAD_LEFT), 0, 4));

        $loanNo = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($loan->loanNo ?? ''));
        if ($loanNo === '') {
            $loanNo = 'L' . $loan->id;
        }
        $loanNo = strtoupper($loanNo);

        $op = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $operation), 0, 1)) ?: 'R';
        $suffix = $op . strtoupper(substr(Str::random(3), 0, 3));

        $maxLoanLen = 32 - strlen($prefix) - strlen($suffix);
        $loanPart = substr($loanNo, 0, max(1, $maxLoanLen));

        return substr($prefix . $loanPart . $suffix, 0, 32);
    }

    public function isEnabled(): bool
    {
        return config('services.dcb.enabled') && $this->gateway->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function initiateTransfer(array $params, ?string $referenceType = null, ?int $referenceId = null, ?array $meta = null): array
    {
        if (!$this->gateway->isConfigured()) {
            return ['success' => false, 'message' => 'DCB gateway is not configured.'];
        }

        $clientReference = $params['client_reference']
            ?? self::generateClientReference($referenceType ? strtoupper(substr($referenceType, 0, 6)) : 'SF');

        $amount = (int) round((float) ($params['amount'] ?? 0));
        if ($amount < 1) {
            return ['success' => false, 'message' => 'Amount must be at least 1 TZS.'];
        }

        $storedDestination = $params['destination_account']
            ?? ($referenceType === self::REF_LOAN_REPAYMENT ? ($params['msisdn'] ?? 'gateway') : '');

        $transaction = DcbTransaction::create([
            'company_id' => current_company_id(),
            'user_id' => Auth::id(),
            'type' => 'transfer',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'client_reference' => $clientReference,
            'destination_account' => $storedDestination,
            'institution_code' => $params['institution_code'],
            'institution_name' => $params['institution_name'] ?? null,
            'amount' => $amount,
            'beneficiary_name' => Str::limit($params['beneficiary_name'], 120, ''),
            'msisdn' => $params['msisdn'],
            'sender_name' => Str::limit($params['sender_name'] ?? config('services.dcb.sender_name', config('app.name')), 120, ''),
            'purpose' => isset($params['purpose']) ? Str::limit($params['purpose'], 32, '') : null,
            'status' => DcbTransaction::STATUS_PENDING,
            'meta' => $meta,
        ]);

        $payload = [
            'institution_code' => $params['institution_code'],
            'amount' => $amount,
            'beneficiary_name' => $transaction->beneficiary_name,
            'msisdn' => $params['msisdn'],
            'sender_name' => $transaction->sender_name,
            'client_reference' => $clientReference,
        ];

        // Disbursement: destination is the customer wallet. Repayment: receiving account is on the gateway server.
        if (!empty($params['destination_account'])) {
            $payload['destination_account'] = $params['destination_account'];
            if (!empty($params['normalize_destination'])) {
                $payload['normalize_destination'] = true;
            }
            if (!empty($params['strip_destination_leading_zeros'])) {
                $payload['strip_destination_leading_zeros'] = true;
            }
        }

        if (!empty($params['purpose'])) {
            $payload['purpose'] = $transaction->purpose;
        }
        if (!empty($params['customer_no'])) {
            $payload['customer_no'] = $params['customer_no'];
        }

        $response = $this->gateway->transfer($payload);

        if ($response['success'] ?? false) {
            $transaction->markSubmitted($response);

            $result = [
                'success' => true,
                'pending' => !$this->isGatewayFinalSuccess($response),
                'message' => $response['data']['message'] ?? 'Transfer submitted to DCB gateway.',
                'transaction' => $transaction->fresh(),
                'gateway' => $response,
            ];

            if (!$result['pending']) {
                $this->finalizeSuccessfulTransaction($transaction->fresh());
                $result['completed'] = true;
            }

            return $result;
        }

        $transaction->markFailed(
            $response['message'] ?? ($response['data']['message'] ?? 'Transfer rejected by gateway.'),
            $response
        );

        return [
            'success' => false,
            'message' => $transaction->message,
            'transaction' => $transaction->fresh(),
            'gateway' => $response,
        ];
    }

    /**
     * Disburse loan to customer wallet via DCB.
     *
     * @param  array<string, mixed>  $params
     */
    public function disburseLoan(Loan $loan, array $params): array
    {
        $customer = $loan->customer;
        if (!$customer) {
            return ['success' => false, 'message' => 'Loan has no customer.'];
        }

        $amount = (int) ($params['amount'] ?? $this->disbursementCompletion->netDisbursementAmount($loan));
        $meta = [
            'disbursement_date' => $params['disbursement_date'] ?? now()->toDateString(),
            'approval_comments' => $params['approval_comments'] ?? null,
            'user_id' => Auth::id(),
        ];

        return $this->initiateTransfer([
            'destination_account' => $params['destination_account'] ?? $customer->phone1,
            'institution_code' => $params['institution_code'],
            'institution_name' => $params['institution_name'] ?? null,
            'amount' => $amount,
            'beneficiary_name' => $params['beneficiary_name'] ?? $customer->name,
            'msisdn' => $params['msisdn'] ?? $customer->phone1,
            'sender_name' => $params['sender_name'] ?? config('services.dcb.sender_name', config('app.name')),
            'purpose' => Str::limit($params['purpose'] ?? ('Disb' . ($loan->loanNo ?? $loan->id)), 32, ''),
            'normalize_destination' => $params['normalize_destination'] ?? true,
            'client_reference' => $params['client_reference'] ?? self::generateLoanClientReference($loan, 'D'),
        ], DcbTransaction::REF_LOAN_DISBURSEMENT, $loan->id, $meta);
    }

    /**
     * Request loan repayment via DCB Collect API (USSD / push — customer authorizes on mobile).
     *
     * @param  array<string, mixed>  $params
     */
    public function collectRepayment(Loan $loan, float $amount, array $params): array
    {
        if (!$this->gateway->isConfigured()) {
            return ['success' => false, 'message' => 'DCB gateway is not configured.'];
        }

        $customer = $loan->customer;
        if (!$customer) {
            return ['success' => false, 'message' => 'Loan has no customer.'];
        }

        $bankAccountId = $params['bank_account_id'] ?? config('services.dcb.settlement_bank_account_id');
        if (!$bankAccountId) {
            return ['success' => false, 'message' => 'Settlement bank account is required for DCB repayment GL posting.'];
        }

        $bankAccount = BankAccount::find($bankAccountId);
        if (!$bankAccount) {
            return ['success' => false, 'message' => 'Invalid settlement bank account.'];
        }

        $msisdn = $params['msisdn'] ?? $customer->phone1;
        if (empty($msisdn)) {
            return ['success' => false, 'message' => 'Customer phone number (MSISDN) is required for DCB collection.'];
        }

        $collectAmount = (int) round($amount);
        if ($collectAmount < 1) {
            return ['success' => false, 'message' => 'Amount must be at least 1 TZS.'];
        }

        $operation = ($params['settlement_type'] ?? 'repayment') === 'settlement' ? 'S' : 'R';
        $clientReference = $params['client_reference']
            ?? self::generateLoanClientReference($loan, $operation);

        $meta = [
            'loan_id' => $loan->id,
            'amount' => $amount,
            'payment_date' => $params['payment_date'] ?? now()->toDateString(),
            'schedule_id' => $params['schedule_id'] ?? null,
            'bank_account_id' => $bankAccount->id,
            'bank_chart_account_id' => $bankAccount->chart_account_id,
            'calculation_method' => $params['calculation_method'] ?? ($loan->product->interest_method ?? 'flat_rate'),
            'settlement_type' => $params['settlement_type'] ?? 'repayment',
        ];

        $transaction = DcbTransaction::create([
            'company_id' => current_company_id(),
            'user_id' => Auth::id(),
            'type' => 'collect',
            'reference_type' => self::REF_LOAN_REPAYMENT,
            'reference_id' => $loan->id,
            'client_reference' => $clientReference,
            'destination_account' => $msisdn,
            'institution_code' => 'COLLECT',
            'amount' => $collectAmount,
            'beneficiary_name' => config('services.dcb.sender_name', config('app.name')),
            'msisdn' => $msisdn,
            'sender_name' => Str::limit($customer->name, 120, ''),
            'purpose' => 'Collect',
            'status' => DcbTransaction::STATUS_PENDING,
            'meta' => $meta,
        ]);

        $payload = [
            'msisdn' => $msisdn,
            'amount' => $collectAmount,
            'client_reference' => $clientReference,
        ];

        $controlNo = $params['control_no'] ?? null;
        if (!empty($controlNo)) {
            $payload['control_no'] = $controlNo;
        }

        // Optional: credit specific DCB account; otherwise gateway uses business_account / source account.
        $bankAccountNo = $params['bank_account_no'] ?? $bankAccount->account_number ?? null;
        if (!empty($bankAccountNo)) {
            $payload['bank_account_no'] = $bankAccountNo;
        }

        if (!empty($params['request_id'])) {
            $payload['request_id'] = $params['request_id'];
        }

        $response = $this->gateway->collect($payload);

        if ($response['success'] ?? false) {
            $transaction->markSubmitted($response);

            // Collect requires customer USSD/push approval — never post repayment on the sync API response.
            return [
                'success' => true,
                'pending' => true,
                'message' => $response['data']['message'] ?? $response['message'] ?? 'DCB collection request sent. Approve payment on your phone; repayment will post when confirmed.',
                'transaction' => $transaction->fresh(),
                'gateway' => $response,
            ];
        }

        $transaction->markFailed(
            $response['message'] ?? ($response['data']['message'] ?? 'DCB collect request failed.'),
            $response
        );

        return [
            'success' => false,
            'message' => $transaction->message,
            'transaction' => $transaction->fresh(),
            'gateway' => $response,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): array
    {
        $clientReference = $payload['client_reference']
            ?? $payload['clientReference']
            ?? null;

        if (!$clientReference) {
            Log::warning('DCB callback missing client_reference', ['payload' => $payload]);

            return ['success' => false, 'message' => 'Missing client_reference in callback.'];
        }

        $transaction = DcbTransaction::where('client_reference', $clientReference)->first();

        if (!$transaction) {
            Log::warning('DCB callback for unknown client_reference', ['client_reference' => $clientReference]);

            return ['success' => false, 'message' => 'Transaction not found.'];
        }

        if ($transaction->status === DcbTransaction::STATUS_SUCCESS) {
            return ['success' => true, 'transaction_id' => $transaction->id, 'status' => $transaction->status];
        }

        $isSuccess = $this->callbackIndicatesSuccess($payload);

        if ($isSuccess) {
            $transaction->markSuccess($payload);
            $this->finalizeSuccessfulTransaction($transaction->fresh());
        } else {
            $transaction->markFailed(
                $payload['message'] ?? 'Transfer failed per DCB callback.',
                $payload
            );
        }

        return [
            'success' => true,
            'transaction_id' => $transaction->id,
            'status' => $transaction->status,
        ];
    }

    private function finalizeSuccessfulTransaction(DcbTransaction $transaction): void
    {
        if ($transaction->status !== DcbTransaction::STATUS_SUCCESS
            && $transaction->status !== DcbTransaction::STATUS_SUBMITTED) {
            return;
        }

        // Mobile collect: loan repayment/settlement only after async callback confirms payment.
        if ($transaction->type === 'collect'
            && $transaction->reference_type === self::REF_LOAN_REPAYMENT
            && empty($transaction->callback_payload)) {
            return;
        }

        if ($transaction->status === DcbTransaction::STATUS_SUBMITTED) {
            $transaction->update([
                'status' => DcbTransaction::STATUS_SUCCESS,
                'completed_at' => now(),
            ]);
        }

        match ($transaction->reference_type) {
            DcbTransaction::REF_LOAN_DISBURSEMENT => $this->completeLoanDisbursement($transaction),
            self::REF_LOAN_REPAYMENT => $this->completeLoanRepayment($transaction),
            default => null,
        };
    }

    private function completeLoanDisbursement(DcbTransaction $transaction): void
    {
        $loan = Loan::find($transaction->reference_id);
        if (!$loan) {
            return;
        }

        if ($this->disbursementCompletion->isAlreadyDisbursed($loan)) {
            return;
        }

        $meta = $transaction->meta ?? [];

        try {
            $this->disbursementCompletion->complete(
                $loan,
                $meta['disbursement_date'] ?? now(),
                $meta['user_id'] ?? $transaction->user_id,
                $meta['approval_comments'] ?? 'Disbursed via DCB (ref: ' . $transaction->client_reference . ')',
                true
            );
        } catch (\Throwable $e) {
            Log::error('DCB loan disbursement completion failed', [
                'loan_id' => $loan->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function completeLoanRepayment(DcbTransaction $transaction): void
    {
        $meta = $transaction->meta ?? [];
        $loanId = $meta['loan_id'] ?? $transaction->reference_id;
        $loan = Loan::with('product')->find($loanId);

        if (!$loan) {
            return;
        }

        if (!empty($meta['repayment_recorded'])) {
            return;
        }

        $paymentData = [
            'payment_date' => $meta['payment_date'] ?? now()->toDateString(),
            'payment_source' => 'bank',
            'bank_chart_account_id' => $meta['bank_chart_account_id'],
            'bank_account_id' => $meta['bank_account_id'],
            'notes' => 'DCB mobile collection (ref: ' . $transaction->client_reference . ')',
        ];

        try {
            if (($meta['settlement_type'] ?? '') === 'settlement') {
                $this->loanRepaymentService->processSettleRepayment(
                    $loan->id,
                    (float) ($meta['amount'] ?? $transaction->amount),
                    $paymentData
                );
            } else {
                $this->loanRepaymentService->processRepayment(
                    $loan->id,
                    (float) ($meta['amount'] ?? $transaction->amount),
                    $paymentData,
                    $meta['calculation_method'] ?? 'flat_rate',
                    $meta['schedule_id'] ?? null
                );
            }

            $transaction->update([
                'meta' => array_merge($meta, ['repayment_recorded' => true]),
            ]);
        } catch (\Throwable $e) {
            Log::error('DCB loan repayment completion failed', [
                'loan_id' => $loan->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function isGatewayFinalSuccess(array $response): bool
    {
        if (!($response['success'] ?? false)) {
            return false;
        }

        $data = $response['data'] ?? [];
        $code = strtoupper((string) ($data['responseCode'] ?? $data['response_code'] ?? ''));
        $status = strtoupper((string) ($data['status'] ?? ''));

        if (in_array($code, ['PENDING', 'PROCESSING', 'OTP_REQUIRED', 'INITIATED'], true)) {
            return false;
        }
        if (in_array($status, ['PENDING', 'PROCESSING', 'INITIATED', 'OTP_REQUIRED'], true)) {
            return false;
        }

        if (in_array($code, ['0', '00', '000', '200', 'SUCCESS', 'COMPLETED'], true)) {
            return true;
        }
        if (in_array($status, ['SUCCESS', 'COMPLETED', 'SUCCESSFUL'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackIndicatesSuccess(array $payload): bool
    {
        if (isset($payload['success'])) {
            return filter_var($payload['success'], FILTER_VALIDATE_BOOLEAN);
        }

        $code = (string) ($payload['responseCode'] ?? $payload['response_code'] ?? '');
        if ($code !== '') {
            return in_array($code, ['0', '00', '000', '200', 'SUCCESS'], true);
        }

        $status = strtoupper((string) ($payload['status'] ?? ''));

        return in_array($status, ['SUCCESS', 'COMPLETED', 'SUCCESSFUL'], true);
    }
}
