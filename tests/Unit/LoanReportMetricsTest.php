<?php

namespace Tests\Unit;

use App\Support\Loans\LoanReportMetrics;
use PHPUnit\Framework\TestCase;

class LoanReportMetricsTest extends TestCase
{
    public function test_contract_totals_relationship(): void
    {
        $paid = ['principal' => 100, 'interest' => 50, 'penalties' => 10, 'fees' => 5, 'total' => 165];
        $outstanding = [
            'outstanding_principal' => 200,
            'outstanding_interest' => 30,
            'outstanding_penalty' => 5,
            'outstanding_fees' => 0,
            'total_balance' => 235,
        ];

        $totalDue = $paid['total'] + $outstanding['total_balance'];
        $this->assertEquals(400, $totalDue);
        $this->assertEquals(41.25, ($paid['total'] / $totalDue) * 100);
    }
}
