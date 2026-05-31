<?php

namespace App\Services;

use App\Models\AccruedPenalty;
use App\Models\GlTransaction;
use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Penalty;
use App\Support\Accounting\PenaltyConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic penalty accrual from /accounting/penalties settings + loan product grace period.
 */
class PenaltyAccrualService
{
    public readonly Carbon $accrualDate;

    public function __construct(Carbon $accrualDate)
    {
        $this->accrualDate = $accrualDate->copy()->startOfDay();
    }

    public static function forDate(?string $date = null): self
    {
        return new self($date ? Carbon::parse($date) : Carbon::today());
    }

    /**
     * Accrue penalties day-by-day from the first overdue schedule through $untilDate.
     * Used when creating back-dated loans so penalties match product/accounting settings.
     */
    public function catchUpForLoan(Loan $loan, ?Carbon $untilDate = null): float
    {
        $untilDate = ($untilDate ?? Carbon::today())->copy()->startOfDay();

        $loan->unsetRelation('schedule');
        $loan->loadMissing(['product']);
        $loan->load(['schedule.repayments']);

        $penalty = $this->resolvePenaltyForLoan($loan);
        if (!$penalty) {
            return 0.0;
        }

        if (!$penalty->penalty_receivables_account_id || !$penalty->penalty_income_account_id) {
            Log::warning("Loan {$loan->loanNo}: Penalty #{$penalty->id} missing GL accounts — skipping catch-up");

            return 0.0;
        }

        $eligibleSchedules = $loan->schedule->filter(function (LoanSchedule $schedule) use ($untilDate) {
            if (in_array($schedule->status, ['restructured', 'paid', 'cancelled'], true)) {
                return false;
            }

            return Carbon::parse($schedule->due_date)->startOfDay()->lt($untilDate);
        });

        if ($eligibleSchedules->isEmpty()) {
            return 0.0;
        }

        foreach ($eligibleSchedules as $schedule) {
            $accruedSum = (float) AccruedPenalty::where('loan_schedule_id', $schedule->id)
                ->whereNull('reversed_at')
                ->sum('penalty_amount');

            if ($accruedSum <= 0 && (float) ($schedule->penalty_amount ?? 0) > 0) {
                $schedule->update(['penalty_amount' => 0]);
            }
        }

        $startDate = $eligibleSchedules
            ->map(function (LoanSchedule $schedule) use ($loan) {
                return $this->getGraceEndDate($loan, $schedule)->copy()->addDay();
            })
            ->min();

        if (!$startDate || $startDate->gt($untilDate)) {
            return 0.0;
        }

        $totalAccrued = 0.0;
        $current = $startDate->copy();

        while ($current->lte($untilDate)) {
            $dayService = static::forDate($current->toDateString());

            foreach ($eligibleSchedules as $schedule) {
                $calculation = $dayService->shouldAccrueSchedule($loan, $schedule, $penalty);
                if (!$calculation) {
                    continue;
                }

                try {
                    $dayService->postAccrual($loan, $schedule, $penalty, $calculation);
                    $totalAccrued += $calculation['penalty_amount'];
                } catch (\Throwable $e) {
                    Log::error("Penalty catch-up failed for loan {$loan->loanNo}, schedule {$schedule->id} on {$current->toDateString()}: {$e->getMessage()}");
                    throw $e;
                }
            }

            $current->addDay();
        }

        if ($totalAccrued > 0) {
            Log::info("Penalty catch-up for past loan {$loan->loanNo}: TZS " . number_format($totalAccrued, 2));
        }

        foreach ($eligibleSchedules as $schedule) {
            $accruedSum = (float) AccruedPenalty::where('loan_schedule_id', $schedule->id)
                ->whereNull('reversed_at')
                ->sum('penalty_amount');

            if (abs((float) $schedule->fresh()->penalty_amount - $accruedSum) > 0.01) {
                $schedule->update(['penalty_amount' => round($accruedSum, 2)]);
            }
        }

        return round($totalAccrued, 2);
    }

    /**
     * Resolve active penalty linked to the loan product (first active penalty_id).
     */
    public function resolvePenaltyForLoan(Loan $loan): ?Penalty
    {
        $product = $loan->product;
        if (!$product) {
            return null;
        }

        $ids = $product->penalty_ids;
        if (empty($ids)) {
            return null;
        }

        $ids = is_array($ids) ? $ids : [$ids];
        foreach ($ids as $id) {
            $penalty = Penalty::where('id', $id)->where('status', 'active')->first();
            if ($penalty) {
                try {
                    PenaltyConfiguration::assertAccrualReady($penalty);
                } catch (\InvalidArgumentException $e) {
                    Log::warning("Loan {$loan->loanNo}: " . $e->getMessage());

                    return null;
                }

                return $penalty;
            }
        }

        return null;
    }

    public function getGraceEndDate(Loan $loan, LoanSchedule $schedule): Carbon
    {
        if ($schedule->end_grace_date) {
            return Carbon::parse($schedule->end_grace_date)->startOfDay();
        }

        $graceDays = (int) ($loan->product->grace_period ?? 0);

        return Carbon::parse($schedule->due_date)->startOfDay()->addDays($graceDays);
    }

    public function isWithinGracePeriod(Loan $loan, LoanSchedule $schedule): bool
    {
        return $this->accrualDate->lte($this->getGraceEndDate($loan, $schedule));
    }

    public function daysOverdueAfterGrace(Loan $loan, LoanSchedule $schedule): int
    {
        $graceEnd = $this->getGraceEndDate($loan, $schedule);
        if ($this->accrualDate->lte($graceEnd)) {
            return 0;
        }

        return (int) $graceEnd->diffInDays($this->accrualDate, false);
    }

    public function scheduleInterestDue(LoanSchedule $schedule): float
    {
        $accrued = (float) ($schedule->accrued_interest ?? 0);
        $scheduled = (float) ($schedule->interest ?? 0);

        return max($accrued, $scheduled);
    }

    public function calculatePenaltyBase(Loan $loan, LoanSchedule $schedule, string $deductionType): float
    {
        if (!$schedule->relationLoaded('repayments')) {
            $schedule->load('repayments');
        }

        $paidPrincipal = (float) $schedule->repayments->sum('principal');
        $paidInterest = (float) $schedule->repayments->sum('interest');

        $unpaidPrincipal = max(0, (float) ($schedule->principal ?? 0) - $paidPrincipal);
        $interestAmount = $this->scheduleInterestDue($schedule);
        $unpaidInterest = max(0, $interestAmount - $paidInterest);

        return match ($deductionType) {
            'over_due_principal_amount' => $unpaidPrincipal,
            'over_due_interest_amount' => $unpaidInterest,
            'over_due_principal_and_interest' => $unpaidPrincipal + $unpaidInterest,
            'total_principal_amount_released' => (float) $loan->amount,
            default => $unpaidPrincipal + $unpaidInterest,
        };
    }

    public function isScheduleFullyPaid(LoanSchedule $schedule): bool
    {
        if (!$schedule->relationLoaded('repayments')) {
            $schedule->load('repayments');
        }

        $paidAmount = (float) $schedule->repayments->sum(function ($rep) {
            return ($rep->principal ?? 0) + ($rep->interest ?? 0) + ($rep->fee_amount ?? 0) + ($rep->penalt_amount ?? 0);
        });

        $interestAmount = $this->scheduleInterestDue($schedule);
        $totalDue = (float) ($schedule->principal ?? 0) + $interestAmount + (float) ($schedule->fee_amount ?? 0) + (float) ($schedule->penalty_amount ?? 0);

        return $paidAmount >= $totalDue - 0.01;
    }

    public function hasAccrualForToday(LoanSchedule $schedule, string $chargeFrequency): bool
    {
        $query = AccruedPenalty::where('loan_schedule_id', $schedule->id)
            ->whereNull('reversed_at');

        if ($chargeFrequency === 'daily') {
            $query->whereDate('accrual_date', $this->accrualDate);
        }

        return $query->exists();
    }

    public function calculatePenaltyAmount(
        float $baseAmount,
        Penalty $penalty,
        int $daysOverdue
    ): float {
        if ($baseAmount <= 0) {
            return 0;
        }

        $penaltyType = $penalty->penalty_type ?? 'percentage';
        $penaltyRate = (float) ($penalty->amount ?? 0);
        $chargeFrequency = $penalty->charge_frequency ?? 'daily';
        $frequencyCycle = $penalty->frequency_cycle ?? 'monthly';

        if ($penaltyRate <= 0) {
            return 0;
        }

        if ($chargeFrequency === 'daily') {
            if ($penaltyType === 'percentage') {
                $dailyRate = $this->convertRateToDaily($penaltyRate, $frequencyCycle);

                return round($baseAmount * $dailyRate / 100, 2);
            }

            return round($this->convertFixedAmountToDaily($penaltyRate, $frequencyCycle), 2);
        }

        if ($penaltyType === 'percentage') {
            return round($baseAmount * $penaltyRate / 100, 2);
        }

        return round($penaltyRate, 2);
    }

    /**
     * Whether this schedule should receive a new accrual today.
     */
    public function shouldAccrueSchedule(Loan $loan, LoanSchedule $schedule, Penalty $penalty): ?array
    {
        if (in_array($schedule->status, ['restructured', 'paid', 'cancelled'], true)) {
            return null;
        }

        if (Carbon::parse($schedule->due_date)->startOfDay()->gte($this->accrualDate)) {
            return null;
        }

        if ($this->isWithinGracePeriod($loan, $schedule)) {
            return null;
        }

        $chargeFrequency = $penalty->charge_frequency ?? 'daily';
        $deductionType = $penalty->deduction_type ?? 'over_due_principal_and_interest';

        if ($this->hasAccrualForToday($schedule, $chargeFrequency)) {
            return null;
        }

        if ($this->isScheduleFullyPaid($schedule)) {
            return null;
        }

        $daysOverdue = $this->daysOverdueAfterGrace($loan, $schedule);

        if ($chargeFrequency === 'daily' && $penalty->penalty_limit_days !== null) {
            if ($daysOverdue >= (int) $penalty->penalty_limit_days) {
                return null;
            }
        }

        $baseAmount = $this->calculatePenaltyBase($loan, $schedule, $deductionType);
        if ($baseAmount <= 0) {
            return null;
        }

        $penaltyAmount = $this->calculatePenaltyAmount($baseAmount, $penalty, $daysOverdue);
        if ($penaltyAmount <= 0) {
            return null;
        }

        return [
            'base_amount' => $baseAmount,
            'penalty_amount' => $penaltyAmount,
            'days_overdue' => $daysOverdue,
            'deduction_type' => $deductionType,
            'charge_frequency' => $chargeFrequency,
        ];
    }

    /**
     * Post penalty accrual (GL + journal + accrued_penalties + schedule.penalty_amount).
     */
    public function postAccrual(Loan $loan, LoanSchedule $schedule, Penalty $penalty, array $calculation): AccruedPenalty
    {
        $penaltyAmount = (float) $calculation['penalty_amount'];
        $receivableId = (int) $penalty->penalty_receivables_account_id;
        $incomeId = (int) $penalty->penalty_income_account_id;

        if (!$receivableId || !$incomeId) {
            throw new \RuntimeException("Penalty #{$penalty->id} is missing GL accounts.");
        }

        return DB::transaction(function () use ($loan, $schedule, $penalty, $calculation, $penaltyAmount, $receivableId, $incomeId) {
            $journal = Journal::create([
                'date' => $this->accrualDate,
                'reference' => 'PEN-' . $loan->loanNo . '-' . $schedule->id . '-' . $this->accrualDate->format('Ymd'),
                'reference_type' => 'Penalty Accrual',
                'customer_id' => $loan->customer_id,
                'description' => "Penalty accrual for overdue schedule #{$schedule->id} - Loan {$loan->loanNo}",
                'branch_id' => $loan->branch_id,
                'user_id' => 1,
                'approved' => true,
                'approved_by' => 1,
                'approved_at' => now(),
            ]);

            JournalItem::create([
                'journal_id' => $journal->id,
                'chart_account_id' => $receivableId,
                'amount' => $penaltyAmount,
                'description' => "Penalty receivable for loan {$loan->loanNo}",
                'nature' => 'debit',
            ]);

            JournalItem::create([
                'journal_id' => $journal->id,
                'chart_account_id' => $incomeId,
                'amount' => $penaltyAmount,
                'description' => "Penalty income for loan {$loan->loanNo}",
                'nature' => 'credit',
            ]);

            $accrued = AccruedPenalty::create([
                'loan_id' => $loan->id,
                'loan_schedule_id' => $schedule->id,
                'customer_id' => $loan->customer_id,
                'branch_id' => $loan->branch_id,
                'penalty_amount' => $penaltyAmount,
                'accrual_date' => $this->accrualDate,
                'penalty_type' => $penalty->penalty_type,
                'penalty_rate' => $penalty->amount,
                'calculation_basis' => $calculation['deduction_type'],
                'days_overdue' => $calculation['days_overdue'],
                'journal_id' => $journal->id,
                'posted_to_gl' => true,
                'description' => "Penalty accrual for overdue schedule #{$schedule->id} - Loan {$loan->loanNo}",
                'user_id' => 1,
            ]);

            foreach ([['debit', $receivableId], ['credit', $incomeId]] as [$nature, $accountId]) {
                GlTransaction::create([
                    'chart_account_id' => $accountId,
                    'customer_id' => $loan->customer_id,
                    'amount' => $penaltyAmount,
                    'nature' => $nature,
                    'transaction_id' => $accrued->id,
                    'transaction_type' => 'Accrued Penalty',
                    'date' => $this->accrualDate,
                    'description' => "Penalty accrual for loan {$loan->loanNo}, schedule {$schedule->id}",
                    'branch_id' => $loan->branch_id,
                    'user_id' => 1,
                ]);
            }

            $schedule->increment('penalty_amount', $penaltyAmount);

            return $accrued;
        });
    }

    /**
     * Reverse all non-reversed accruals for a schedule and reduce schedule.penalty_amount.
     */
    public function reverseScheduleAccruals(LoanSchedule $schedule, ?string $reason = null): float
    {
        $reversedTotal = 0.0;

        $rows = AccruedPenalty::where('loan_schedule_id', $schedule->id)
            ->whereNull('reversed_at')
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $accrued) {
            $reversedTotal += $this->reverseAccrualRow($accrued, $reason);
        }

        if ($reversedTotal > 0) {
            $schedule->refresh();
            $schedule->update([
                'penalty_amount' => max(0, round((float) $schedule->penalty_amount - $reversedTotal, 2)),
            ]);
        }

        return $reversedTotal;
    }

    public function reverseAccrualRow(AccruedPenalty $accrued, ?string $reason = null): float
    {
        $amount = (float) $accrued->penalty_amount;
        if ($amount <= 0) {
            $accrued->update(['reversed_at' => now()]);

            return 0;
        }

        $originals = GlTransaction::where('transaction_id', $accrued->id)
            ->where('transaction_type', 'Accrued Penalty')
            ->get();

        foreach ($originals as $gl) {
            GlTransaction::create([
                'chart_account_id' => $gl->chart_account_id,
                'customer_id' => $gl->customer_id,
                'supplier_id' => $gl->supplier_id,
                'amount' => $gl->amount,
                'nature' => $gl->nature === 'debit' ? 'credit' : 'debit',
                'transaction_id' => $accrued->id,
                'transaction_type' => 'Accrued Penalty Reversal',
                'date' => $this->accrualDate,
                'description' => trim(($gl->description ?? '') . ' (Reversal' . ($reason ? ": {$reason}" : '') . ')'),
                'branch_id' => $gl->branch_id,
                'user_id' => 1,
            ]);
        }

        if ($accrued->journal_id) {
            $this->reverseJournal($accrued, $reason);
        }

        $accrued->update(['reversed_at' => now()]);

        return $amount;
    }

    protected function reverseJournal(AccruedPenalty $accrued, ?string $reason): void
    {
        $items = JournalItem::where('journal_id', $accrued->journal_id)->get();
        if ($items->isEmpty()) {
            return;
        }

        $reversalJournal = Journal::create([
            'date' => $this->accrualDate,
            'reference' => 'PEN-REV-' . $accrued->id,
            'reference_type' => 'Penalty Accrual Reversal',
            'customer_id' => $accrued->customer_id,
            'description' => 'Reversal of penalty accrual #' . $accrued->id . ($reason ? " — {$reason}" : ''),
            'branch_id' => $accrued->branch_id,
            'user_id' => 1,
            'approved' => true,
            'approved_by' => 1,
            'approved_at' => now(),
        ]);

        foreach ($items as $item) {
            JournalItem::create([
                'journal_id' => $reversalJournal->id,
                'chart_account_id' => $item->chart_account_id,
                'amount' => $item->amount,
                'description' => ($item->description ?? '') . ' (Reversal)',
                'nature' => $item->nature === 'debit' ? 'credit' : 'debit',
            ]);
        }
    }

    protected function convertRateToDaily(float $rate, string $frequencyCycle): float
    {
        return match (strtolower(trim($frequencyCycle))) {
            'daily' => $rate,
            'weekly' => $rate / 7,
            'monthly' => $rate / 30,
            'quarterly' => $rate / 90,
            'semi_annually' => $rate / 180,
            'annually', 'yearly' => $rate / 365,
            default => $rate / 30,
        };
    }

    protected function convertFixedAmountToDaily(float $amount, string $frequencyCycle): float
    {
        return match (strtolower(trim($frequencyCycle))) {
            'daily' => $amount,
            'weekly' => $amount / 7,
            'monthly' => $amount / 30,
            'quarterly' => $amount / 90,
            'semi_annually' => $amount / 180,
            'annually', 'yearly' => $amount / 365,
            default => $amount / 30,
        };
    }
}
