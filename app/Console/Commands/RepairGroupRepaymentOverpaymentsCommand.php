<?php

namespace App\Console\Commands;

use App\Models\CashCollateral;
use App\Models\CashCollateralType;
use App\Models\Fee;
use App\Models\GlTransaction;
use App\Models\LoanSchedule;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Repayment;
use App\Support\Loans\GroupRepaymentAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairGroupRepaymentOverpaymentsCommand extends Command
{
    protected $signature = 'group-repayments:repair-overpayments
                            {--dry-run : Show unbalanced repayments without changing data}
                            {--force : Apply without confirmation}';

    protected $description = 'Allocate legacy unbalanced group repayment overpayments to later schedules, then cash collateral';

    public function handle(): int
    {
        $candidates = DB::table('gl_transactions')
            ->where('transaction_type', 'Repayment')
            ->select(
                'transaction_id',
                DB::raw('SUM(CASE WHEN nature = "debit" THEN amount ELSE 0 END) as debit'),
                DB::raw('SUM(CASE WHEN nature = "credit" THEN amount ELSE 0 END) as credit')
            )
            ->groupBy('transaction_id')
            ->havingRaw('SUM(CASE WHEN nature = "debit" THEN amount ELSE -amount END) > 0.009')
            ->orderBy('transaction_id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No unbalanced group repayment overpayments found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Repayment', 'Cash debit', 'Allocated credit', 'Overpayment'],
            $candidates->map(fn ($row) => [
                $row->transaction_id,
                number_format((float) $row->debit, 2),
                number_format((float) $row->credit, 2),
                number_format((float) $row->debit - (float) $row->credit, 2),
            ])->all()
        );

        $total = $candidates->sum(fn ($row) => (float) $row->debit - (float) $row->credit);
        $this->line('Total overpayment: TZS '.number_format($total, 2));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Apply these allocations?', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($candidates as $candidate) {
            try {
                DB::transaction(fn () => $this->repair((int) $candidate->transaction_id));
                $this->info("Repaired repayment #{$candidate->transaction_id}.");
                $fixed++;
            } catch (\Throwable $exception) {
                $this->error("Repayment #{$candidate->transaction_id}: {$exception->getMessage()}");
            }
        }

        $this->line("Repaired {$fixed} of {$candidates->count()} repayment(s).");

        return $fixed === $candidates->count() ? self::SUCCESS : self::FAILURE;
    }

    private function repair(int $repaymentId): void
    {
        $repayment = Repayment::with(['loan.product', 'loan.branch', 'loan', 'schedule'])
            ->lockForUpdate()
            ->findOrFail($repaymentId);
        $glRows = GlTransaction::query()
            ->where('transaction_type', 'Repayment')
            ->where('transaction_id', $repaymentId)
            ->lockForUpdate()
            ->get();
        $debitRows = $glRows->where('nature', 'debit');

        if ($debitRows->count() !== 1) {
            throw new \RuntimeException('Expected exactly one cash debit row.');
        }

        $debit = $debitRows->first();
        $debitTotal = (float) $debit->amount;
        $creditTotal = (float) $glRows->where('nature', 'credit')->sum('amount');
        $overflow = round($debitTotal - $creditTotal, 2);
        if ($overflow <= 0) {
            return;
        }

        $receipt = $repayment->receipt_id ? Receipt::find($repayment->receipt_id) : null;
        $receipt ??= Receipt::query()
            ->where('reference_type', 'Repayment')
            ->where('reference', $repayment->id)
            ->first();
        if (! $receipt) {
            throw new \RuntimeException('Source receipt was not found.');
        }

        $laterSchedules = LoanSchedule::query()
            ->where('loan_id', $repayment->loan_id)
            ->where(function ($query) use ($repayment) {
                $query->whereDate('due_date', '>', $repayment->schedule->due_date)
                    ->orWhere(function ($sameDate) use ($repayment) {
                        $sameDate->whereDate('due_date', $repayment->schedule->due_date)
                            ->where('id', '>', $repayment->loan_schedule_id);
                    });
            })
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $laterSchedules->load('repayments');

        $product = $repayment->loan->product;
        $result = GroupRepaymentAllocator::allocate(
            $laterSchedules,
            $overflow,
            $this->paymentOrder($product->repayment_order)
        );

        $debit->update(['amount' => $creditTotal]);
        $repayment->update([
            'cash_deposit' => $creditTotal,
            'receipt_id' => $receipt->id,
        ]);

        foreach ($result['allocations'] as $allocation) {
            $schedule = $laterSchedules->firstWhere('id', $allocation['schedule_id']);
            $amount = (float) $allocation['total'];
            $notes = "Advance repayment allocation repaired from repayment #{$repayment->id}";
            $newRepayment = Repayment::create([
                'customer_id' => $repayment->customer_id,
                'loan_id' => $repayment->loan_id,
                'loan_schedule_id' => $schedule->id,
                'receipt_id' => $receipt->id,
                'principal' => $allocation['principal'],
                'interest' => $allocation['interest'],
                'penalt_amount' => $allocation['penalt_amount'],
                'bank_account_id' => $repayment->bank_account_id,
                'fee_amount' => $allocation['fee_amount'],
                'cash_deposit' => $amount,
                'due_date' => $schedule->due_date,
                'payment_date' => $repayment->payment_date,
            ]);

            $accounts = [
                'principal' => (int) $product->principal_receivable_account_id,
                'interest' => (int) $product->interest_revenue_account_id,
                'fee_amount' => $this->feeAccountId($product),
                'penalt_amount' => $product->penalty?->penalty_receivables_account_id,
            ];

            GlTransaction::create([
                'chart_account_id' => $debit->chart_account_id,
                'customer_id' => $repayment->customer_id,
                'amount' => $amount,
                'nature' => 'debit',
                'transaction_id' => $newRepayment->id,
                'transaction_type' => 'Repayment',
                'date' => $repayment->payment_date,
                'description' => $notes,
                'branch_id' => $debit->branch_id,
                'user_id' => $debit->user_id,
            ]);

            foreach ($accounts as $component => $accountId) {
                $componentAmount = (float) $allocation[$component];
                if ($componentAmount <= 0) {
                    continue;
                }
                if (! $accountId) {
                    throw new \RuntimeException("No chart account is configured for {$component}.");
                }

                ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'chart_account_id' => $accountId,
                    'amount' => $componentAmount,
                    'description' => $notes,
                ]);
                GlTransaction::create([
                    'chart_account_id' => $accountId,
                    'customer_id' => $repayment->customer_id,
                    'amount' => $componentAmount,
                    'nature' => 'credit',
                    'transaction_id' => $newRepayment->id,
                    'transaction_type' => 'Repayment',
                    'date' => $repayment->payment_date,
                    'description' => $notes,
                    'branch_id' => $debit->branch_id,
                    'user_id' => $debit->user_id,
                ]);
            }
        }

        $collateralAmount = (float) $result['cash_collateral'];
        if ($collateralAmount > 0) {
            $this->postCollateral($repayment, $receipt, $debit, $collateralAmount);
        }
    }

    private function postCollateral(
        Repayment $repayment,
        Receipt $receipt,
        GlTransaction $sourceDebit,
        float $amount
    ): void {
        $companyId = (int) (
            $repayment->loan->branch?->company_id
            ?? DB::table('branches')->where('id', $sourceDebit->branch_id)->value('company_id')
            ?? 0
        );
        if ($companyId <= 0) {
            throw new \RuntimeException('Unable to resolve company for cash collateral.');
        }

        $collateral = CashCollateral::with('type')
            ->where('customer_id', $repayment->customer_id)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();
        if (! $collateral) {
            $type = CashCollateralType::where('is_active', true)
                ->whereNotNull('chart_account_id')
                ->firstOrFail();
            $collateral = CashCollateral::create([
                'customer_id' => $repayment->customer_id,
                'type_id' => $type->id,
                'branch_id' => $sourceDebit->branch_id,
                'company_id' => $companyId,
                'amount' => 0,
            ]);
            $collateral->setRelation('type', $type);
        }
        if (! $collateral->type?->chart_account_id) {
            throw new \RuntimeException('Cash collateral chart account is not configured.');
        }

        $notes = "Overpayment from repayment #{$repayment->id} transferred to cash collateral";
        $collateral->increment('amount', $amount);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'chart_account_id' => $collateral->type->chart_account_id,
            'amount' => $amount,
            'description' => $notes,
        ]);

        foreach ([
            ['account' => $sourceDebit->chart_account_id, 'nature' => 'debit'],
            ['account' => $collateral->type->chart_account_id, 'nature' => 'credit'],
        ] as $entry) {
            GlTransaction::create([
                'chart_account_id' => $entry['account'],
                'customer_id' => $repayment->customer_id,
                'amount' => $amount,
                'nature' => $entry['nature'],
                'transaction_id' => $receipt->id,
                'transaction_type' => 'receipt',
                'date' => $repayment->payment_date,
                'description' => $notes,
                'branch_id' => $sourceDebit->branch_id,
                'user_id' => $sourceDebit->user_id,
            ]);
        }
    }

    private function paymentOrder(mixed $order): array
    {
        if (is_array($order)) {
            return $order;
        }

        $parsed = array_values(array_filter(array_map('trim', explode(',', (string) $order))));

        return $parsed ?: ['penalties', 'fees', 'interest', 'principal'];
    }

    private function feeAccountId(object $product): ?int
    {
        $feeIds = is_array($product->fees_ids)
            ? $product->fees_ids
            : json_decode((string) $product->fees_ids, true);

        foreach (is_array($feeIds) ? $feeIds : [] as $feeId) {
            $accountId = Fee::find($feeId)?->chart_account_id;
            if ($accountId) {
                return (int) $accountId;
            }
        }

        return null;
    }
}
