<?php

namespace Tests\Unit;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Support\Loans\CustomerLoanStatementBuilder;
use App\Support\Loans\LoanReportMetrics;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class CustomerLoanStatementBuilderTest extends TestCase
{
    public function test_builds_schedule_rows_with_settlement_totals(): void
    {
        $loan = $this->makeLoanWithSchedules();
        $asOfDate = '2026-06-25';

        $report = CustomerLoanStatementBuilder::build($loan, $asOfDate);

        $this->assertSame('JOHN DOE', $report['client_name']);
        $this->assertSame(6000000.0, $report['summary']['principal']);
        $this->assertSame(630000.0, $report['summary']['interest']);
        $this->assertSame(6630000.0, $report['summary']['total_pi']);
        $this->assertCount(3, $report['schedule_rows']);

        $firstRow = $report['schedule_rows'][0];
        $this->assertSame(1, $firstRow['sn']);
        $this->assertSame('paid', $firstRow['row_class']);
        $this->assertNull($firstRow['outstanding_balance']);

        $overdueRow = $report['schedule_rows'][1];
        $this->assertSame('overdue', $overdueRow['row_class']);
        $this->assertGreaterThan(0, $overdueRow['outstanding_balance']);
        $this->assertGreaterThan(0, $overdueRow['days_in_arrears']);

        $futureRow = $report['schedule_rows'][2];
        $this->assertSame('future', $futureRow['row_class']);
        $this->assertSame(710000.0, $futureRow['outstanding_balance']);

        $this->assertArrayHasKey('outstanding_amount', $report['settlements']);
        $this->assertArrayHasKey('loan_settlement_amount', $report['settlements']);
        $this->assertGreaterThan(0, $report['settlements']['outstanding_amount']);
    }

    public function test_days_in_arrears_matches_loan_page_logic(): void
    {
        $loan = $this->makeLoanWithSchedules();
        $asOfDate = '2026-06-25';

        $overdueSchedule = $loan->schedule->sortBy('due_date')->values()->get(1);
        $report = CustomerLoanStatementBuilder::build($loan, $asOfDate);
        $overdueRow = $report['schedule_rows'][1];

        $expectedDays = LoanReportMetrics::scheduleDaysInArrearsAsOf($overdueSchedule, $asOfDate);
        $loanPageStyleDays = (int) round(
            Carbon::parse($overdueSchedule->due_date)->diffInDays(Carbon::parse($asOfDate)->endOfDay())
        );

        $this->assertSame(25, $loanPageStyleDays);
        $this->assertSame($expectedDays, $overdueRow['days_in_arrears']);
        $this->assertSame(25, $overdueRow['days_in_arrears']);
    }

    private function makeLoanWithSchedules(): Loan
    {
        $loan = new Loan([
            'id' => 1,
            'loanNo' => 'LN-001',
            'amount' => 6000000,
            'period' => 12,
            'status' => Loan::STATUS_ACTIVE,
            'disbursed_on' => '2025-12-18',
        ]);

        $loan->setRelation('customer', (object) ['name' => 'JOHN DOE']);
        $product = new LoanProduct(['name' => 'Business Loan', 'interest_method' => 'flat', 'fees_ids' => []]);
        $loan->setRelation('product', $product);
        $loan->setRelation('branch', (object) ['name' => 'Main']);
        $loan->setRelation('receipts', collect());
        $loan->setRelation('repayments', collect());

        $schedules = new Collection([
            $this->makeSchedule(1, '2026-01-18', 500000, 210000, 0, [
                ['payment_date' => '2026-01-20', 'principal' => 500000, 'interest' => 210000, 'fee_amount' => 0, 'penalt_amount' => 0],
            ]),
            $this->makeSchedule(2, '2026-06-01', 500000, 210000, 100000, [
                ['payment_date' => '2026-06-10', 'principal' => 200000, 'interest' => 100000, 'fee_amount' => 0, 'penalt_amount' => 0],
            ]),
            $this->makeSchedule(3, '2026-07-18', 500000, 210000, 0, []),
        ]);

        $loan->setRelation('schedule', $schedules);

        foreach ($schedules as $schedule) {
            $schedule->setRelation('loan', $loan);
        }

        return $loan;
    }

    private function makeSchedule(int $id, string $dueDate, float $principal, float $interest, float $penalty, array $repayments): LoanSchedule
    {
        $schedule = new LoanSchedule([
            'id' => $id,
            'loan_id' => 1,
            'due_date' => $dueDate,
            'principal' => $principal,
            'interest' => $interest,
            'fee_amount' => 0,
            'penalty_amount' => $penalty,
            'status' => 'pending',
        ]);

        $repaymentModels = collect($repayments)->map(function (array $data) {
            return (object) $data;
        });

        $schedule->setRelation('repayments', $repaymentModels);

        return $schedule;
    }
}
