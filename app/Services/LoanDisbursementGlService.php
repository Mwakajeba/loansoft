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

        if ($branchId === null || (int) $branchId <= 0) {
            $branchId = $loan->branch_id ?: $loan->bankAccount?->branch_id;
        }

        if ($branchId === null || (int) $branchId <= 0) {
            throw new \Exception('Branch is required for loan disbursement accounting.');
        }

        $branchId = (int) $branchId;

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

        if ($disbursementAmount < 0) {
            throw new \Exception(
                'Release-date fees (' . number_format($releaseFeeTotal, 2)
                . ' TZS) exceed loan principal (' . number_format($principalAmount, 2)
                . ' TZS). Reduce fees or increase the loan amount.'
            );
        }

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

        if ($releaseFeeTotal > 0.009) {
            $this->postReleaseFeeIncomeGl($loan, $disburseDate->toDateString(), $userId, $branchId);
        }
    }

    /**
     * Active product fees charged on release date, with calculated amounts.
     *
     * @return \Illuminate\Support\Collection<int, object{fee: Fee, amount: float}>
     */
    public function releaseFeeBreakdown(Loan $loan)
    {
        $loan->loadMissing('product');
        $product = $loan->product;
        if (! $product || ! $product->fees_ids) {
            return collect();
        }

        $feeIds = is_array($product->fees_ids)
            ? $product->fees_ids
            : json_decode($product->fees_ids, true);

        if (! is_array($feeIds) || empty($feeIds)) {
            return collect();
        }

        return Fee::query()
            ->whereIn('id', $feeIds)
            ->where('deduction_criteria', 'charge_fee_on_release_date')
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->orderBy('id')
            ->get()
            ->map(function (Fee $fee) use ($loan) {
                $amount = $fee->monetaryAmountForPrincipal((float) $loan->amount, $loan->custom_fee_amounts);

                return (object) [
                    'fee' => $fee,
                    'amount' => round((float) $amount, 2),
                ];
            })
            ->filter(fn ($row) => $row->amount > 0.009)
            ->values();
    }

    /**
     * Credit fee income GL for release-date fees (for P&L).
     * Cash is assumed already netted (principal − fees).
     *
     * @return float Total fee income posted
     */
    public function postReleaseFeeIncomeGl(Loan $loan, $date, int $userId, ?int $branchId = null): float
    {
        $breakdown = $this->releaseFeeBreakdown($loan);
        if ($breakdown->isEmpty()) {
            return 0.0;
        }

        $date = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $branchId = $branchId ?: $loan->branch_id;
        $posted = 0.0;

        foreach ($breakdown as $row) {
            /** @var Fee $fee */
            $fee = $row->fee;
            $amount = (float) $row->amount;
            if (! $fee->chart_account_id || $amount <= 0) {
                continue;
            }

            $already = (float) GlTransaction::query()
                ->where('transaction_id', $loan->id)
                ->where('transaction_type', self::TRANSACTION_TYPE)
                ->where('chart_account_id', $fee->chart_account_id)
                ->where('nature', 'credit')
                ->sum('amount');

            $needed = round($amount - $already, 2);
            if ($needed <= 0.009) {
                continue;
            }

            $desc = ($fee->name ?: 'Fee')." Fee for loan #{$loan->id}";

            GlTransaction::create([
                'chart_account_id' => $fee->chart_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $needed,
                'nature' => 'credit',
                'transaction_id' => $loan->id,
                'transaction_type' => self::TRANSACTION_TYPE,
                'date' => $date,
                'description' => $desc,
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]);

            $posted += $needed;
        }

        return round($posted, 2);
    }

    /**
     * Assess whether a loan needs release-fee GL deduction (no writes).
     *
     * @return array{status: string, message: string, principal: float, fees: float, cash: float, fee_income_needed: float, cash_needs_reduce: bool, target_cash: float, breakdown: \Illuminate\Support\Collection}
     */
    public function assessMissingReleaseFeeDeductions(Loan $loan): array
    {
        $loan->loadMissing(['product.principalReceivableAccount', 'bankAccount', 'customer']);

        $breakdown = $this->releaseFeeBreakdown($loan);
        $feeTotal = round((float) $breakdown->sum('amount'), 2);
        $principal = round((float) $loan->amount, 2);

        $base = [
            'status' => 'skipped',
            'message' => 'No release-date fees to deduct',
            'principal' => $principal,
            'fees' => $feeTotal,
            'cash' => 0.0,
            'fee_income_needed' => 0.0,
            'cash_needs_reduce' => false,
            'target_cash' => $principal,
            'breakdown' => $breakdown,
        ];

        if ($feeTotal <= 0.009) {
            return $base;
        }

        $missingChart = $breakdown->first(fn ($row) => ! $row->fee->chart_account_id);
        if ($missingChart) {
            return array_merge($base, [
                'status' => 'error',
                'message' => 'Fee "'.$missingChart->fee->name.'" has no chart account configured',
            ]);
        }

        $feeChartIds = $breakdown->pluck('fee.chart_account_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $principalReceivable = optional($loan->product?->principalReceivableAccount)->id;

        $existingFeeIncome = (float) GlTransaction::query()
            ->where('transaction_id', $loan->id)
            ->where('transaction_type', self::TRANSACTION_TYPE)
            ->where('nature', 'credit')
            ->whereIn('chart_account_id', $feeChartIds)
            ->sum('amount');

        $cashTotal = round((float) GlTransaction::query()
            ->where('transaction_id', $loan->id)
            ->where('transaction_type', self::TRANSACTION_TYPE)
            ->where('nature', 'credit')
            ->when(! empty($feeChartIds), fn ($q) => $q->whereNotIn('chart_account_id', $feeChartIds))
            ->when($principalReceivable, fn ($q) => $q->where('chart_account_id', '!=', $principalReceivable))
            ->sum('amount'), 2);

        $targetCash = round($principal - $feeTotal, 2);
        if ($targetCash < 0) {
            return array_merge($base, [
                'status' => 'error',
                'message' => 'Release fees exceed loan principal',
                'cash' => $cashTotal,
            ]);
        }

        $feeIncomeNeeded = round($feeTotal - $existingFeeIncome, 2);
        $cashNeedsReduce = $cashTotal > ($targetCash + 0.009);

        if ($feeIncomeNeeded <= 0.009 && ! $cashNeedsReduce) {
            return array_merge($base, [
                'status' => 'already_done',
                'message' => 'Release fees already deducted and posted to fee income',
                'cash' => $cashTotal,
                'target_cash' => $targetCash,
            ]);
        }

        if (! ($loan->branch_id ?: $loan->bankAccount?->branch_id)) {
            return array_merge($base, [
                'status' => 'error',
                'message' => 'Loan has no branch for GL posting',
                'cash' => $cashTotal,
                'target_cash' => $targetCash,
            ]);
        }

        return [
            'status' => 'needs_fix',
            'message' => sprintf(
                'Will set cash to %.2f and credit fee income %.2f (principal %.2f)',
                $targetCash,
                max(0, $feeIncomeNeeded),
                $principal
            ),
            'principal' => $principal,
            'fees' => $feeTotal,
            'cash' => $cashTotal,
            'fee_income_needed' => $feeIncomeNeeded,
            'cash_needs_reduce' => $cashNeedsReduce,
            'target_cash' => $targetCash,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Alias used by artisan dry-run listing.
     */
    public function applyMissingReleaseFeeDeductionsPreview(Loan $loan): array
    {
        return $this->assessMissingReleaseFeeDeductions($loan);
    }

    /**
     * For existing loans: deduct missing release fees from cash GL and credit fee income.
     *
     * Target double entry:
     *   Dr Principal receivable  P
     *   Cr Cash/Bank             P − F
     *   Cr Fee income            F
     *
     * @return array{status: string, message: string, principal: float, fees: float, cash: float, posted_fee_income: float, cash_adjusted: float}
     */
    public function applyMissingReleaseFeeDeductions(Loan $loan, int $userId): array
    {
        $assessment = $this->assessMissingReleaseFeeDeductions($loan);

        if ($assessment['status'] !== 'needs_fix') {
            return [
                'status' => $assessment['status'],
                'message' => $assessment['message'],
                'principal' => $assessment['principal'],
                'fees' => $assessment['fees'],
                'cash' => $assessment['cash'],
                'posted_fee_income' => 0.0,
                'cash_adjusted' => 0.0,
            ];
        }

        $loan->loadMissing(['product.principalReceivableAccount', 'bankAccount', 'customer']);
        $breakdown = $assessment['breakdown'];
        $feeTotal = $assessment['fees'];
        $principal = $assessment['principal'];
        $targetCash = $assessment['target_cash'];
        $cashNeedsReduce = $assessment['cash_needs_reduce'];
        $feeIncomeNeeded = $assessment['fee_income_needed'];
        $cashTotal = $assessment['cash'];

        $feeChartIds = $breakdown->pluck('fee.chart_account_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $principalReceivable = optional($loan->product?->principalReceivableAccount)->id;

        $cashCredits = GlTransaction::query()
            ->where('transaction_id', $loan->id)
            ->where('transaction_type', self::TRANSACTION_TYPE)
            ->where('nature', 'credit')
            ->when(! empty($feeChartIds), fn ($q) => $q->whereNotIn('chart_account_id', $feeChartIds))
            ->when($principalReceivable, fn ($q) => $q->where('chart_account_id', '!=', $principalReceivable))
            ->orderBy('id')
            ->get();

        $branchId = (int) ($loan->branch_id ?: $loan->bankAccount?->branch_id);
        $date = $loan->disbursed_on ?? $loan->date_applied ?? now()->toDateString();
        $date = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return DB::transaction(function () use (
            $loan,
            $userId,
            $branchId,
            $date,
            $principal,
            $feeTotal,
            $targetCash,
            $cashCredits,
            $cashTotal,
            $cashNeedsReduce,
            $feeIncomeNeeded,
            $breakdown,
            $feeChartIds,
            $principalReceivable
        ) {
            $cashAdjusted = 0.0;

            if ($cashNeedsReduce && $cashCredits->isNotEmpty()) {
                $remaining = round($cashTotal - $targetCash, 2);
                foreach ($cashCredits->sortByDesc('id') as $row) {
                    if ($remaining <= 0.009) {
                        break;
                    }
                    $rowAmount = round((float) $row->amount, 2);
                    $take = min($rowAmount, $remaining);
                    $newAmount = round($rowAmount - $take, 2);
                    if ($newAmount <= 0.009) {
                        $row->delete();
                    } else {
                        $row->update(['amount' => $newAmount]);
                    }
                    $remaining = round($remaining - $take, 2);
                    $cashAdjusted = round($cashAdjusted + $take, 2);
                }
            } elseif ($cashCredits->isEmpty()) {
                $bankChartId = $loan->bankAccount?->chart_account_id;
                $receivableId = $principalReceivable;

                if ($bankChartId && $receivableId) {
                    $notes = $this->disbursementDescription($loan);
                    GlTransaction::insert([
                        [
                            'chart_account_id' => $bankChartId,
                            'customer_id' => $loan->customer_id,
                            'amount' => $targetCash,
                            'nature' => 'credit',
                            'transaction_id' => $loan->id,
                            'transaction_type' => self::TRANSACTION_TYPE,
                            'date' => $date,
                            'description' => $notes,
                            'branch_id' => $branchId,
                            'user_id' => $userId,
                        ],
                        [
                            'chart_account_id' => $receivableId,
                            'customer_id' => $loan->customer_id,
                            'amount' => $principal,
                            'nature' => 'debit',
                            'transaction_id' => $loan->id,
                            'transaction_type' => self::TRANSACTION_TYPE,
                            'date' => $date,
                            'description' => $notes,
                            'branch_id' => $branchId,
                            'user_id' => $userId,
                        ],
                    ]);
                    $cashAdjusted = $feeTotal;
                } elseif ($cashNeedsReduce) {
                    return [
                        'status' => 'error',
                        'message' => 'Cannot adjust cash: no bank/cash disbursement GL and loan has no bank account',
                        'principal' => $principal,
                        'fees' => $feeTotal,
                        'cash' => $cashTotal,
                        'posted_fee_income' => 0.0,
                        'cash_adjusted' => 0.0,
                    ];
                }
            }

            $postedFeeIncome = 0.0;
            if ($feeIncomeNeeded > 0.009) {
                $journal = \App\Models\Journal::create([
                    'date' => $date,
                    'reference' => $loan->id,
                    'reference_type' => self::TRANSACTION_TYPE,
                    'customer_id' => $loan->customer_id,
                    'description' => 'Release fee deduction for loan #'.$loan->id,
                    'branch_id' => $branchId,
                    'user_id' => $userId,
                ]);

                foreach ($breakdown as $row) {
                    /** @var Fee $fee */
                    $fee = $row->fee;
                    $amount = (float) $row->amount;
                    $already = (float) GlTransaction::query()
                        ->where('transaction_id', $loan->id)
                        ->where('transaction_type', self::TRANSACTION_TYPE)
                        ->where('chart_account_id', $fee->chart_account_id)
                        ->where('nature', 'credit')
                        ->sum('amount');
                    $needed = round($amount - $already, 2);
                    if ($needed <= 0.009 || ! $fee->chart_account_id) {
                        continue;
                    }

                    $desc = ($fee->name ?: 'Fee')." Fee for loan #{$loan->id}";

                    \App\Models\JournalItem::create([
                        'journal_id' => $journal->id,
                        'chart_account_id' => $fee->chart_account_id,
                        'amount' => $needed,
                        'nature' => 'credit',
                        'description' => $desc,
                    ]);

                    GlTransaction::create([
                        'chart_account_id' => $fee->chart_account_id,
                        'customer_id' => $loan->customer_id,
                        'amount' => $needed,
                        'nature' => 'credit',
                        'transaction_id' => $loan->id,
                        'transaction_type' => self::TRANSACTION_TYPE,
                        'date' => $date,
                        'description' => $desc,
                        'branch_id' => $branchId,
                        'user_id' => $userId,
                    ]);

                    $postedFeeIncome = round($postedFeeIncome + $needed, 2);
                }
            }

            $newCash = round((float) GlTransaction::query()
                ->where('transaction_id', $loan->id)
                ->where('transaction_type', self::TRANSACTION_TYPE)
                ->where('nature', 'credit')
                ->whereNotIn('chart_account_id', $feeChartIds)
                ->when($principalReceivable, fn ($q) => $q->where('chart_account_id', '!=', $principalReceivable))
                ->sum('amount'), 2);

            return [
                'status' => 'fixed',
                'message' => sprintf(
                    'Deducted fees %.2f: cash now %.2f, fee income +%.2f (principal %.2f)',
                    $feeTotal,
                    $newCash,
                    $postedFeeIncome,
                    $principal
                ),
                'principal' => $principal,
                'fees' => $feeTotal,
                'cash' => $newCash,
                'posted_fee_income' => $postedFeeIncome,
                'cash_adjusted' => $cashAdjusted,
            ];
        });
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
