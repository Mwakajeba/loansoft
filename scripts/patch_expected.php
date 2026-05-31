<?php

$file = __DIR__ . '/../app/Http/Controllers/LoanReportController.php';
$content = file_get_contents($file);

$start = strpos($content, 'private function getExpectedVsCollectedData');
$end = strpos($content, '    /**', strpos($content, 'return $reportData;', $start));
if ($start === false || $end === false) {
    fwrite(STDERR, "getExpectedVsCollectedData bounds not found\n");
    exit(1);
}

$replacement = <<<'PHP'
private function getExpectedVsCollectedData($startDate, $endDate, $branchId = null, $groupId = null, $loanOfficerId = null)
    {
        Loan::syncActiveLoansEligibleForCompletion();

        $user = auth()->user();
        $company = $user->company;

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $loansQuery = Loan::with(['customer', 'branch', 'group', 'loanOfficer', 'schedule.repayments'])
                          ->where('status', 'active')
                          ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($groupId) {
            $loansQuery->where('group_id', $groupId);
        }

        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();
        $reportData = [];

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::expectedVsCollectedRow($loan, $startDate, $endDate);
            if ($row) {
                $reportData[] = $row;
            }
        }

        usort($reportData, fn ($a, $b) => $a['balance_due'] <=> $b['balance_due']);

        return $reportData;
    }

PHP;

$new = substr($content, 0, $start) . $replacement . substr($content, $end);
file_put_contents($file, $new);
echo "patched expected vs collected\n";
