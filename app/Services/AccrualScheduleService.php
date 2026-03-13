<?php

namespace App\Services;

use App\Models\AccrualSchedule;
use App\Models\AccrualJournal;
use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\GlTransaction;
use App\Models\Payment;
use App\Models\PaymentItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AccrualScheduleService
{
    /**
     * Generate schedule number
     * Handles soft-deleted records to avoid duplicate number conflicts
     * 
     * Strategy:
     * 1. Find the highest existing schedule number for the year (including soft-deleted)
     * 2. Increment from there to ensure uniqueness
     * 3. This allows reusing numbers from soft-deleted records while avoiding conflicts
     */
    public function generateScheduleNumber($companyId)
    {
        $prefix = 'ACC';
        $year = date('Y');
        
        // Find the highest schedule number for this year (including soft-deleted records)
        $lastSchedule = AccrualSchedule::withTrashed()
            ->where('company_id', $companyId)
            ->where('schedule_number', 'like', $prefix . '-' . $year . '-%')
            ->orderBy('schedule_number', 'desc')
            ->first();
        
        $nextNumber = 1;
        
        if ($lastSchedule) {
            // Extract the number part from the last schedule number (e.g., "ACC-2025-0001" -> 1)
            $parts = explode('-', $lastSchedule->schedule_number);
            if (count($parts) === 3 && is_numeric($parts[2])) {
                $nextNumber = (int)$parts[2] + 1;
            }
        }
        
        // Generate the number
        $scheduleNumber = sprintf('%s-%s-%04d', $prefix, $year, $nextNumber);
        
        // Double-check if this number exists (shouldn't happen, but safety check)
        // If it exists, increment until we find an available number
        $attempts = 0;
        $maxAttempts = 100; // Safety limit
        
        while (AccrualSchedule::withTrashed()->where('schedule_number', $scheduleNumber)->exists() && $attempts < $maxAttempts) {
            $nextNumber++;
            $scheduleNumber = sprintf('%s-%s-%04d', $prefix, $year, $nextNumber);
            $attempts++;
        }
        
        if ($attempts >= $maxAttempts) {
            throw new \Exception('Unable to generate unique schedule number after ' . $maxAttempts . ' attempts.');
        }
        
        return $scheduleNumber;
    }

    /**
     * Calculate amortisation periods and amounts
     *
     * Both ACCRUALS and PREPAYMENTS are amortised across the full
     * schedule period using day‑based proration. The difference
     * between the two is handled in the double‑entry logic when
     * journals are created (see createJournalItems()).
     */
    public function calculateAmortisationSchedule(AccrualSchedule $schedule)
    {
        $startDate = Carbon::parse($schedule->start_date);
        $endDate = Carbon::parse($schedule->end_date);
        $totalAmount = $schedule->total_amount;
        
        $periods = [];
        $currentDate = $startDate->copy();

        // Pre‑compute schedule span in days (inclusive) for day‑based proration
        $scheduleStart = $startDate->copy()->startOfDay();
        $scheduleEnd = $endDate->copy()->startOfDay();
        $totalDays = (int) $scheduleStart->diffInDays($scheduleEnd) + 1;
        if ($totalDays <= 0) {
            return $periods;
        }
        
        while ($currentDate->lte($endDate)) {
            $periodEnd = $this->getPeriodEnd($currentDate, $schedule->frequency);
            
            // Don't exceed the schedule end date
            if ($periodEnd->gt($endDate)) {
                $periodEnd = $endDate->copy();
            }
            
            // Calculate days in period (inclusive of both start and end dates)
            // Use startOfDay() to ensure we're working with dates only, avoiding time component issues
            $periodStart = $currentDate->copy()->startOfDay();
            $periodEndDate = $periodEnd->copy()->startOfDay();
            
            // Calculate calendar days (inclusive) - always a whole number
            $daysInPeriod = (int) $periodStart->diffInDays($periodEndDate) + 1;
            
            // Calculate prorated amount (IFRS compliant - exact days) for both
            // accruals and prepayments. The difference in treatment is in the
            // ledger accounts, not the timing pattern.
            $amount = ($totalAmount / $totalDays) * $daysInPeriod;
            
            $periods[] = [
                'period' => $currentDate->format('Y-m'),
                'period_start_date' => $currentDate->copy(),
                'period_end_date' => $periodEnd->copy(),
                'days_in_period' => $daysInPeriod,
                'amortisation_amount' => round($amount, 2),
            ];
            
            // Move to next period
            $currentDate = $periodEnd->copy()->addDay();
        }
        
        return $periods;
    }

    /**
     * Get period length based on frequency
     */
    private function getPeriodLength($frequency)
    {
        return match($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'custom' => null, // Will use custom_periods
            default => 1,
        };
    }

    /**
     * Get period end date
     */
    private function getPeriodEnd(Carbon $startDate, $frequency)
    {
        return match($frequency) {
            'monthly' => $startDate->copy()->endOfMonth(),
            'quarterly' => $startDate->copy()->addMonths(2)->endOfMonth(),
            'custom' => $startDate->copy()->addMonths(1)->subDay(), // Default to monthly if custom not specified
            default => $startDate->copy()->endOfMonth(),
        };
    }

    /**
     * Generate journals for a schedule
     */
    public function generateJournals(AccrualSchedule $schedule, $periods = null)
    {
        if (!$periods) {
            $periods = $this->calculateAmortisationSchedule($schedule);
        }

        $journals = [];
        
        foreach ($periods as $periodData) {
            // Check if journal already exists for this period
            $existingJournal = AccrualJournal::where('accrual_schedule_id', $schedule->id)
                ->where('period', $periodData['period'])
                ->first();
            
            if ($existingJournal && $existingJournal->status === 'posted') {
                continue; // Skip if already posted
            }
            
            if ($existingJournal) {
                // Update existing pending journal
                $journal = $existingJournal;
            } else {
                // Create new journal
                $journal = new AccrualJournal();
                $journal->accrual_schedule_id = $schedule->id;
            }
            
            $journal->period = $periodData['period'];
            $journal->period_start_date = $periodData['period_start_date'];
            $journal->period_end_date = $periodData['period_end_date'];
            $journal->days_in_period = $periodData['days_in_period'];
            $journal->amortisation_amount = $periodData['amortisation_amount'];
            $journal->fx_rate = $this->getFxRate($schedule->currency_code, $periodData['period_end_date']);
            $journal->home_currency_amount = $periodData['amortisation_amount'] * $journal->fx_rate;
            $journal->narration = $this->generateNarration($schedule, $periodData['period']);
            $journal->company_id = $schedule->company_id;
            $journal->branch_id = $schedule->branch_id;
            $journal->status = 'pending';
            $journal->save();
            
            $journals[] = $journal;
        }
        
        return $journals;
    }

    /**
     * Generate narration for journal
     */
    private function generateNarration(AccrualSchedule $schedule, $period)
    {
        $category = $schedule->category_name;
        $periodName = Carbon::createFromFormat('Y-m', $period)->format('M Y');
        
        if ($schedule->schedule_type === 'accrual') {
            $type = $schedule->nature === 'expense' ? 'Accrued Expense' : 'Accrued Income';
            return "{$type} Recognition for {$category} – {$periodName}";
        }
        
        return "Amortisation for {$category} – {$periodName}";
    }

    /**
     * Get FX rate for currency and date
     */
    public function getFxRate($currencyCode, $date)
    {
        if ($currencyCode === 'TZS') {
            return 1.0;
        }
        
        // Get FX rate from fx_rates table
        $fxRate = \App\Models\FxRate::where('currency_code', $currencyCode)
            ->where('rate_date', '<=', $date)
            ->orderBy('rate_date', 'desc')
            ->first();
        
        return $fxRate ? $fxRate->rate : 1.0;
    }

    /**
     * Create initial payment/receipt journal entry for prepayments
     * This records the actual cash/bank movement when prepayment is made/received
     */
    public function createInitialPaymentJournal(AccrualSchedule $schedule)
    {
        // Only create initial journal for prepayments (not accruals)
        if ($schedule->schedule_type !== 'prepayment') {
            return null;
        }

        // Only create if payment method is set
        if (!$schedule->payment_method) {
            return null;
        }

        // Don't create if already exists
        if ($schedule->initial_journal_id) {
            return $schedule->initialJournal;
        }

        DB::beginTransaction();
        try {
            $paymentDate = $schedule->payment_date ?? $schedule->start_date;
            $amount = $schedule->home_currency_amount;

            // Resolve branch_id with fallback (required for journal creation)
            $branchId = $schedule->branch_id 
                ?? session('branch_id') 
                ?? (Auth::user()->branch_id ?? null);
            
            if (!$branchId) {
                throw new \Exception('Branch ID is required for journal creation. Please ensure the schedule has a branch assigned or select a branch in your session.');
            }

            // Create journal entry
            $journalNumber = $this->generateJournalNumber($schedule->company_id);
            $journal = Journal::create([
                'reference' => $journalNumber,
                'reference_type' => 'Accrual Schedule Initial Payment',
                'description' => "Initial " . ($schedule->nature === 'expense' ? 'Payment' : 'Receipt') . " for {$schedule->schedule_number} - {$schedule->description}",
                'date' => $paymentDate,
                'branch_id' => $branchId,
                'user_id' => Auth::id(),
                'approved' => true, // Auto-approve initial payment/receipt
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            // Determine cash/bank account
            $cashBankAccountId = null;
            if ($schedule->payment_method === 'bank' && $schedule->bank_account_id) {
                $bankAccount = $schedule->bankAccount;
                $cashBankAccountId = $bankAccount ? $bankAccount->chart_account_id : null;
            } else {
                // Cash payment - get default cash account
                $cashAccountId = \App\Models\SystemSetting::where('key', 'inventory_default_cash_account')
                    ->where('company_id', $schedule->company_id)
                    ->value('value');
                
                if (!$cashAccountId) {
                    // Fallback: find cash account for this company
                    $cashAccount = \App\Models\ChartAccount::join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
                        ->where('account_class_groups.company_id', $schedule->company_id)
                        ->where(function($query) {
                            $query->where('chart_accounts.account_name', 'like', '%Cash%Hand%')
                                  ->orWhere('chart_accounts.account_name', 'like', '%Cash on Hand%');
                        })
                        ->select('chart_accounts.*')
                        ->first();
                    $cashAccountId = $cashAccount ? $cashAccount->id : null;
                }
                $cashBankAccountId = $cashAccountId;
            }

            if (!$cashBankAccountId) {
                throw new \Exception('Cash/Bank account not found. Please configure payment method.');
            }

            // Create journal items based on prepayment type
            if ($schedule->nature === 'expense') {
                // Prepaid Expense: Dr Prepaid Expense (Asset), Cr Cash/Bank
                $this->createJournalItem($journal, $schedule->balance_sheet_account_id, 'debit', $amount);
                $this->createJournalItem($journal, $cashBankAccountId, 'credit', $amount);
            } else {
                // Deferred Income: Dr Cash/Bank, Cr Deferred Income (Liability)
                $this->createJournalItem($journal, $cashBankAccountId, 'debit', $amount);
                $this->createJournalItem($journal, $schedule->balance_sheet_account_id, 'credit', $amount);
            }

            // Refresh journal and load items relationship
            $journal->refresh();
            $journal->load('items');

            // Create GL transactions for the journal
            // Since journal is approved, create GL transactions directly
            try {
                $journal->createGlTransactions();
            } catch (\Exception $e) {
                // Log error with full details
                \Log::error('Failed to create GL transactions for initial payment journal', [
                    'journal_id' => $journal->id,
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'items_count' => $journal->items->count()
                ]);
                // Re-throw the exception to prevent silent failures
                throw $e;
            }

            // Update schedule with initial journal reference
            $schedule->initial_journal_id = $journal->id;
            $schedule->save();

            DB::commit();
            return $journal;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post journal to GL
     */
    public function postJournal(AccrualJournal $journal)
    {
        $schedule = $journal->schedule;
        
        DB::beginTransaction();
        try {
            // Resolve branch_id with fallback (required for journal creation)
            $branchId = $schedule->branch_id 
                ?? session('branch_id') 
                ?? (Auth::user()->branch_id ?? null);
            
            if (!$branchId) {
                throw new \Exception('Branch ID is required for journal creation. Please ensure the schedule has a branch assigned or select a branch in your session.');
            }
            
            // Create journal entry
            $journalNumber = $this->generateJournalNumber($schedule->company_id);
            $glJournal = Journal::create([
                'reference' => $journalNumber,
                'reference_type' => 'Accrual Schedule Amortisation',
                'description' => $journal->narration,
                'date' => $journal->period_end_date,
                'branch_id' => $branchId,
                'user_id' => Auth::id(),
                'approved' => true, // Auto-approve system-generated journals
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
            
            // Create journal items based on schedule type and nature
            $this->createJournalItems($glJournal, $schedule, $journal);
            
            // Refresh journal and load items relationship
            $glJournal->refresh();
            $glJournal->load('items');
            
            // Create GL transactions for the journal
            \Log::info('About to create GL transactions for amortisation journal', [
                'journal_id' => $glJournal->id,
                'journal_reference' => $glJournal->reference,
                'accrual_journal_id' => $journal->id,
                'items_count' => $glJournal->items->count()
            ]);
            
            try {
                $glJournal->createGlTransactions();
                \Log::info('GL transactions created successfully for amortisation journal', [
                    'journal_id' => $glJournal->id,
                    'journal_reference' => $glJournal->reference
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to create GL transactions for amortisation journal', [
                    'journal_id' => $glJournal->id,
                    'journal_reference' => $glJournal->reference,
                    'accrual_journal_id' => $journal->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'items_count' => $glJournal->items->count()
                ]);
                // Re-throw the exception to prevent silent failures
                throw $e;
            }
            
            // Verify GL transactions were created
            $glTransactionsCount = GlTransaction::where('transaction_id', $glJournal->id)
                ->where('transaction_type', 'journal')
                ->count();
            
            if ($glTransactionsCount === 0) {
                \Log::error('GL transactions were not created for journal', [
                    'journal_id' => $glJournal->id,
                    'journal_reference' => $glJournal->reference,
                    'items_count' => $glJournal->items->count()
                ]);
                throw new \Exception("Failed to create GL transactions for journal {$glJournal->reference}. Expected {$glJournal->items->count()} transactions but found 0.");
            }
            
            \Log::info('Verified GL transactions created', [
                'journal_id' => $glJournal->id,
                'journal_reference' => $glJournal->reference,
                'gl_transactions_count' => $glTransactionsCount,
                'expected_count' => $glJournal->items->count()
            ]);
            
            // Update journal reference
            $journal->journal_id = $glJournal->id;
            $journal->status = 'posted';
            $journal->posted_at = now();
            $journal->posted_by = Auth::id();
            $journal->save();
            
            // Update schedule amortised amount
            $schedule->amortised_amount += $journal->home_currency_amount;
            $schedule->remaining_amount = $schedule->total_amount - $schedule->amortised_amount;
            $schedule->last_posted_period = $journal->period_end_date;
            $schedule->save();

            // Create Payment and PaymentItems so the posted accrual is visible in Payments list (GL already created by journal above)
            $payment = Payment::create([
                'reference' => $glJournal->reference,
                'reference_type' => 'accrual_schedule_amortisation',
                'reference_number' => $schedule->schedule_number . ' - Period ' . $journal->period,
                'amount' => $journal->home_currency_amount,
                'currency' => $schedule->currency_code ?? 'TZS',
                'exchange_rate' => 1,
                'amount_fcy' => $journal->home_currency_amount,
                'amount_lcy' => $journal->home_currency_amount,
                'wht_treatment' => 'NONE',
                'wht_rate' => 0,
                'wht_amount' => 0,
                'net_payable' => $journal->home_currency_amount,
                'total_cost' => $journal->home_currency_amount,
                'vat_mode' => 'NONE',
                'vat_amount' => 0,
                'base_amount' => $journal->home_currency_amount,
                'date' => $journal->period_end_date,
                'description' => $glJournal->description,
                'user_id' => Auth::id(),
                'bank_account_id' => $schedule->bank_account_id,
                'payee_type' => 'other',
                'payee_name' => 'Accrual: ' . $schedule->schedule_number,
                'branch_id' => $branchId,
                'approved' => true,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            foreach ($glJournal->items as $journalItem) {
                PaymentItem::create([
                    'payment_id' => $payment->id,
                    'chart_account_id' => $journalItem->chart_account_id,
                    'amount' => $journalItem->amount,
                    'wht_treatment' => 'NONE',
                    'wht_rate' => 0,
                    'wht_amount' => 0,
                    'base_amount' => $journalItem->amount,
                    'net_payable' => $journalItem->amount,
                    'total_cost' => $journalItem->amount,
                    'vat_mode' => 'NONE',
                    'vat_amount' => 0,
                    'description' => $journalItem->description ?? $glJournal->description,
                ]);
            }
            
            DB::commit();
            
            \Log::info('Journal posted successfully', [
                'journal_id' => $glJournal->id,
                'journal_reference' => $glJournal->reference,
                'gl_transactions_count' => $glTransactionsCount,
                'payment_id' => $payment->id,
            ]);
            
            return $glJournal;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create journal items based on IFRS double entry logic
     */
    private function createJournalItems(Journal $journal, AccrualSchedule $schedule, AccrualJournal $accrualJournal)
    {
        $amount = $accrualJournal->home_currency_amount;
        
        // Determine debit and credit accounts based on schedule type and nature
        if ($schedule->schedule_type === 'prepayment' && $schedule->nature === 'expense') {
            // Prepaid Expense: Dr Expense, Cr Prepaid Expense (Asset)
            $this->createJournalItem($journal, $schedule->expense_income_account_id, 'debit', $amount);
            $this->createJournalItem($journal, $schedule->balance_sheet_account_id, 'credit', $amount);
            
        } elseif ($schedule->schedule_type === 'accrual' && $schedule->nature === 'expense') {
            // Accrued Expense: Dr Expense, Cr Accrued Expense (Liability)
            $this->createJournalItem($journal, $schedule->expense_income_account_id, 'debit', $amount);
            $this->createJournalItem($journal, $schedule->balance_sheet_account_id, 'credit', $amount);
            
        } elseif ($schedule->schedule_type === 'prepayment' && $schedule->nature === 'income') {
            // Deferred Income: Dr Deferred Income (Liability), Cr Revenue
            $this->createJournalItem($journal, $schedule->balance_sheet_account_id, 'debit', $amount);
            $this->createJournalItem($journal, $schedule->expense_income_account_id, 'credit', $amount);
            
        } elseif ($schedule->schedule_type === 'accrual' && $schedule->nature === 'income') {
            // Accrued Income: Dr Accrued Income (Asset), Cr Revenue
            $this->createJournalItem($journal, $schedule->balance_sheet_account_id, 'debit', $amount);
            $this->createJournalItem($journal, $schedule->expense_income_account_id, 'credit', $amount);
        }
        
        // Handle FX differences if applicable
        if ($schedule->currency_code !== 'TZS' && $accrualJournal->fx_difference != 0) {
            // FX gain/loss entry
            $fxAccount = $accrualJournal->fxGainLossAccount;
            if ($fxAccount) {
                $nature = $accrualJournal->fx_difference > 0 ? 'credit' : 'debit';
                $this->createJournalItem($journal, $fxAccount->id, $nature, abs($accrualJournal->fx_difference));
            }
        }
    }

    /**
     * Create a journal item
     */
    private function createJournalItem(Journal $journal, $accountId, $nature, $amount)
    {
        $item = new JournalItem();
        $item->journal_id = $journal->id;
        $item->chart_account_id = $accountId;
        $item->nature = $nature;
        $item->amount = $amount;
        $item->description = $journal->description; // Add description from journal
        $item->save();
        
        \Log::info('Journal item created', [
            'journal_id' => $journal->id,
            'journal_reference' => $journal->reference,
            'chart_account_id' => $accountId,
            'nature' => $nature,
            'amount' => $amount,
            'item_id' => $item->id
        ]);
    }

    /**
     * Generate journal number
     */
    private function generateJournalNumber($companyId)
    {
        $prefix = 'JNL';
        $year = date('Y');
        // Journals table doesn't have company_id, filter by branch->company relationship
        $count = Journal::whereHas('branch', function($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->whereYear('created_at', $year)
            ->count() + 1;
        
        return sprintf('%s-%s-%06d', $prefix, $year, $count);
    }

    /**
     * Recalculate schedule after edit
     */
    public function recalculateSchedule(AccrualSchedule $schedule)
    {
        // Cancel all future (unposted) journals
        AccrualJournal::where('accrual_schedule_id', $schedule->id)
            ->where('status', 'pending')
            ->delete();
        
        // Recalculate remaining amount
        $postedAmount = AccrualJournal::where('accrual_schedule_id', $schedule->id)
            ->where('status', 'posted')
            ->sum('home_currency_amount');
        
        $schedule->amortised_amount = $postedAmount;
        $schedule->remaining_amount = $schedule->total_amount - $postedAmount;
        $schedule->save();
        
        // Regenerate future journals
        $this->generateJournals($schedule);
    }

    /**
     * Auto-reverse accrual journals on the first day of the next period
     * This is IFRS compliant - reverses accruals so actual invoices can be recorded without double entry
     * 
     * Only applies to ACCRUALS (not prepayments)
     * 
     * @param Carbon|null $reversalDate Date to post reversals (defaults to today, should be 1st of month)
     * @return array Statistics of reversal process
     */
    public function autoReverseAccruals(Carbon $reversalDate = null)
    {
        if (!$reversalDate) {
            $reversalDate = Carbon::now();
        }

        // Only process on the 1st of the month
        if ($reversalDate->day !== 1) {
            \Log::info('Auto-reversal skipped: Not the 1st of the month', [
                'date' => $reversalDate->format('Y-m-d')
            ]);
            return [
                'processed' => 0,
                'skipped' => 0,
                'errors' => [],
                'message' => 'Auto-reversal only runs on the 1st of the month'
            ];
        }

        // Get the previous month (the period that just ended)
        $previousMonth = $reversalDate->copy()->subMonth();
        $previousPeriod = $previousMonth->format('Y-m');

        \Log::info('Starting auto-reversal for accruals', [
            'reversal_date' => $reversalDate->format('Y-m-d'),
            'previous_period' => $previousPeriod
        ]);

        $processed = 0;
        $skipped = 0;
        $errors = [];

        // Find all posted accrual journals from the previous period that haven't been reversed
        // Only for ACCRUALS (not prepayments)
        $accrualJournals = AccrualJournal::whereHas('schedule', function($query) {
                $query->where('schedule_type', 'accrual')
                      ->where('status', 'active');
            })
            ->where('period', $previousPeriod)
            ->where('status', 'posted')
            ->whereNull('reversal_journal_id') // Not yet reversed
            ->with('schedule')
            ->get();

        foreach ($accrualJournals as $accrualJournal) {
            try {
                $this->createReversalJournal($accrualJournal, $reversalDate);
                $processed++;
            } catch (\Exception $e) {
                $errors[] = [
                    'accrual_journal_id' => $accrualJournal->id,
                    'schedule_number' => $accrualJournal->schedule->schedule_number ?? 'N/A',
                    'error' => $e->getMessage()
                ];
                \Log::error('Failed to create reversal journal', [
                    'accrual_journal_id' => $accrualJournal->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        \Log::info('Auto-reversal completed', [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => count($errors)
        ]);

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "Processed {$processed} reversal(s), " . count($errors) . " error(s)"
        ];
    }

    /**
     * Create a reversal journal entry for an accrual journal
     * 
     * Reversal entries:
     * - Accrued Expense: Dr Accrued Expense, Cr Expense (reverses the original Dr Expense, Cr Accrued Expense)
     * - Accrued Income: Dr Revenue, Cr Accrued Income (reverses the original Dr Accrued Income, Cr Revenue)
     */
    private function createReversalJournal(AccrualJournal $accrualJournal, Carbon $reversalDate)
    {
        $schedule = $accrualJournal->schedule;
        
        // Only reverse accruals
        if ($schedule->schedule_type !== 'accrual') {
            throw new \Exception('Reversals only apply to accruals, not prepayments');
        }

        // Check if already reversed
        if ($accrualJournal->reversal_journal_id) {
            throw new \Exception('This accrual journal has already been reversed');
        }

        DB::beginTransaction();
        try {
            // Resolve branch_id
            $branchId = $schedule->branch_id 
                ?? session('branch_id') 
                ?? (Auth::user()->branch_id ?? null);
            
            if (!$branchId) {
                throw new \Exception('Branch ID is required for journal creation.');
            }

            // Create reversal journal entry
            $journalNumber = $this->generateJournalNumber($schedule->company_id);
            $reversalJournal = Journal::create([
                'reference' => $journalNumber,
                'reference_type' => 'Accrual Schedule Auto-Reversal',
                'description' => "Auto-Reversal: " . $accrualJournal->narration,
                'date' => $reversalDate->copy()->startOfDay(),
                'branch_id' => $branchId,
                'user_id' => Auth::id() ?? 1, // System user if no auth
                'approved' => true, // Auto-approve system-generated reversals
                'approved_by' => Auth::id() ?? 1,
                'approved_at' => now(),
            ]);

            $amount = $accrualJournal->home_currency_amount;

            // Create reversal journal items (opposite of original entry)
            if ($schedule->nature === 'expense') {
                // Original: Dr Expense, Cr Accrued Expense
                // Reversal: Dr Accrued Expense, Cr Expense
                $this->createJournalItem($reversalJournal, $schedule->balance_sheet_account_id, 'debit', $amount);
                $this->createJournalItem($reversalJournal, $schedule->expense_income_account_id, 'credit', $amount);
            } else {
                // Original: Dr Accrued Income, Cr Revenue
                // Reversal: Dr Revenue, Cr Accrued Income
                $this->createJournalItem($reversalJournal, $schedule->expense_income_account_id, 'debit', $amount);
                $this->createJournalItem($reversalJournal, $schedule->balance_sheet_account_id, 'credit', $amount);
            }

            // Refresh journal and load items
            $reversalJournal->refresh();
            $reversalJournal->load('items');

            // Create GL transactions for the reversal journal
            try {
                $reversalJournal->createGlTransactions();
            } catch (\Exception $e) {
                \Log::error('Failed to create GL transactions for reversal journal', [
                    'journal_id' => $reversalJournal->id,
                    'accrual_journal_id' => $accrualJournal->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            // Update accrual journal with reversal reference
            $accrualJournal->reversal_journal_id = $reversalJournal->id;
            $accrualJournal->status = 'reversed';
            $accrualJournal->save();

            DB::commit();

            \Log::info('Reversal journal created successfully', [
                'reversal_journal_id' => $reversalJournal->id,
                'accrual_journal_id' => $accrualJournal->id,
                'schedule_number' => $schedule->schedule_number
            ]);

            return $reversalJournal;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Auto-post pending accrual journals at month-end
     * Posts all pending accrual journals whose period_end_date has passed
     * 
     * @param Carbon|null $asOfDate Date to check against (defaults to today)
     * @return array Statistics of posting process
     */
    public function autoPostPendingAccruals(Carbon $asOfDate = null)
    {
        if (!$asOfDate) {
            $asOfDate = Carbon::now();
        }

        \Log::info('Starting auto-post for pending accruals', [
            'as_of_date' => $asOfDate->format('Y-m-d')
        ]);

        $processed = 0;
        $skipped = 0;
        $errors = [];

        // Find all pending accrual journals that should be posted
        // Only for ACCRUALS (not prepayments) that are approved/active
        $pendingJournals = AccrualJournal::whereHas('schedule', function($query) {
                $query->where('schedule_type', 'accrual')
                      ->whereIn('status', ['approved', 'active']);
            })
            ->where('status', 'pending')
            ->where('period_end_date', '<=', $asOfDate->copy()->endOfDay())
            ->with('schedule')
            ->get();

        foreach ($pendingJournals as $journal) {
            try {
                // Post the journal
                $this->postJournal($journal);
                $processed++;
            } catch (\Exception $e) {
                $errors[] = [
                    'accrual_journal_id' => $journal->id,
                    'schedule_number' => $journal->schedule->schedule_number ?? 'N/A',
                    'error' => $e->getMessage()
                ];
                \Log::error('Failed to auto-post accrual journal', [
                    'accrual_journal_id' => $journal->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        \Log::info('Auto-post completed', [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => count($errors)
        ]);

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'message' => "Processed {$processed} journal(s), " . count($errors) . " error(s)"
        ];
    }
}

