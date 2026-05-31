<?php

namespace App\Console\Commands;

use App\Models\AccruedPenalty;
use App\Models\GlTransaction;
use App\Models\LoanSchedule;
use App\Models\Penalty;
use App\Services\PenaltyAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reverse penalty GL left on the receivable account after pardon / schedule cleared
 * (Loan Details shows 0 but dashboard & Customer Penalty List still show a balance).
 */
class CleanupOrphanPenaltyGlCommand extends Command
{
    protected $signature = 'accounting:cleanup-orphan-penalty-gl
                            {--dry-run : List fixes without writing}
                            {--loan= : Limit to one loan id}
                            {--customer= : Limit to one customer id}';

    protected $description = 'Reverse orphan penalty GL for pardoned schedules (schedule penalty 0, GL balance remains)';

    /** @var string[] */
    private const LEGACY_PENALTY_TYPES = ['Penalty', 'penalty', 'Loan Penalty'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $customerId = $this->option('customer') ? (int) $this->option('customer') : null;

        $receivableId = (int) Penalty::query()
            ->where('status', 'active')
            ->whereNotNull('penalty_receivables_account_id')
            ->value('penalty_receivables_account_id');

        if (!$receivableId) {
            $this->error('No active penalty receivables account configured.');

            return self::FAILURE;
        }

        $service = PenaltyAccrualService::forDate(Carbon::today()->toDateString());
        $fixedAccrued = 0;
        $fixedGl = 0;
        $fixedLegacy = 0;

        $scheduleIds = $this->pardonedScheduleIds($receivableId, $loanId, $customerId);

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Checking ' . count($scheduleIds) . ' pardoned schedule(s)...');

        foreach ($scheduleIds as $scheduleId) {
            $schedule = LoanSchedule::with('loan')->find($scheduleId);
            if (!$schedule || !$schedule->loan || $schedule->loan->status !== 'active') {
                continue;
            }

            $loan = $schedule->loan;

            $unreversed = AccruedPenalty::where('loan_schedule_id', $schedule->id)
                ->whereNull('reversed_at')
                ->get();

            foreach ($unreversed as $accrued) {
                $this->line(sprintf(
                    '  REVERSE-ACCRUED loan %s sched#%d accrued#%d amount=%.2f',
                    $loan->loanNo,
                    $schedule->id,
                    $accrued->id,
                    $accrued->penalty_amount
                ));
                if (!$dryRun) {
                    $service->reverseAccrualRow($accrued, 'Pardon / orphan penalty GL cleanup');
                }
                $fixedAccrued++;
            }

            $reversed = AccruedPenalty::where('loan_schedule_id', $schedule->id)
                ->whereNotNull('reversed_at')
                ->get();

            foreach ($reversed as $accrued) {
                $net = $this->netReceivableGlForAccrued($accrued->id, $receivableId);
                if ($net <= 0.02) {
                    continue;
                }

                $this->line(sprintf(
                    '  GL-REVERSAL loan %s sched#%d accrued#%d (reversed_at set, GL net=%.2f)',
                    $loan->loanNo,
                    $schedule->id,
                    $accrued->id,
                    $net
                ));
                if (!$dryRun) {
                    $this->postAccruedPenaltyGlReversal($accrued, 'Pardon / missing GL reversal');
                }
                $fixedGl++;
            }

            $legacyNet = $this->netLegacyPenaltyGlOnSchedule($schedule->id, $receivableId);
            if ($legacyNet > 0.02) {
                $this->line(sprintf(
                    '  LEGACY-GL loan %s sched#%d net receivable=%.2f',
                    $loan->loanNo,
                    $schedule->id,
                    $legacyNet
                ));
                if (!$dryRun) {
                    $fixedLegacy += $this->reverseLegacyPenaltyGl($schedule, $legacyNet, $receivableId);
                } else {
                    $fixedLegacy++;
                }
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would fix' : 'Fixed') . " {$fixedAccrued} active accrual(s), {$fixedGl} missing GL reversal(s), {$fixedLegacy} legacy GL schedule(s).");
        $this->line('Refresh Dashboard and Customer Penalty List.');

        return self::SUCCESS;
    }

    /**
     * Schedules with no schedule penalty but net GL on receivable (pardoned / stale).
     *
     * @return int[]
     */
    protected function pardonedScheduleIds(int $receivableId, ?int $loanId, ?int $customerId): array
    {
        $fromSchedules = LoanSchedule::query()
            ->where('penalty_amount', '<=', 0)
            ->when($loanId, fn ($q) => $q->where('loan_id', $loanId))
            ->when($customerId, fn ($q) => $q->whereHas('loan', fn ($lq) => $lq->where('customer_id', $customerId)))
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->pluck('id');

        $fromAccrued = AccruedPenalty::query()
            ->when($loanId, fn ($q) => $q->where('loan_id', $loanId))
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->whereHas('loanSchedule', fn ($q) => $q->where('penalty_amount', '<=', 0))
            ->distinct()
            ->pluck('loan_schedule_id');

        $candidateIds = $fromSchedules->merge($fromAccrued)->unique();

        return $candidateIds->filter(function ($scheduleId) use ($receivableId) {
            $schedule = LoanSchedule::find($scheduleId);
            if (!$schedule) {
                return false;
            }

            $activeAccrued = (float) AccruedPenalty::where('loan_schedule_id', $scheduleId)
                ->whereNull('reversed_at')
                ->sum('penalty_amount');

            if ($activeAccrued > 0.02) {
                return true;
            }

            $legacyNet = $this->netLegacyPenaltyGlOnSchedule($scheduleId, $receivableId);
            if ($legacyNet > 0.02) {
                return true;
            }

            return AccruedPenalty::where('loan_schedule_id', $scheduleId)
                ->whereNotNull('reversed_at')
                ->get()
                ->contains(fn (AccruedPenalty $a) => $this->netReceivableGlForAccrued($a->id, $receivableId) > 0.02);
        })->values()->all();
    }

    protected function netReceivableGlForAccrued(int $accruedId, int $receivableId): float
    {
        $rows = GlTransaction::query()
            ->where('chart_account_id', $receivableId)
            ->where('transaction_id', $accruedId)
            ->whereIn('transaction_type', ['Accrued Penalty', 'Accrued Penalty Reversal'])
            ->get();

        return $this->netDebitMinusCredit($rows);
    }

    protected function netLegacyPenaltyGlOnSchedule(int $scheduleId, int $receivableId): float
    {
        $rows = GlTransaction::query()
            ->where('chart_account_id', $receivableId)
            ->where('transaction_id', $scheduleId)
            ->where(function ($q) {
                $q->whereIn('transaction_type', self::LEGACY_PENALTY_TYPES)
                    ->orWhere('transaction_type', 'Penalty Reversal');
            })
            ->get();

        return $this->netDebitMinusCredit($rows);
    }

    protected function netDebitMinusCredit(Collection $rows): float
    {
        $debit = (float) $rows->where('nature', 'debit')->sum('amount');
        $credit = (float) $rows->where('nature', 'credit')->sum('amount');

        return round($debit - $credit, 2);
    }

    protected function postAccruedPenaltyGlReversal(AccruedPenalty $accrued, ?string $reason): void
    {
        $originals = GlTransaction::where('transaction_id', $accrued->id)
            ->where('transaction_type', 'Accrued Penalty')
            ->get();

        foreach ($originals as $gl) {
            $hasReversal = GlTransaction::where('transaction_id', $accrued->id)
                ->where('transaction_type', 'Accrued Penalty Reversal')
                ->where('chart_account_id', $gl->chart_account_id)
                ->where('amount', $gl->amount)
                ->where('nature', $gl->nature === 'debit' ? 'credit' : 'debit')
                ->exists();

            if ($hasReversal) {
                continue;
            }

            GlTransaction::create([
                'chart_account_id' => $gl->chart_account_id,
                'customer_id' => $gl->customer_id,
                'supplier_id' => $gl->supplier_id,
                'amount' => $gl->amount,
                'nature' => $gl->nature === 'debit' ? 'credit' : 'debit',
                'transaction_id' => $accrued->id,
                'transaction_type' => 'Accrued Penalty Reversal',
                'date' => Carbon::today(),
                'description' => trim(($gl->description ?? '') . ' (Reversal' . ($reason ? ": {$reason}" : '') . ')'),
                'branch_id' => $gl->branch_id,
                'user_id' => 1,
            ]);
        }

    }

    protected function reverseLegacyPenaltyGl(LoanSchedule $schedule, float $amountToReverse, int $receivableId): int
    {
        $remaining = $amountToReverse;
        $count = 0;

        $debits = GlTransaction::where('transaction_id', $schedule->id)
            ->where('chart_account_id', $receivableId)
            ->whereIn('transaction_type', self::LEGACY_PENALTY_TYPES)
            ->where('nature', 'debit')
            ->orderByDesc('id')
            ->get();

        foreach ($debits as $debit) {
            if ($remaining <= 0) {
                break;
            }

            $rowAmount = (float) $debit->amount;
            if ($rowAmount <= 0 || $rowAmount > $remaining + 0.01) {
                continue;
            }

            $alreadyReversed = GlTransaction::where('transaction_id', $schedule->id)
                ->where('transaction_type', 'Penalty Reversal')
                ->where('nature', 'credit')
                ->where('amount', $rowAmount)
                ->where('chart_account_id', $receivableId)
                ->exists();

            if ($alreadyReversed) {
                $remaining -= $rowAmount;

                continue;
            }

            GlTransaction::create([
                'chart_account_id' => $debit->chart_account_id,
                'customer_id' => $debit->customer_id,
                'amount' => $debit->amount,
                'nature' => 'credit',
                'transaction_id' => $schedule->id,
                'transaction_type' => 'Penalty Reversal',
                'date' => Carbon::today(),
                'description' => ($debit->description ?? 'Penalty reversal') . ' (Pardon / orphan cleanup)',
                'branch_id' => $debit->branch_id,
                'user_id' => 1,
            ]);

            $remaining -= $rowAmount;
            $count++;
        }

        return $count;
    }
}
