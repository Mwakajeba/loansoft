<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\DailyInterestAccrual;
use App\Models\GlTransaction;
use App\Models\Journal;
use App\Models\JournalItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AccountingService
{
    /**
     * Post GL transactions and journal entries for daily interest accrual
     *
     * @param Loan $loan
     * @param DailyInterestAccrual $accrual
     * @param Carbon $date
     * @return void
     */
    public function postDailyInterestTransactions(Loan $loan, DailyInterestAccrual $accrual, Carbon $date): void
    {
        $product = $loan->product;

        if (!$product) {
            Log::warning("Loan {$loan->id} has no product. Cannot post GL transactions.");
            return;
        }

        // Get the GL accounts from loan product settings
        $interestReceivableAccountId = $product->interest_receivable_account_id;
        $interestRevenueAccountId = $product->interest_revenue_account_id;

        if (!$interestReceivableAccountId || !$interestRevenueAccountId) {
            Log::warning("Loan product {$product->id} missing interest GL accounts. Cannot post transactions.");
            return;
        }

        $description = "Daily interest accrual for loan {$loan->loanNo} - {$date->toDateString()}";
        $reference = "DIA-{$accrual->id}-" . $date->format('Ymd');

        // Create Journal Entry
        $journal = Journal::create([
            'date' => $date,
            'reference' => $reference,
            'reference_type' => 'DailyInterestAccrual',
            'customer_id' => $loan->customer_id,
            'description' => $description,
            'branch_id' => $loan->branch_id,
            'user_id' => $accrual->user_id,
        ]);

        // Create Journal Items - Debit: Interest Receivable
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $interestReceivableAccountId,
            'amount' => $accrual->daily_interest_amount,
            'nature' => 'debit',
            'description' => $description,
        ]);

        // Create Journal Items - Credit: Interest Revenue
        JournalItem::create([
            'journal_id' => $journal->id,
            'chart_account_id' => $interestRevenueAccountId,
            'amount' => $accrual->daily_interest_amount,
            'nature' => 'credit',
            'description' => $description,
        ]);

        // Debit: Interest Receivable (Asset Account) - GL Transaction
        GlTransaction::create([
            'chart_account_id' => $interestReceivableAccountId,
            'customer_id' => $loan->customer_id,
            'amount' => $accrual->daily_interest_amount,
            'nature' => 'debit',
            'transaction_id' => $accrual->id,
            'transaction_type' => 'DailyInterestAccrual',
            'date' => $date,
            'description' => $description,
            'branch_id' => $loan->branch_id,
            'user_id' => $accrual->user_id,
        ]);

        // Credit: Interest Income (Revenue Account) - GL Transaction
        GlTransaction::create([
            'chart_account_id' => $interestRevenueAccountId,
            'customer_id' => $loan->customer_id,
            'amount' => $accrual->daily_interest_amount,
            'nature' => 'credit',
            'transaction_id' => $accrual->id,
            'transaction_type' => 'DailyInterestAccrual',
            'date' => $date,
            'description' => $description,
            'branch_id' => $loan->branch_id,
            'user_id' => $accrual->user_id,
        ]);

        Log::info("GL transactions and journal entry {$journal->id} posted for daily interest accrual {$accrual->id}");
    }
}
