<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Services\LoanDisbursementGlService;
use Illuminate\Console\Command;

class DeductReleaseFeesCommand extends Command
{
    protected $signature = 'loans:deduct-release-fees
                            {--dry-run : Show what would be fixed without writing GL}
                            {--loan= : Limit to one loan id}
                            {--loan-no= : Limit to one loan number}
                            {--force : Run without confirmation}';

    protected $description = 'Deduct missing release-date fees from cash/bank GL and credit fee income (P&L). Example: loan 100,000 fee 10% → Dr receivable 100,000 / Cr cash 90,000 / Cr fee 10,000';

    public function handle(LoanDisbursementGlService $glService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $loanId = $this->option('loan') ? (int) $this->option('loan') : null;
        $loanNo = $this->option('loan-no') ? trim((string) $this->option('loan-no')) : null;

        $query = Loan::query()
            ->with(['product.principalReceivableAccount', 'bankAccount', 'customer'])
            ->whereIn('status', ['active', 'disbursed', 'completed', 'defaulted', 'written_off']);

        if ($loanId) {
            $query->where('id', $loanId);
        }
        if ($loanNo) {
            $query->where('loanNo', $loanNo);
        }

        $loans = $query->orderBy('id')->get();
        if ($loans->isEmpty()) {
            $this->warn('No matching loans found.');

            return self::SUCCESS;
        }

        $candidates = [];
        foreach ($loans as $loan) {
            $breakdown = $glService->releaseFeeBreakdown($loan);
            $feeTotal = round((float) $breakdown->sum('amount'), 2);
            if ($feeTotal <= 0.009) {
                continue;
            }

            $preview = $glService->applyMissingReleaseFeeDeductionsPreview($loan);
            if ($preview['status'] !== 'needs_fix') {
                continue;
            }

            $candidates[] = [
                'loan' => $loan,
                'preview' => $preview,
                'fees' => $feeTotal,
                'breakdown' => $breakdown,
            ];
        }

        if (empty($candidates)) {
            $this->info('No loans need release-fee deduction (already posted or no release fees).');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Found '.count($candidates).' loan(s) needing release fee deduction.');
        $this->newLine();

        $rows = [];
        foreach ($candidates as $item) {
            /** @var Loan $loan */
            $loan = $item['loan'];
            $preview = $item['preview'];
            $feeNames = $item['breakdown']->map(fn ($r) => $r->fee->name.'='.number_format($r->amount, 2))->implode(', ');
            $rows[] = [
                $loan->id,
                $loan->loanNo,
                $loan->customer->name ?? '',
                number_format((float) $loan->amount, 2),
                number_format($item['fees'], 2),
                number_format((float) ($preview['cash'] ?? 0), 2),
                $preview['status'],
                $feeNames,
            ];
        }

        $this->table(
            ['ID', 'Loan No', 'Customer', 'Principal', 'Fees', 'Cash GL now', 'Status', 'Fee breakdown'],
            $rows
        );

        if ($dryRun) {
            $this->comment('Run without --dry-run to post: Cr cash reduced to P−F, Cr fee income F (shows on P&L).');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Apply release fee deductions and update GL for the loans above?', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $userId = (int) (auth()->id() ?? 1);
        $fixed = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($candidates as $item) {
            /** @var Loan $loan */
            $loan = $item['loan'];
            $result = $glService->applyMissingReleaseFeeDeductions($loan, $userId);

            $line = sprintf(
                'Loan #%d %s: [%s] %s',
                $loan->id,
                $loan->loanNo,
                $result['status'],
                $result['message']
            );

            if ($result['status'] === 'fixed') {
                $this->info($line);
                $fixed++;
            } elseif ($result['status'] === 'error') {
                $this->error($line);
                $errors++;
            } else {
                $this->line($line);
                $skipped++;
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Fixed', $fixed],
                ['Skipped', $skipped],
                ['Errors', $errors],
            ]
        );

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
