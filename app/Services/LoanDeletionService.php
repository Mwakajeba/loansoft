<?php

namespace App\Services;

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

        (new LoanRepaymentService())->deleteAllRepaymentsForLoan($loanId);

        DB::transaction(function () use ($loanId) {
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
        });
    }

    protected function deleteLoanDependentRecords(int $loanId): void
    {
        if (Schema::hasTable('loan_topups')) {
            DB::table('loan_topups')
                ->where('old_loan_id', $loanId)
                ->orWhere('new_loan_id', $loanId)
                ->delete();
        }

        if (Schema::hasTable('loan_writeoffs')) {
            DB::table('loan_writeoffs')->where('loan_id', $loanId)->delete();
        }
    }
}
