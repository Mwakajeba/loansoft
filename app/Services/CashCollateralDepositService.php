<?php

namespace App\Services;

use App\Helpers\SmsHelper;
use App\Models\BankAccount;
use App\Models\CashCollateral;
use App\Models\GlTransaction;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashCollateralDepositService
{
    /**
     * Process a single cash collateral deposit (receipt + GL + balance update).
     */
    public function processDeposit(
        CashCollateral $collateral,
        BankAccount $bankAccount,
        float $amount,
        string $depositDate,
        string $notes,
        User $user,
        bool $sendSms = true
    ): Receipt {
        return DB::transaction(function () use ($collateral, $bankAccount, $amount, $depositDate, $notes, $user, $sendSms) {
            $collateral->loadMissing(['customer', 'type']);

            $receipt = Receipt::create([
                'reference' => $collateral->id,
                'reference_type' => 'Deposit',
                'reference_number' => null,
                'amount' => $amount,
                'date' => $depositDate,
                'description' => $notes,
                'user_id' => $user->id,
                'bank_account_id' => $bankAccount->id,
                'customer_id' => $collateral->customer_id,
                'branch_id' => $user->branch_id,
                'approved' => true,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'chart_account_id' => $collateral->type->chart_account_id,
                'amount' => $amount,
                'description' => $notes,
            ]);

            GlTransaction::create([
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $collateral->customer_id,
                'amount' => $amount,
                'nature' => 'debit',
                'transaction_id' => $receipt->id,
                'transaction_type' => 'receipt',
                'date' => $depositDate,
                'description' => $notes,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
            ]);

            GlTransaction::create([
                'chart_account_id' => $collateral->type->chart_account_id,
                'customer_id' => $collateral->customer_id,
                'amount' => $amount,
                'nature' => 'credit',
                'transaction_id' => $receipt->id,
                'transaction_type' => 'receipt',
                'date' => $depositDate,
                'description' => $notes,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
            ]);

            $collateral->increment('amount', $amount);

            if ($sendSms && $collateral->customer && $collateral->customer->phone1) {
                $templateVars = [
                    'amount' => number_format($amount, 2),
                    'action' => 'deposit',
                    'company_name' => '',
                ];
                $smsMessage = SmsHelper::resolveTemplate('cash_collateral', $templateVars)
                    ?? 'Cash deposit processed successfully. Amount: TSHS'.number_format($amount, 2);
                SmsHelper::send($collateral->customer->phone1, $smsMessage, 'cash_collateral');
            }

            return $receipt;
        });
    }
}
