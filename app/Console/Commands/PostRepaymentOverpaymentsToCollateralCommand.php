<?php

namespace App\Console\Commands;

use App\Models\CashCollateral;
use App\Models\CashCollateralType;
use App\Models\Customer;
use App\Models\GlTransaction;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Repayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PostRepaymentOverpaymentsToCollateralCommand extends Command
{
    protected $signature = 'group-repayments:post-overpayments-to-collateral
                            {--dry-run : Preview overpayments without writing}
                            {--force : Apply without confirmation}
                            {--company= : Limit to company id}
                            {--branch= : Limit to branch id}
                            {--repayment= : Limit to one repayment id}';

    protected $description = 'Credit unbalanced group-repayment overpayments to customer cash collateral (production data fix)';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $repaymentId = $this->option('repayment') ? (int) $this->option('repayment') : null;

        $query = DB::table('gl_transactions as g')
            ->join('repayments as r', 'r.id', '=', 'g.transaction_id')
            ->join('loans as l', 'l.id', '=', 'r.loan_id')
            ->join('branches as b', 'b.id', '=', 'l.branch_id')
            ->where('g.transaction_type', 'Repayment')
            ->whereNull('r.deleted_at')
            ->when($companyId, fn ($q) => $q->where('b.company_id', $companyId))
            ->when($branchId, fn ($q) => $q->where('g.branch_id', $branchId))
            ->when($repaymentId, fn ($q) => $q->where('g.transaction_id', $repaymentId))
            ->select(
                'g.transaction_id',
                'r.customer_id',
                'r.loan_id',
                'l.loanNo',
                'b.company_id',
                DB::raw('SUM(CASE WHEN g.nature = "debit" THEN g.amount ELSE 0 END) as debit'),
                DB::raw('SUM(CASE WHEN g.nature = "credit" THEN g.amount ELSE 0 END) as credit')
            )
            ->groupBy('g.transaction_id', 'r.customer_id', 'r.loan_id', 'l.loanNo', 'b.company_id')
            ->havingRaw('SUM(CASE WHEN g.nature = "debit" THEN g.amount ELSE -g.amount END) > 0.009')
            ->orderBy('g.transaction_id');

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->info('No unbalanced repayment overpayments found.');

            return self::SUCCESS;
        }

        $customers = Customer::query()
            ->whereIn('id', $candidates->pluck('customer_id')->unique())
            ->pluck('name', 'id');

        $rows = [];
        $total = 0.0;
        foreach ($candidates as $row) {
            $overpayment = round((float) $row->debit - (float) $row->credit, 2);
            $total += $overpayment;
            $rows[] = [
                $row->transaction_id,
                $row->loanNo,
                $customers[$row->customer_id] ?? $row->customer_id,
                number_format((float) $row->debit, 2),
                number_format((float) $row->credit, 2),
                number_format($overpayment, 2),
            ];
        }

        $this->table(
            ['Repayment', 'Loan', 'Customer', 'Cash debit', 'Allocated credit', 'To collateral'],
            $rows
        );
        $this->line('Total to post to cash collateral: TZS '.number_format($total, 2));
        $this->comment('Double entry per overpayment: Dr Cash (already posted) / Cr Customer cash collateral liability.');

        if ($this->option('dry-run')) {
            $this->comment('Dry run only. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Post these overpayments to customer cash collateral?', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $errors = 0;
        foreach ($candidates as $candidate) {
            $overpayment = round((float) $candidate->debit - (float) $candidate->credit, 2);
            try {
                DB::transaction(function () use ($candidate, $overpayment) {
                    $this->postToCollateral((int) $candidate->transaction_id, $overpayment);
                });
                $this->info(sprintf(
                    'Repayment #%d (%s): TZS %s → cash collateral',
                    $candidate->transaction_id,
                    $candidate->loanNo,
                    number_format($overpayment, 2)
                ));
                $fixed++;
            } catch (\Throwable $exception) {
                $this->error("Repayment #{$candidate->transaction_id}: {$exception->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Posted', $fixed],
                ['Errors', $errors],
                ['Total candidates', $candidates->count()],
            ]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function postToCollateral(int $repaymentId, float $overpayment): void
    {
        if ($overpayment <= 0) {
            return;
        }

        $repayment = Repayment::with(['loan.product', 'loan', 'customer'])
            ->lockForUpdate()
            ->findOrFail($repaymentId);

        $glRows = GlTransaction::query()
            ->where('transaction_type', 'Repayment')
            ->where('transaction_id', $repaymentId)
            ->lockForUpdate()
            ->get();

        $debitRows = $glRows->where('nature', 'debit');
        if ($debitRows->count() !== 1) {
            throw new \RuntimeException('Expected exactly one cash debit GL row.');
        }

        $debit = $debitRows->first();
        $creditTotal = round((float) $glRows->where('nature', 'credit')->sum('amount'), 2);
        $liveOverpayment = round((float) $debit->amount - $creditTotal, 2);
        if ($liveOverpayment <= 0.009) {
            return;
        }
        $overpayment = $liveOverpayment;

        $receipt = $repayment->receipt_id ? Receipt::find($repayment->receipt_id) : null;
        $receipt ??= Receipt::query()
            ->where('reference_type', 'Repayment')
            ->where('reference', $repayment->id)
            ->first();

        if (! $receipt) {
            throw new \RuntimeException('Source receipt was not found.');
        }

        // Skip if this repayment already has a collateral credit for the same excess.
        $alreadyPosted = GlTransaction::query()
            ->where('transaction_type', 'receipt')
            ->where('transaction_id', $receipt->id)
            ->where('nature', 'credit')
            ->where('description', 'like', "Overpayment from repayment #{$repayment->id}%")
            ->exists();
        if ($alreadyPosted) {
            throw new \RuntimeException('Overpayment already posted to cash collateral for this receipt.');
        }

        $collateral = $this->resolveCollateral($repayment, $debit);
        $notes = "Overpayment from repayment #{$repayment->id} transferred to cash collateral";

        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'chart_account_id' => $collateral->type->chart_account_id,
            'amount' => $overpayment,
            'description' => $notes,
        ]);

        // Balancing credit only — cash debit already includes the overpayment.
        GlTransaction::create([
            'chart_account_id' => $collateral->type->chart_account_id,
            'customer_id' => $repayment->customer_id,
            'amount' => $overpayment,
            'nature' => 'credit',
            'transaction_id' => $receipt->id,
            'transaction_type' => 'receipt',
            'date' => $repayment->payment_date,
            'description' => $notes,
            'branch_id' => $debit->branch_id,
            'user_id' => $debit->user_id,
        ]);

        $collateral->increment('amount', $overpayment);
        Customer::where('id', $repayment->customer_id)->update(['has_cash_collateral' => true]);

        $allocated = round(
            (float) $repayment->principal
            + (float) $repayment->interest
            + (float) $repayment->fee_amount
            + (float) $repayment->penalt_amount,
            2
        );
        $repayment->update([
            'cash_deposit' => $allocated,
            'receipt_id' => $receipt->id,
        ]);
    }

    private function resolveCollateral(Repayment $repayment, GlTransaction $debit): CashCollateral
    {
        $repayment->loan->loadMissing('branch');
        $companyId = (int) ($repayment->loan->branch->company_id
            ?? DB::table('branches')->where('id', $debit->branch_id)->value('company_id')
            ?? 0);

        $collateral = CashCollateral::with('type')
            ->where('customer_id', $repayment->customer_id)
            ->when($companyId > 0, fn ($q) => $q->where('company_id', $companyId))
            ->lockForUpdate()
            ->first();

        if (! $collateral) {
            $typeName = $repayment->loan->product->cash_collateral_type ?? null;
            $type = null;
            if ($typeName) {
                $type = CashCollateralType::query()
                    ->where('name', $typeName)
                    ->where('is_active', true)
                    ->whereNotNull('chart_account_id')
                    ->first();
            }
            $type ??= CashCollateralType::query()
                ->where('is_active', true)
                ->whereNotNull('chart_account_id')
                ->first();

            if (! $type) {
                throw new \RuntimeException('No active cash collateral type with a chart account is configured.');
            }
            if ($companyId <= 0) {
                throw new \RuntimeException('Unable to resolve company for cash collateral.');
            }

            $collateral = CashCollateral::create([
                'customer_id' => $repayment->customer_id,
                'type_id' => $type->id,
                'branch_id' => $debit->branch_id,
                'company_id' => $companyId,
                'amount' => 0,
            ]);
            $collateral->setRelation('type', $type);
        }

        $collateral->loadMissing('type');
        if (! $collateral->type?->chart_account_id) {
            throw new \RuntimeException('Customer cash collateral has no linked chart account.');
        }

        return $collateral;
    }
}
