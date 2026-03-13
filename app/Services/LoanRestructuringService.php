<?php
namespace App\Services;

use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\LoanTopup;
use Illuminate\Support\Facades\DB;
use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\GlTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LoanRestructuringService
{
    /**
     * Restructure a loan's repayment schedule.
     *
     * @param Loan $loan
     * @param array $params [
     *   'new_tenure' => int,
     *   'new_interest_rate' => float,
     *   'new_start_date' => date,
     *   'penalty_waived' => bool,
     *   'interest_type' => 'flat'|'reducing'
     * ]
     * @param int $userId
     * @return Loan The new restructured loan (new loan ID, old loan marked as restructured)
     */
    public function restructure(Loan $loan, array $params, int $userId)
    {
        return DB::transaction(function () use ($loan, $params, $userId) {
            // 1. Get all schedules with repayments
            $schedules = $loan->schedule()->with('repayments')->get();
            
            // 2. Separate paid and unpaid schedules
            // Ensure repayments are loaded for each schedule
            $schedules->load('repayments');
            
            $paidSchedules = $schedules->filter(function ($schedule) {
                return $schedule->is_fully_paid ?? false;
            });
            
            $unpaidSchedules = $schedules->filter(function ($schedule) {
                return !($schedule->is_fully_paid ?? false);
            });

            // 3. Calculate outstanding balances
            // Calculate outstanding principal from original loan amount minus total paid
            // This avoids rounding errors from summing schedule principal amounts
            $totalPaidPrincipal = $schedules->sum(function ($schedule) {
                return $schedule->repayments->sum('principal');
            });
            $outstandingPrincipal = max(0, $loan->amount - $totalPaidPrincipal);
            
            // Calculate outstanding interest and penalty from unpaid schedules
            $outstandingInterest = 0;
            $outstandingPenalty = 0;
            
            foreach ($unpaidSchedules as $schedule) {
                $paidInterest = $schedule->repayments->sum('interest');
                $paidPenalty = $schedule->repayments->sum('penalt_amount');
                
                $outstandingInterest += max(0, $schedule->interest - $paidInterest);
                $outstandingPenalty += max(0, $schedule->penalty_amount - $paidPenalty);
            }
            
            // Round to 2 decimal places to avoid floating point precision issues
            $outstandingPrincipal = round($outstandingPrincipal, 2);
            $outstandingInterest = round($outstandingInterest, 2);
            $outstandingPenalty = round($outstandingPenalty, 2);

            // Apply penalty waiver if requested
            if (isset($params['penalty_waived']) && $params['penalty_waived']) {
                $outstandingPenalty = 0;
            }

            // 4. Calculate new principal and interest amount for the new loan
            $newPrincipal = $outstandingPrincipal + $outstandingInterest + $outstandingPenalty;
            $newInterestAmount = 0;
            $tenure = $params['new_tenure'];
            $interestRate = $params['new_interest_rate'];
            $interestCycle = $loan->interest_cycle ?? 'monthly';
            
            // Get interest type from product
            $interestType = 'flat_rate';
            if ($loan->product && $loan->product->interest_method) {
                $method = strtolower($loan->product->interest_method);
                if (in_array($method, ['flat_rate', 'reducing_balance_with_equal_installment', 'reducing_balance_with_equal_principal'])) {
                    $interestType = $method;
                }
            }
            
            // Convert interest rate based on interest cycle (base is monthly)
            $convertedRate = $this->convertInterestRate($interestRate, $interestCycle);
            
            // Calculate interest based on type (using converted rate)
            if ($interestType === 'flat_rate') {
                $newInterestAmount = ($newPrincipal * $convertedRate / 100) * $tenure;
            } elseif ($interestType === 'reducing_balance_with_equal_installment') {
                $cycleRate = $convertedRate / 100;
                $remainingPrincipal = $newPrincipal;
                for ($i = 1; $i <= $tenure; $i++) {
                    $interestPart = $remainingPrincipal * $cycleRate;
                    $newInterestAmount += $interestPart;
                    $emi = ($cycleRate > 0)
                        ? ($newPrincipal * $cycleRate * pow(1 + $cycleRate, $tenure)) / (pow(1 + $cycleRate, $tenure) - 1)
                        : $newPrincipal / $tenure;
                    $principalPart = $emi - $interestPart;
                    $remainingPrincipal -= $principalPart;

                    // Log each installment
                    Log::info("Restructure Schedule", [
                        'installment_no'     => $i,
                        'interest_part'      => round($interestPart, 2),
                        'principal_part'     => round($principalPart, 2),
                        'emi'                => round($emi, 2),
                        'remaining_balance'  => round(max($remainingPrincipal, 0), 2),
                    ]);
                }
            } elseif ($interestType === 'reducing_balance_with_equal_principal') {
                $cyclePrincipal = $newPrincipal / $tenure;
                $cycleRate = $convertedRate / 100;
                for ($i = 1; $i <= $tenure; $i++) {
                    $remainingPrincipal = $newPrincipal - $cyclePrincipal * ($i - 1);
                    $interestPart = $remainingPrincipal * $cycleRate;
                    $newInterestAmount += $interestPart;
                }
            }

            // 5. Calculate last repayment date based on interest cycle
            $startDate = Carbon::parse($params['new_start_date']);
            $lastRepaymentDate = $this->calculateLastRepaymentDate($startDate, $tenure, $loan->interest_cycle ?? 'monthly');
            
            // 6. Mark unpaid schedules of old loan as restructured (not deleted)
            foreach ($unpaidSchedules as $schedule) {
                $schedule->status = 'restructured';
                $schedule->save();
            }
            
            Log::info('Unpaid schedules marked as restructured', [
                'loan_id' => $loan->id,
                'restructured_count' => $unpaidSchedules->count()
            ]);

            // 7. Create the new restructured loan
            $newLoan = Loan::create([
                'customer_id' => $loan->customer_id,
                'group_id' => $loan->group_id,
                'product_id' => $loan->product_id,
                'amount' => $newPrincipal,
                'interest' => $params['new_interest_rate'],
                'interest_amount' => round($newInterestAmount, 2),
                'period' => $params['new_tenure'],
                'amount_total' => $newPrincipal + round($newInterestAmount, 2),
                'bank_account_id' => $loan->bank_account_id,
                'date_applied' => Carbon::parse($params['new_start_date'])->toDateString(),
                'disbursed_on' => Carbon::parse($params['new_start_date'])->toDateString(),
                'status' => 'active',
                'sector' => $loan->sector,
                'interest_cycle' => $loan->interest_cycle,
                'loan_officer_id' => $loan->loan_officer_id,
                'branch_id' => $loan->branch_id,
                'top_up_id' => $loan->id, // Link to old loan
                'first_repayment_date' => $params['new_start_date'],
                'last_repayment_date' => $lastRepaymentDate->toDateString(),
            ]);

            // 8. Mark the old loan as restructured
            $loan->status = 'restructured';
            $loan->save();

            // 9. Create GL transactions to close old loan and create new loan receivable
            $this->createRestructureGlTransactions($loan, $newLoan, $outstandingPrincipal);

            // 10. Handle double entry for capitalization if needed
            if ($outstandingInterest > 0 || $outstandingPenalty > 0) {
                $product = $newLoan->product;
                $principalAccountId = $product->principal_receivable_account_id;
                $interestAccountId = $product->interest_receivable_account_id;
                $penaltyAccountId = null;
                
                $penalty = $product->penalty; // uses getPenaltyAttribute()
                if ($penalty && $penalty->penalty_receivables_account_id) {
                    $penaltyAccountId = $penalty->penalty_receivables_account_id;
                }

                $journal = Journal::create([
                    'date' => now(),
                    'reference' => $newLoan->id,
                    'reference_type' => 'Loan Restructuring',
                    'customer_id' => $newLoan->customer_id,
                    'description' => 'Loan Restructuring Capitalization',
                    'branch_id' => $newLoan->branch_id,
                    'user_id' => $userId,
                ]);

                // Interest capitalization
                if ($outstandingInterest > 0 && $interestAccountId && $principalAccountId) {
                    JournalItem::create([
                        'journal_id' => $journal->id,
                        'chart_account_id' => $interestAccountId,
                        'amount' => $outstandingInterest,
                        'description' => 'Capitalize Interest',
                        'nature' => 'credit',
                    ]);
                    JournalItem::create([
                        'journal_id' => $journal->id,
                        'chart_account_id' => $principalAccountId,
                        'amount' => $outstandingInterest,
                        'description' => 'Capitalize Interest',
                        'nature' => 'debit',
                    ]);
                    GlTransaction::create([
                        'chart_account_id' => $interestAccountId,
                        'customer_id' => $newLoan->customer_id,
                        'amount' => $outstandingInterest,
                        'nature' => 'credit',
                        'transaction_id' => $newLoan->id,
                        'transaction_type' => 'Loan Restructuring',
                        'date' => now(),
                        'description' => 'Capitalize Interest',
                        'branch_id' => $newLoan->branch_id,
                        'user_id' => $userId,
                    ]);
                    GlTransaction::create([
                        'chart_account_id' => $principalAccountId,
                        'customer_id' => $newLoan->customer_id,
                        'amount' => $outstandingInterest,
                        'nature' => 'debit',
                        'transaction_id' => $newLoan->id,
                        'transaction_type' => 'Loan Restructuring',
                        'date' => now(),
                        'description' => 'Capitalize Interest',
                        'branch_id' => $newLoan->branch_id,
                        'user_id' => $userId,
                    ]);
                }

                // Penalty capitalization
                if ($outstandingPenalty > 0 && $penaltyAccountId && $principalAccountId) {
                    JournalItem::create([
                        'journal_id' => $journal->id,
                        'chart_account_id' => $penaltyAccountId,
                        'amount' => $outstandingPenalty,
                        'description' => 'Capitalize Penalty',
                        'nature' => 'credit',
                    ]);
                    JournalItem::create([
                        'journal_id' => $journal->id,
                        'chart_account_id' => $principalAccountId,
                        'amount' => $outstandingPenalty,
                        'description' => 'Capitalize Penalty',
                        'nature' => 'debit',
                    ]);
                    GlTransaction::create([
                        'chart_account_id' => $penaltyAccountId,
                        'customer_id' => $newLoan->customer_id,
                        'amount' => $outstandingPenalty,
                        'nature' => 'credit',
                        'transaction_id' => $newLoan->id,
                        'transaction_type' => 'Loan Restructuring',
                        'date' => now(),
                        'description' => 'Capitalize Penalty',
                        'branch_id' => $newLoan->branch_id,
                        'user_id' => $userId,
                    ]);
                    GlTransaction::create([
                        'chart_account_id' => $principalAccountId,
                        'customer_id' => $newLoan->customer_id,
                        'amount' => $outstandingPenalty,
                        'nature' => 'debit',
                        'transaction_id' => $newLoan->id,
                        'transaction_type' => 'Loan Restructuring',
                        'date' => now(),
                        'description' => 'Capitalize Penalty',
                        'branch_id' => $newLoan->branch_id,
                        'user_id' => $userId,
                    ]);
                }
            }

            // 11. Generate new repayment schedule for the new loan
            // Note: generateRepaymentSchedule expects the rate as stored in the loan (monthly base)
            // The method will handle interest cycle conversion internally
            $newLoan->generateRepaymentSchedule($params['new_interest_rate']);

            // 12. Create loan top-up record for tracking
            LoanTopup::create([
                'old_loan_id' => $loan->id,
                'new_loan_id' => $newLoan->id,
                'old_balance' => $outstandingPrincipal + $outstandingInterest + $outstandingPenalty,
                'topup_amount' => 0, // No new cash disbursed in restructuring
                'topup_type' => 'restructure',
            ]);

            Log::info('Loan restructured successfully', [
                'old_loan_id' => $loan->id,
                'new_loan_id' => $newLoan->id,
                'new_principal' => $newPrincipal,
                'new_interest_amount' => $newInterestAmount,
                'new_tenure' => $tenure,
                'new_interest_rate' => $interestRate,
                'outstanding_principal' => $outstandingPrincipal,
                'outstanding_interest' => $outstandingInterest,
                'outstanding_penalty' => $outstandingPenalty,
                'cancelled_schedules' => $unpaidSchedules->count(),
            ]);

            return $newLoan;
        });
    }

    /**
     * Convert interest rate based on interest cycle
     * Base is monthly (as stored in loan product)
     */
    private function convertInterestRate(float $monthlyRate, string $selectedCycle): float
    {
        switch (strtolower($selectedCycle)) {
            case 'daily':
                return $monthlyRate / 30;
            case 'weekly':
                return $monthlyRate / 4;
            case 'bimonthly':
                return $monthlyRate / 2;
            case 'monthly':
                return $monthlyRate; // Base rate
            case 'quarterly':
                return $monthlyRate * 4;
            case 'semi_annually':
                return $monthlyRate * 6;
            case 'annually':
                return $monthlyRate * 12;
            default:
                return $monthlyRate; // Default to monthly if unknown
        }
    }

    /**
     * Calculate last repayment date based on interest cycle
     */
    private function calculateLastRepaymentDate(Carbon $startDate, int $tenure, string $interestCycle): Carbon
    {
        $date = clone $startDate;
        
        switch (strtolower($interestCycle)) {
            case 'daily':
                $date->addDays($tenure - 1);
                break;
            case 'weekly':
                $date->addWeeks($tenure - 1);
                break;
            case 'bimonthly':
                // Bi-monthly = every 2 weeks
                $date->addWeeks(($tenure - 1) * 2);
                break;
            case 'monthly':
                $date->addMonths($tenure - 1);
                break;
            case 'quarterly':
                $date->addMonths(($tenure - 1) * 3);
                break;
            case 'semi_annually':
                $date->addMonths(($tenure - 1) * 6);
                break;
            case 'annually':
                $date->addYears($tenure - 1);
                break;
            default:
                $date->addMonths($tenure - 1);
        }
        
        return $date;
    }

    /**
     * Create GL transactions for loan restructuring
     * Closes old loan receivable and creates new loan receivable
     */
    private function createRestructureGlTransactions(Loan $oldLoan, Loan $newLoan, float $outstandingPrincipal)
    {
        $userId = auth()->id() ?? 1;
        $branchId = $oldLoan->branch_id ?? auth()->user()->branch_id ?? 1;
        $product = $oldLoan->product;
        
        if (!$product) {
            Log::warning('Product not found for loan restructuring GL transactions', [
                'old_loan_id' => $oldLoan->id,
                'new_loan_id' => $newLoan->id
            ]);
            return;
        }

        $principalAccountId = $product->principal_receivable_account_id;
        
        if (!$principalAccountId) {
            Log::warning('Principal receivable account not found for loan restructuring', [
                'old_loan_id' => $oldLoan->id,
                'product_id' => $product->id
            ]);
            return;
        }

        // 1. Close old loan receivable (Credit the old loan receivable)
        GlTransaction::create([
            'chart_account_id' => $principalAccountId,
            'customer_id' => $oldLoan->customer_id,
            'amount' => $outstandingPrincipal,
            'nature' => 'credit',
            'transaction_id' => $oldLoan->id,
            'transaction_type' => 'Loan Restructuring - Old Loan Closure',
            'date' => now(),
            'description' => "Restructure: Close old loan receivable (Loan #{$oldLoan->id})",
            'branch_id' => $branchId,
            'user_id' => $userId,
        ]);

        // 2. Create new loan receivable (Debit the new loan receivable)
        GlTransaction::create([
            'chart_account_id' => $principalAccountId,
            'customer_id' => $newLoan->customer_id,
            'amount' => $newLoan->amount,
            'nature' => 'debit',
            'transaction_id' => $newLoan->id,
            'transaction_type' => 'Loan Restructuring - New Loan',
            'date' => now(),
            'description' => "Restructure: Create new loan receivable (Loan #{$newLoan->id})",
            'branch_id' => $branchId,
            'user_id' => $userId,
        ]);

        Log::info('Restructure GL transactions created', [
            'old_loan_id' => $oldLoan->id,
            'new_loan_id' => $newLoan->id,
            'outstanding_principal' => $outstandingPrincipal,
            'new_loan_amount' => $newLoan->amount
        ]);
    }
}
