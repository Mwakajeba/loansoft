<?php

namespace App\Console\Commands;

use App\Services\LoanDisbursementGlService;
use Illuminate\Console\Command;

class DedupeLoanDisbursementGlCommand extends Command
{
    protected $signature = 'loans:dedupe-disbursement-gl {loan_id? : Optional loan ID to clean}';

    protected $description = 'Remove duplicate Loan Disbursement GL entries (keeps oldest per loan)';

    public function handle(LoanDisbursementGlService $service): int
    {
        $loanId = $this->argument('loan_id');
        $loanId = $loanId !== null ? (int) $loanId : null;

        $removed = $service->removeDuplicateDisbursementGlEntries($loanId);

        $this->info("Removed {$removed} duplicate disbursement GL row(s).");

        return self::SUCCESS;
    }
}
