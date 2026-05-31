<?php

namespace App\Traits;

use App\Models\ChartAccount;
use App\Models\Customer;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Jenssegers\Agent\Agent;

trait LogsActivity
{
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            $model->storeActivityLog('create');
        });

        static::updated(function ($model) {
            $model->storeActivityLog('update');
        });

        static::deleted(function ($model) {
            $model->storeActivityLog('delete', $model->getAttributes());
        });
    }

    protected function storeActivityLog($action, $deletedData = null)
    {
        $agent = new Agent();
        $deviceInfo = 'Unknown';
        if ($agent->isDesktop()) {
            $deviceInfo = 'Desktop';
        } elseif ($agent->isPhone()) {
            if ($agent->is('iPhone')) {
                $deviceInfo = 'iPhone';
            } elseif ($agent->is('AndroidOS')) {
                $deviceInfo = 'Android Phone';
            } else {
                $deviceInfo = 'Phone';
            }
        } elseif ($agent->isTablet()) {
            if ($agent->is('iPad')) {
                $deviceInfo = 'iPad';
            } else {
                $deviceInfo = 'Tablet';
            }
        }

        $deviceString = $deviceInfo . ' - ' . $agent->browser();

        $description = $this->buildDescription($action, $deletedData);

        $logData = [
            'user_id'     => Auth::id(),
            'model'       => class_basename($this),
            'action'      => $action,
            'description' => $description,
            'ip_address'  => resolve_client_ip(),
            'device'      => $deviceString,
            'activity_time' => now(),
        ];

        if ($action === 'update') {
            $changes = $this->getChanges();
            $original = $this->getOriginal();

            $oldValues = [];
            $newValues = [];

            foreach ($changes as $key => $newValue) {
                if (in_array($key, ['updated_at', 'created_at', 'deleted_at'])) {
                    continue;
                }

                $oldValues[$key] = $original[$key] ?? null;
                $newValues[$key] = $newValue;
            }

            if (!empty($oldValues)) {
                $logData['old_values'] = $oldValues;
                $logData['new_values'] = $newValues;
            }
        }

        if ($action === 'delete' && $deletedData) {
            $filteredData = [];
            foreach ($deletedData as $key => $value) {
                $skipFields = ['updated_at', 'created_at', 'deleted_at', 'password', 'remember_token'];
                if (in_array($key, $skipFields)) {
                    continue;
                }
                $filteredData[$key] = $value;
            }

            if (!empty($filteredData)) {
                $logData['old_values'] = $filteredData;
            }
        }

        ActivityLog::create($logData);
    }

    /**
     * Models may override this for custom activity text.
     */
    public function getActivityLogDescription(string $action, ?array $deletedData = null): ?string
    {
        return null;
    }

    protected function buildDescription($action, $deletedData = null): string
    {
        $custom = $this->getActivityLogDescription($action, $deletedData);
        if ($custom) {
            return $custom;
        }

        $specific = $this->resolveModelSpecificDescription($action, $deletedData);
        if ($specific) {
            return $specific;
        }

        $modelName = class_basename($this);
        $identifier = $this->getIdentifier($deletedData);
        $actionVerb = $this->activityVerb($action);

        if ($identifier) {
            return "{$actionVerb} {$modelName}: {$identifier}";
        }

        $id = $deletedData['id'] ?? $this->id ?? null;

        return $id
            ? "{$actionVerb} {$modelName} (ID: {$id})"
            : "{$actionVerb} {$modelName}";
    }

    protected function resolveModelSpecificDescription(string $action, ?array $deletedData = null): ?string
    {
        $verb = $this->activityVerb($action);

        return match (class_basename($this)) {
            'GlTransaction' => $this->describeGlTransaction($verb, $deletedData),
            'Repayment' => $this->describeRepayment($verb, $deletedData),
            'Receipt' => $this->describeReceipt($verb, $deletedData),
            'ReceiptItem' => $this->describeReceiptItem($verb, $deletedData),
            'Payment' => $this->describePayment($verb, $deletedData),
            'PaymentItem' => $this->describePaymentItem($verb, $deletedData),
            'LoanSchedule' => $this->describeLoanSchedule($verb, $deletedData),
            'Loan' => $this->describeLoan($verb, $deletedData),
            'Customer' => $this->describeCustomer($verb, $deletedData),
            'Journal' => $this->describeJournal($verb, $deletedData),
            'JournalItem' => $this->describeJournalItem($verb, $deletedData),
            'LoanApproval' => $this->describeLoanApproval($verb, $deletedData),
            default => null,
        };
    }

    protected function activityData(?array $deletedData = null): array
    {
        return $deletedData ?? $this->getAttributes();
    }

    protected function activityVerb(string $action): string
    {
        return match ($action) {
            'create' => 'Created',
            'update' => 'Updated',
            'delete' => 'Deleted',
            default => ucfirst($action),
        };
    }

    protected function fmtMoney($amount): string
    {
        if ($amount === null || $amount === '') {
            return 'TZS 0.00';
        }

        return 'TZS ' . number_format((float) $amount, 2);
    }

    protected function customerLabel(?int $customerId, ?array $data = null): ?string
    {
        if (!empty($data['payee_name'])) {
            return $data['payee_name'];
        }

        if (!$customerId) {
            return null;
        }

        if ($this->relationLoaded('customer') && $this->customer) {
            return $this->customer->name;
        }

        return Customer::whereKey($customerId)->value('name');
    }

    protected function loanLabel(?int $loanId): ?string
    {
        if (!$loanId) {
            return null;
        }

        if ($this->relationLoaded('loan') && $this->loan) {
            return $this->loan->loanNo ?? ('Loan #' . $loanId);
        }

        $loan = Loan::whereKey($loanId)->first(['id', 'loanNo', 'customer_id']);
        if (!$loan) {
            return 'Loan #' . $loanId;
        }

        $customer = $loan->customer_id
            ? Customer::whereKey($loan->customer_id)->value('name')
            : null;

        $loanNo = $loan->loanNo ?? ('Loan #' . $loanId);

        return $customer ? "{$loanNo} ({$customer})" : $loanNo;
    }

    protected function chartAccountLabel(?int $chartAccountId): ?string
    {
        if (!$chartAccountId) {
            return null;
        }

        if ($this->relationLoaded('chartAccount') && $this->chartAccount) {
            return $this->chartAccount->account_name ?? null;
        }

        return ChartAccount::whereKey($chartAccountId)->value('account_name');
    }

    protected function describeGlTransaction(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $nature = ucfirst($data['nature'] ?? 'entry');
        $type = $data['transaction_type'] ?? 'General';
        $refId = $data['transaction_id'] ?? null;
        $customer = $this->customerLabel($data['customer_id'] ?? null, $data);
        $notes = !empty($data['description']) ? ' — ' . \Illuminate\Support\Str::limit($data['description'], 80) : '';

        $context = match ($type) {
            'Loan Disbursement' => $refId ? ' for ' . ($this->loanLabel((int) $refId) ?? "loan #{$refId}") : ' for loan disbursement',
            'Loan Payment', 'Loan Repayment' => $refId ? ' for ' . ($this->loanLabel((int) $refId) ?? "loan #{$refId}") : ' for loan repayment',
            'Penalty', 'Mature Interest' => $refId ? " schedule #{$refId}" : '',
            default => $refId ? " ref #{$refId}" : '',
        };

        $party = $customer ? " — {$customer}" : '';

        return "{$verb} GL {$nature} {$amount} ({$type}){$context}{$party}{$notes}";
    }

    protected function describeRepayment(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $total = (float) ($data['principal'] ?? 0)
            + (float) ($data['interest'] ?? 0)
            + (float) ($data['penalt_amount'] ?? 0)
            + (float) ($data['fee_amount'] ?? 0);
        $loan = $this->loanLabel($data['loan_id'] ?? null);
        $customer = $this->customerLabel($data['customer_id'] ?? null, $data);
        $date = !empty($data['payment_date'])
            ? ' on ' . \Carbon\Carbon::parse($data['payment_date'])->format('M d, Y')
            : '';

        $parts = array_filter([
            $loan ? "for {$loan}" : null,
            $customer ? "by {$customer}" : null,
        ]);

        $breakdown = [];
        if ((float) ($data['principal'] ?? 0) > 0) {
            $breakdown[] = 'Principal ' . $this->fmtMoney($data['principal']);
        }
        if ((float) ($data['interest'] ?? 0) > 0) {
            $breakdown[] = 'Interest ' . $this->fmtMoney($data['interest']);
        }
        if ((float) ($data['penalt_amount'] ?? 0) > 0) {
            $breakdown[] = 'Penalty ' . $this->fmtMoney($data['penalt_amount']);
        }
        if ((float) ($data['fee_amount'] ?? 0) > 0) {
            $breakdown[] = 'Fee ' . $this->fmtMoney($data['fee_amount']);
        }

        $detail = $parts ? ' ' . implode(' ', $parts) : '';
        $split = $breakdown ? ' [' . implode(', ', $breakdown) . ']' : '';

        return "{$verb} loan repayment {$this->fmtMoney($total)}{$date}{$detail}{$split}";
    }

    protected function describeReceipt(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $ref = $data['reference'] ?? ('#' . ($data['id'] ?? $this->id));
        $type = $data['reference_type'] ?? 'Receipt';
        $payee = $this->customerLabel($data['customer_id'] ?? null, $data)
            ?? ($data['payee_name'] ?? null);
        $date = !empty($data['date'])
            ? ' — ' . \Carbon\Carbon::parse($data['date'])->format('M d, Y')
            : '';

        $party = $payee ? " from {$payee}" : '';
        $notes = !empty($data['description']) ? ' — ' . \Illuminate\Support\Str::limit($data['description'], 60) : '';

        return "{$verb} receipt {$ref} ({$type}) {$amount}{$party}{$date}{$notes}";
    }

    protected function describeReceiptItem(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $receiptId = $data['receipt_id'] ?? null;
        $feeName = null;

        if (!empty($data['fee_id'])) {
            $feeName = Fee::whereKey($data['fee_id'])->value('name');
        }

        $account = $this->chartAccountLabel($data['chart_account_id'] ?? null);
        $feePart = $feeName ? " — {$feeName}" : '';
        $accountPart = $account ? " to {$account}" : '';
        $receiptPart = $receiptId ? " on receipt #{$receiptId}" : '';

        return "{$verb} receipt line {$amount}{$receiptPart}{$feePart}{$accountPart}";
    }

    protected function describePayment(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $ref = $data['reference'] ?? ('#' . ($data['id'] ?? $this->id));
        $type = $data['reference_type'] ?? 'Payment';
        $payee = $this->customerLabel($data['customer_id'] ?? null, $data)
            ?? ($data['payee_name'] ?? null);
        $date = !empty($data['date'])
            ? ' — ' . \Carbon\Carbon::parse($data['date'])->format('M d, Y')
            : '';

        $party = $payee ? " to {$payee}" : '';
        $loanRef = ($type === 'Loan Payment' && !empty($data['reference']))
            ? ' for ' . ($this->loanLabel((int) $data['reference']) ?? "loan #{$data['reference']}")
            : '';
        $notes = !empty($data['description']) ? ' — ' . \Illuminate\Support\Str::limit($data['description'], 60) : '';

        return "{$verb} payment {$ref} ({$type}) {$amount}{$party}{$loanRef}{$date}{$notes}";
    }

    protected function describePaymentItem(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $paymentId = $data['payment_id'] ?? null;
        $account = $this->chartAccountLabel($data['chart_account_id'] ?? null);
        $accountPart = $account ? " — {$account}" : '';
        $paymentPart = $paymentId ? " on payment #{$paymentId}" : '';
        $notes = !empty($data['description']) ? ' — ' . \Illuminate\Support\Str::limit($data['description'], 50) : '';

        return "{$verb} payment line {$amount}{$paymentPart}{$accountPart}{$notes}";
    }

    protected function describeLoanSchedule(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $loan = $this->loanLabel($data['loan_id'] ?? null);
        $due = !empty($data['due_date'])
            ? \Carbon\Carbon::parse($data['due_date'])->format('M d, Y')
            : null;
        $principal = $this->fmtMoney($data['principal'] ?? 0);
        $interest = $this->fmtMoney($data['interest'] ?? 0);
        $total = (float) ($data['principal'] ?? 0)
            + (float) ($data['interest'] ?? 0)
            + (float) ($data['fee_amount'] ?? 0)
            + (float) ($data['penalty_amount'] ?? 0);

        $loanPart = $loan ? " for {$loan}" : '';
        $duePart = $due ? " due {$due}" : '';

        return "{$verb} loan schedule installment{$loanPart}{$duePart} — total {$this->fmtMoney($total)} (Principal {$principal}, Interest {$interest})";
    }

    protected function describeLoan(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $loanNo = $data['loanNo'] ?? ('#' . ($data['id'] ?? $this->id));
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $customer = $this->customerLabel($data['customer_id'] ?? null, $data);
        $status = !empty($data['status']) ? ' — ' . ucfirst($data['status']) : '';
        $customerPart = $customer ? " for {$customer}" : '';

        return "{$verb} loan {$loanNo} {$amount}{$customerPart}{$status}";
    }

    protected function describeCustomer(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $name = $data['name'] ?? 'Customer';
        $no = !empty($data['customerNo']) ? " ({$data['customerNo']})" : '';
        $phone = !empty($data['phone1']) ? " — {$data['phone1']}" : '';

        return "{$verb} customer {$name}{$no}{$phone}";
    }

    protected function describeJournal(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $ref = $data['reference'] ?? ('#' . ($data['id'] ?? $this->id));
        $type = $data['reference_type'] ?? 'Journal';
        $date = !empty($data['date'])
            ? ' — ' . \Carbon\Carbon::parse($data['date'])->format('M d, Y')
            : '';
        $notes = !empty($data['description']) ? ' — ' . \Illuminate\Support\Str::limit($data['description'], 60) : '';

        return "{$verb} journal entry {$ref} ({$type}){$date}{$notes}";
    }

    protected function describeJournalItem(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $amount = $this->fmtMoney($data['amount'] ?? 0);
        $nature = ucfirst($data['nature'] ?? 'entry');
        $account = $this->chartAccountLabel($data['chart_account_id'] ?? null);
        $journalId = $data['journal_id'] ?? null;
        $accountPart = $account ? " — {$account}" : '';

        return "{$verb} journal line {$nature} {$amount} on journal #{$journalId}{$accountPart}";
    }

    protected function describeLoanApproval(string $verb, ?array $deletedData): string
    {
        $data = $this->activityData($deletedData);
        $action = ucfirst($data['action'] ?? 'approval');
        $loan = $this->loanLabel($data['loan_id'] ?? null);
        $level = !empty($data['approval_level']) ? " at level {$data['approval_level']}" : '';
        $comments = !empty($data['comments']) ? ' — ' . \Illuminate\Support\Str::limit($data['comments'], 60) : '';

        return "{$verb} loan {$action}{$level}" . ($loan ? " for {$loan}" : '') . $comments;
    }

    protected function getIdentifier($deletedData = null): ?string
    {
        $identifierFields = [
            'loanNo',
            'customerNo',
            'name',
            'title',
            'code',
            'reference',
            'payee_name',
            'email',
            'username',
            'account_number',
            'account_name',
            'description',
        ];

        $data = $deletedData ?? $this->getAttributes();

        foreach ($identifierFields as $field) {
            if (!empty($data[$field]) && !in_array($field, ['description'], true)) {
                return (string) $data[$field];
            }
        }

        if (!empty($data['description'])) {
            return \Illuminate\Support\Str::limit((string) $data['description'], 80);
        }

        if (!empty($data['amount'])) {
            return $this->fmtMoney($data['amount']);
        }

        return null;
    }
}
