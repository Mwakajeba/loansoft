<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\GlTransaction;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LoanDisbursementGlService
{
    public const TRANSACTION_TYPE = 'Loan Disbursement';

    public const PAYMENT_REFERENCE_TYPE = 'Loan Payment';

    /**
     * Format loan amounts for GL / payment descriptions (avoids float noise like 400000.000000000000000).
     */
    public function formatAmountForDescription($amount): string
    {
        $value = round((float) $amount, 2);

        if (abs($value - round($value)) < 0.001) {
            return number_format($value, 0, '.', ',');
        }

        return number_format($value, 2, '.', ',');
    }

    public function disbursementDescription(Loan $loan): string
    {
        $loan->loadMissing(['product', 'customer']);
        $productName = $loan->product->name ?? 'Loan';
        $customerName = $loan->customer->name ?? 'Customer';
        $amount = $this->formatAmountForDescription($loan->amount);

        return "Being disbursement for loan of {$productName}, paid to {$customerName}, TSHS.{$amount}";
    }

    public function hasDisbursementGl(int $loanId): bool
    {
        return GlTransaction::where('transaction_id', $loanId)
            ->where('transaction_type', self::TRANSACTION_TYPE)
            ->where('nature', 'debit')
            ->exists();
    }

    public function hasLoanPayment(int $loanId): bool
    {
        return Payment::where('reference', $loanId)
            ->where('reference_type', self::PAYMENT_REFERENCE_TYPE)
            ->exists();
    }

    /**
     * Sum of release-date fees deducted from cash disbursed to customer.
     */
    public function calculateReleaseFeeTotal(Loan $loan): float
    {
        $product = $loan->product;
        if (!$product || !$product->fees_ids) {
            return 0.0;
        }

        $feeIds = is_array($product->fees_ids)
            ? $product->fees_ids
            : json_decode($product->fees_ids, true);

        if (!is_array($feeIds) || empty($feeIds)) {
            return 0.0;
        }

        $releaseFees = DB::table('fees')
            ->whereIn('id', $feeIds)
            ->where('deduction_criteria', 'charge_fee_on_release_date')
            ->where('status', 'active')
            ->get();

        $total = 0.0;
        foreach ($releaseFees as $feeRow) {
            $feeModel = Fee::find($feeRow->id);
            $total += $feeModel
                ? $feeModel->monetaryAmountForPrincipal((float) $loan->amount, $loan->custom_fee_amounts)
                : 0.0;
        }

        return round($total, 2);
    }

    /**
     * Post loan disbursement payment + GL (idempotent — skips if principal debit already exists).
     */
    public function postDisbursement(
        Loan $loan,
        $disburseDate,
        int $userId,
        ?int $branchId
    ): void {
        $loan->loadMissing(['product.principalReceivableAccount', 'customer', 'bankAccount']);

        if (!$loan->bank_account_id || !$loan->bankAccount) {
            throw new \Exception('Bank account must be selected before disbursement.');
        }

        if ($this->hasDisbursementGl($loan->id)) {
            Log::info('Loan disbursement GL already posted, skipping duplicate', [
                'loan_id' => $loan->id,
                'loan_no' => $loan->loanNo,
            ]);

            return;
        }

        $product = $loan->product;
        $principalReceivable = optional($product->principalReceivableAccount)->id;

        if (!$principalReceivable) {
            throw new \Exception('Principal receivable account not set for this loan product.');
        }

        $disburseDate = $disburseDate instanceof Carbon
            ? $disburseDate
            : Carbon::parse($disburseDate);

        $notes = $this->disbursementDescription($loan);
        $principalAmount = round((float) $loan->amount, 2);
        $releaseFeeTotal = $this->calculateReleaseFeeTotal($loan);
        $disbursementAmount = round($principalAmount - $releaseFeeTotal, 2);

        if (!$this->hasLoanPayment($loan->id)) {
            $payment = Payment::create([
                'reference' => $loan->id,
                'reference_type' => self::PAYMENT_REFERENCE_TYPE,
                'reference_number' => null,
                'date' => $disburseDate,
                'amount' => $principalAmount,
                'description' => $notes,
                'user_id' => $userId,
                'payee_type' => 'customer',
                'customer_id' => $loan->customer_id,
                'bank_account_id' => $loan->bank_account_id,
                'branch_id' => $branchId,
                'approved' => true,
                'approved_by' => $userId,
                'approved_at' => $disburseDate,
            ]);

            PaymentItem::create([
                'payment_id' => $payment->id,
                'chart_account_id' => $principalReceivable,
                'amount' => $principalAmount,
                'description' => $notes,
            ]);
        }

        GlTransaction::insert([
            [
                'chart_account_id' => $loan->bankAccount->chart_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $disbursementAmount,
                'nature' => 'credit',
                'transaction_id' => $loan->id,
                'transaction_type' => self::TRANSACTION_TYPE,
                'date' => $disburseDate,
                'description' => $notes,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ],
            [
                'chart_account_id' => $principalReceivable,
                'customer_id' => $loan->customer_id,
                'amount' => $principalAmount,
                'nature' => 'debit',
                'transaction_id' => $loan->id,
                'transaction_type' => self::TRANSACTION_TYPE,
                'date' => $disburseDate,
                'description' => $notes,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ],
        ]);
    }

    /**
     * Remove duplicate Loan Disbursement GL rows (keeps the oldest debit/credit per loan).
     */
    public function removeDuplicateDisbursementGlEntries(?int $loanId = null): int
    {
        $loanIdsQuery = GlTransaction::query()
            ->where('transaction_type', self::TRANSACTION_TYPE)
            ->where('nature', 'debit')
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) > 1');

        if ($loanId !== null) {
            $loanIdsQuery->where('transaction_id', $loanId);
        }

        $loanIds = $loanIdsQuery->pluck('transaction_id');
        $removed = 0;

        foreach ($loanIds as $id) {
            $debits = GlTransaction::where('transaction_id', $id)
                ->where('transaction_type', self::TRANSACTION_TYPE)
                ->where('nature', 'debit')
                ->orderBy('id')
                ->get();

            foreach ($debits->slice(1) as $duplicate) {
                $duplicate->delete();
                $removed++;
            }

            $credits = GlTransaction::where('transaction_id', $id)
                ->where('transaction_type', self::TRANSACTION_TYPE)
                ->where('nature', 'credit')
                ->orderBy('id')
                ->get();

            foreach ($credits->slice(1) as $duplicate) {
                $duplicate->delete();
                $removed++;
            }
        }

        return $removed;
    }
}
