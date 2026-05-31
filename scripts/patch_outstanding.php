<?php

$file = __DIR__ . '/../app/Http/Controllers/LoanReportController.php';
$content = file_get_contents($file);
$marker = '$summaryKeys = [';
$start = strpos($content, $marker);
if ($start === false) {
    fwrite(STDERR, "summaryKeys not found\n");
    exit(1);
}
$outstandingStart = strrpos(substr($content, 0, $start), '$outstandingData = [];');
$end = strpos($content, '// Handle export requests', $start);
if ($outstandingStart === false || $end === false) {
    fwrite(STDERR, "end marker not found\n");
    exit(1);
}

$replacement = <<<'PHP'
$outstandingData = [];
        $summary = [
            'total_disbursed' => 0.0,
            'total_interest' => 0.0,
            'total_principal_interest' => 0.0,
            'total_expected_fees' => 0.0,
            'total_penalties' => 0.0,
            'total_principal_paid' => 0.0,
            'total_interest_paid' => 0.0,
            'total_fees_paid' => 0.0,
            'total_penalty_paid' => 0.0,
            'total_outstanding_principal' => 0.0,
            'total_outstanding_interest' => 0.0,
            'total_outstanding_fees' => 0.0,
            'total_outstanding_penalty' => 0.0,
            'total_outstanding_balance' => 0.0,
        ];

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::outstandingRow($loan, $asOfDate);
            if (!$row) {
                continue;
            }
            $outstandingData[] = $row;
            $summary['total_disbursed'] += $row['disbursed_amount'];
            $summary['total_interest'] += $row['total_interest'];
            $summary['total_principal_interest'] += $row['total_principal_interest'];
            $summary['total_expected_fees'] += $row['expected_fees'];
            $summary['total_penalties'] += $row['total_penalties'];
            $summary['total_principal_paid'] += $row['principal_paid'];
            $summary['total_interest_paid'] += $row['interest_paid'];
            $summary['total_fees_paid'] += $row['fees_paid'];
            $summary['total_penalty_paid'] += $row['penalty_paid'];
            $summary['total_outstanding_principal'] += $row['outstanding_principal'];
            $summary['total_outstanding_interest'] += $row['outstanding_interest'];
            $summary['total_outstanding_fees'] += $row['outstanding_fees'];
            $summary['total_outstanding_penalty'] += $row['outstanding_penalty'];
            $summary['total_outstanding_balance'] += $row['outstanding_balance'];
        }

        
PHP;

$new = substr($content, 0, $outstandingStart) . $replacement . substr($content, $end);
file_put_contents($file, $new);
echo "patched\n";
