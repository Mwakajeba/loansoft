<?php

namespace App\Services;

use App\Models\Loan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoanDeletionService
{
    /**
     * Permanently delete all loans for a customer (includes soft-deleted rows when present).
     */
    public function deleteAllForCustomer(int $customerId): void
    {
        $loanIds = DB::table('loans')
            ->where('customer_id', $customerId)
            ->pluck('id')
            ->all();

        foreach ($loanIds as $loanId) {
            $this->deletePermanently((int) $loanId);
        }
    }

    /**
     * Permanently delete a loan and all related financial records.
     */
    public function deletePermanently(int $loanId): void
    {
        if (!DB::table('loans')->where('id', $loanId)->exists()) {
            return;
        }

        DB::transaction(function () use ($loanId) {
            $this->purgeLoanAndRelations($loanId);
        });
    }

    /**
     * Delete this loan and every loan linked through loan_topups (restructure / top-up chain).
     */
    public function deleteTopupChainPermanently(int $loanId): void
    {
        if (!DB::table('loans')->where('id', $loanId)->exists()) {
            return;
        }

        $loanIds = $this->getTopupRelatedLoanIds($loanId);

        DB::transaction(function () use ($loanIds) {
            if (Schema::hasTable('loan_topups')) {
                DB::table('loan_topups')
                    ->where(function ($query) use ($loanIds) {
                        $query->whereIn('old_loan_id', $loanIds)
                            ->orWhereIn('new_loan_id', $loanIds);
                    })
                    ->delete();
            }

            // Delete newer loans first (typically higher IDs after restructure).
            rsort($loanIds, SORT_NUMERIC);

            foreach ($loanIds as $id) {
                if (DB::table('loans')->where('id', $id)->exists()) {
                    $this->purgeLoanAndRelations((int) $id);
                }
            }
        });
    }

    public function hasTopupLinks(int $loanId): bool
    {
        if (!Schema::hasTable('loan_topups')) {
            return false;
        }

        return DB::table('loan_topups')
            ->where('old_loan_id', $loanId)
            ->orWhere('new_loan_id', $loanId)
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function getTopupRelatedLoanIds(int $loanId): array
    {
        if (!Schema::hasTable('loan_topups')) {
            return [$loanId];
        }

        $seen = [$loanId => true];
        $queue = [$loanId];

        while (!empty($queue)) {
            $current = array_shift($queue);

            $topups = DB::table('loan_topups')
                ->where('old_loan_id', $current)
                ->orWhere('new_loan_id', $current)
                ->get(['old_loan_id', 'new_loan_id']);

            foreach ($topups as $topup) {
                foreach ([$topup->old_loan_id, $topup->new_loan_id] as $linkedId) {
                    if ($linkedId && !isset($seen[$linkedId])) {
                        $seen[$linkedId] = true;
                        $queue[] = (int) $linkedId;
                    }
                }
            }
        }

        return array_map('intval', array_keys($seen));
    }

    /**
     * Human-readable summary for confirmation dialogs.
     */
    public function getTopupChainSummary(int $loanId): array
    {
        $ids = $this->getTopupRelatedLoanIds($loanId);

        $loans = Loan::with('customer:id,name')
            ->whereIn('id', $ids)
            ->get(['id', 'loanNo', 'status', 'amount', 'customer_id']);

        return [
            'count' => count($ids),
            'loans' => $loans->map(fn (Loan $loan) => [
                'id' => $loan->id,
                'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                'status' => $loan->status,
                'amount' => number_format((float) $loan->amount, 2),
                'customer' => optional($loan->customer)->name ?? 'N/A',
            ])->values()->all(),
        ];
    }

    public static function humanizeException(\Throwable $e): string
    {
        $message = $e->getMessage();

        if ($e instanceof QueryException && self::messageReferencesTopups($message)) {
            return 'This loan is linked to a top-up or restructured loan. You can delete the entire linked group (all related loans, repayments, receipts, and ledger entries) using the button below.';
        }

        if (str_contains($message, 'Integrity constraint violation') || str_contains($message, '1451')) {
            if (self::messageReferencesTopups($message)) {
                return 'This loan is part of a top-up / restructure record and cannot be removed on its own. Use "Delete entire top-up chain" to remove all linked loans and their transactions.';
            }

            if (preg_match('/`([^`]+)`\.`([^`]+)`/', $message, $matches)) {
                $table = $matches[2] ?? 'related records';

                return 'This loan still has linked ' . str_replace('_', ' ', $table) . '. Remove those records first, or delete the entire top-up chain if this was a restructured loan.';
            }

            return 'This loan cannot be deleted because other records in the system still reference it.';
        }

        if (str_contains($message, 'repayments')) {
            return 'This loan still has repayment records. Delete repayments first, or use "Delete entire top-up chain" to remove everything linked to this loan.';
        }

        return 'Could not delete this loan. Please try again or delete the entire top-up chain if this loan was restructured.';
    }

    public static function messageReferencesTopups(string $message): bool
    {
        return str_contains($message, 'loan_topups')
            || str_contains($message, 'loan_topup');
    }

    protected function purgeLoanAndRelations(int $loanId): void
    {
        (new LoanRepaymentService())->deleteAllRepaymentsForLoan($loanId);

        $receiptIds = DB::table('receipts')
            ->where('reference_type', 'Loan Disbursement')
            ->where(function ($query) use ($loanId) {
                $query->where('reference_number', $loanId)
                    ->orWhere('reference', $loanId);
            })
            ->pluck('id')
            ->all();

        if (!empty($receiptIds)) {
            if (Schema::hasTable('receipt_items')) {
                DB::table('receipt_items')->whereIn('receipt_id', $receiptIds)->delete();
            }
            DB::table('receipts')->whereIn('id', $receiptIds)->delete();
        }

        $scheduleIds = DB::table('loan_schedules')->where('loan_id', $loanId)->pluck('id')->all();

        DB::table('gl_transactions')
            ->where('transaction_id', $loanId)
            ->where('transaction_type', 'Loan Disbursement')
            ->delete();

        if (!empty($scheduleIds)) {
            DB::table('gl_transactions')
                ->whereIn('transaction_id', $scheduleIds)
                ->whereIn('transaction_type', ['Penalty', 'Mature Interest'])
                ->delete();
        }

        $paymentIds = DB::table('payments')
            ->where('reference_type', 'Loan Payment')
            ->where('reference', $loanId)
            ->pluck('id')
            ->all();

        if (!empty($paymentIds)) {
            if (Schema::hasTable('payment_items')) {
                DB::table('payment_items')->whereIn('payment_id', $paymentIds)->delete();
            }
            DB::table('payments')->whereIn('id', $paymentIds)->delete();
        }

        DB::table('loan_schedules')->where('loan_id', $loanId)->delete();

        if (Schema::hasTable('journals')) {
            $journalsQuery = DB::table('journals')
                ->where('reference_type', 'Loan Disbursement')
                ->where(function ($query) use ($loanId) {
                    $query->where('reference', (string) $loanId);
                    if (Schema::hasColumn('journals', 'reference_number')) {
                        $query->orWhere('reference_number', (string) $loanId);
                    }
                });

            $journalIds = $journalsQuery->pluck('id')->all();

            if (!empty($journalIds) && Schema::hasTable('journal_items')) {
                DB::table('journal_items')->whereIn('journal_id', $journalIds)->delete();
            }

            if (!empty($journalIds)) {
                DB::table('journals')->whereIn('id', $journalIds)->delete();
            }
        }

        $this->deleteLoanDependentRecords($loanId);

        DB::table('loans')->where('id', $loanId)->delete();
    }

    protected function deleteLoanDependentRecords(int $loanId): void
    {
        if (Schema::hasTable('loan_topups')) {
            DB::table('loan_topups')
                ->where(function ($query) use ($loanId) {
                    $query->where('old_loan_id', $loanId)
                        ->orWhere('new_loan_id', $loanId);
                })
                ->delete();
        }

        if (Schema::hasTable('loan_writeoffs')) {
            DB::table('loan_writeoffs')->where('loan_id', $loanId)->delete();
        }

        if (Schema::hasTable('loans') && Schema::hasColumn('loans', 'top_up_id')) {
            DB::table('loans')->where('top_up_id', $loanId)->update(['top_up_id' => null]);
        }
    }
}
