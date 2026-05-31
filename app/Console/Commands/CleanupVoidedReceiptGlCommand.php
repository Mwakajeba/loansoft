<?php

namespace App\Console\Commands;

use App\Models\GlTransaction;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Support\Accounting\GlTransactionReportFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupVoidedReceiptGlCommand extends Command
{
    protected $signature = 'accounting:cleanup-voided-receipt-gl
                            {--dry-run : List rows that would be deleted without deleting}
                            {--company= : Limit to receipts in branches of this company ID}';

    protected $description = 'Remove GL entries for soft-deleted/reversed receipts and soft-deleted repayments from cash book / reports';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $receiptGlQuery = GlTransaction::query()
            ->whereIn('transaction_type', GlTransactionReportFilter::RECEIPT_TRANSACTION_TYPES)
            ->where(function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('receipts')
                        ->whereColumn('receipts.id', 'gl_transactions.transaction_id')
                        ->whereNull('receipts.deleted_at');
                });
            });

        $repaymentGlQuery = GlTransaction::query()
            ->whereIn('transaction_type', GlTransactionReportFilter::REPAYMENT_TRANSACTION_TYPES)
            ->where(function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('repayments')
                        ->whereColumn('repayments.id', 'gl_transactions.transaction_id')
                        ->whereNull('repayments.deleted_at');
                });
            });

        if ($companyId = $this->option('company')) {
            $branchIds = DB::table('branches')->where('company_id', $companyId)->pluck('id');
            $receiptGlQuery->whereIn('branch_id', $branchIds);
            $repaymentGlQuery->whereIn('branch_id', $branchIds);
        }

        $receiptGlIds = (clone $receiptGlQuery)->pluck('id');
        $repaymentGlIds = (clone $repaymentGlQuery)->pluck('id');
        $allIds = $receiptGlIds->merge($repaymentGlIds)->unique()->values();

        $voidedReceiptCount = Receipt::onlyTrashed()->count();
        $voidedRepaymentCount = Repayment::onlyTrashed()->count();

        $this->info('Voided receipts (soft-deleted): ' . $voidedReceiptCount);
        $this->info('Voided repayments (soft-deleted): ' . $voidedRepaymentCount);
        $this->info('GL rows to remove — receipt/reversal: ' . $receiptGlIds->count());
        $this->info('GL rows to remove — repayment-linked: ' . $repaymentGlIds->count());
        $this->info('Total GL rows: ' . $allIds->count());

        if ($allIds->isEmpty()) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $sample = GlTransaction::whereIn('id', $allIds->take(20))->get(['id', 'transaction_type', 'transaction_id', 'amount', 'nature', 'date', 'description']);
            $this->table(
                ['id', 'type', 'txn_id', 'amount', 'nature', 'date', 'description'],
                $sample->map(fn ($r) => [
                    $r->id,
                    $r->transaction_type,
                    $r->transaction_id,
                    $r->amount,
                    $r->nature,
                    $r->date?->format('Y-m-d'),
                    \Illuminate\Support\Str::limit($r->description ?? '', 40),
                ])->all()
            );
            $this->warn('Dry run — no rows deleted. Run without --dry-run to delete.');

            return self::SUCCESS;
        }

        if (!$this->confirm('Permanently delete ' . $allIds->count() . ' GL transaction row(s)?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $deleted = GlTransaction::whereIn('id', $allIds)->delete();
        $this->info("Deleted {$deleted} GL transaction row(s).");

        return self::SUCCESS;
    }
}
