<?php

namespace Tests\Unit;

use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Support\Loans\LoanAgingBuckets;
use App\Support\Loans\LoanReportMetrics;
use App\Support\Loans\LoanReportRowBuilder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class LoanSettlementBehaviorTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_loan_aging_buckets_split_current_and_especially_mentioned(): void
    {
        $current = LoanAgingBuckets::allocate(1000, 4);
        $especiallyMentioned = LoanAgingBuckets::allocate(1000, 10);

        $this->assertSame(1000.0, $current['bucket_current']);
        $this->assertSame(0.0, $current['bucket_esm']);
        $this->assertSame(1.0, $current['provision_rate']);

        $this->assertSame(0.0, $especiallyMentioned['bucket_current']);
        $this->assertSame(1000.0, $especiallyMentioned['bucket_esm']);
        $this->assertSame(5.0, $especiallyMentioned['provision_rate']);
    }

    public function test_settlement_amount_clears_due_components_then_future_principal_only(): void
    {
        Carbon::setTestNow('2026-05-26 09:00:00');

        $loan = new Loan([
            'status' => Loan::STATUS_ACTIVE,
            'amount' => 300,
        ]);
        $loan->setRelation('product', $this->stubProduct());

        $overdueSchedule = $this->makeSchedule(
            $loan,
            principal: 100,
            interest: 20,
            feeAmount: 10,
            penaltyAmount: 5,
            dueDate: '2026-05-20'
        );

        $futureSchedule = $this->makeSchedule(
            $loan,
            principal: 200,
            interest: 30,
            feeAmount: 0,
            penaltyAmount: 0,
            dueDate: '2026-06-20'
        );

        $loan->setRelation('schedule', new Collection([$overdueSchedule, $futureSchedule]));

        $plan = $loan->buildSettlementPlan('2026-05-26');

        $this->assertSame(135.0, $plan['total_due_components']);
        $this->assertSame(200.0, $plan['total_future_principal']);
        $this->assertSame(335.0, $plan['settle_amount']);
        $this->assertCount(1, $plan['due_schedule_payments']);
        $this->assertCount(1, $plan['future_principal_payments']);
        $this->assertSame(0.0, $plan['future_principal_payments'][0]['interest']);
    }

    public function test_legacy_dust_balance_does_not_show_arrears(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        $loan = new Loan([
            'status' => Loan::STATUS_ACTIVE,
            'amount' => 201699.27,
        ]);
        $loan->setRelation('product', $this->stubProduct());

        $paidSchedule = $this->makeSchedule(
            $loan,
            principal: 180000,
            interest: 21699.27,
            feeAmount: 0,
            penaltyAmount: 0,
            dueDate: '2026-06-02'
        );
        $paidSchedule->setRelation('repayments', new Collection([
            (object) [
                'principal' => 180000,
                'interest' => 21699.27,
                'fee_amount' => 0,
                'penalt_amount' => 0,
            ],
        ]));

        $futureSchedule = $this->makeSchedule(
            $loan,
            principal: 180000,
            interest: 21699.27,
            feeAmount: 0,
            penaltyAmount: 0,
            dueDate: '2026-07-02'
        );

        $loan->setRelation('schedule', new Collection([$paidSchedule, $futureSchedule]));

        $this->assertTrue($paidSchedule->is_fully_paid);
        $this->assertEquals(0.0, $paidSchedule->remaining_amount);
        $this->assertFalse($loan->is_in_arrears);
        $this->assertEquals(0.0, $loan->arrears_amount);
        $this->assertSame(0, $loan->days_in_arrears);
    }

    public function test_completed_loans_do_not_show_arrears_or_settlement_balance(): void
    {
        Carbon::setTestNow('2026-05-26 09:00:00');

        $loan = new Loan([
            'status' => Loan::STATUS_COMPLETE,
            'amount' => 100,
        ]);
        $loan->setRelation('product', $this->stubProduct());

        $schedule = $this->makeSchedule(
            $loan,
            principal: 100,
            interest: 20,
            feeAmount: 0,
            penaltyAmount: 0,
            dueDate: '2026-05-20'
        );

        $loan->setRelation('schedule', new Collection([$schedule]));

        $this->assertSame(0, $loan->days_in_arrears);
        $this->assertEquals(0.0, $loan->arrears_amount);
        $this->assertEquals(0.0, $loan->getTotalOutstandingAmount());
        $this->assertEquals(0.0, $loan->getTotalAmountToSettle());
    }

    public function test_portfolio_row_treats_settled_active_loan_as_completed_with_zero_balances(): void
    {
        Carbon::setTestNow('2026-05-26 09:00:00');

        $loan = new Loan([
            'status' => Loan::STATUS_ACTIVE,
            'amount' => 100,
            'loanNo' => 'SF-TEST-1',
            'disbursed_on' => '2026-05-01',
            'last_repayment_date' => '2026-06-30',
        ]);
        $loan->setRelation('product', $this->stubProduct());
        $loan->setRelation('customer', (object) ['name' => 'Test Customer', 'phone1' => '255700000000']);
        $loan->setRelation('branch', null);
        $loan->setRelation('group', null);
        $loan->setRelation('loanOfficer', null);

        $futureSchedule = $this->makeSchedule(
            $loan,
            principal: 100,
            interest: 20,
            feeAmount: 0,
            penaltyAmount: 0,
            dueDate: '2026-06-20'
        );

        $futureSchedule->setRelation('repayments', new Collection([
            (object) [
                'payment_date' => '2026-05-15',
                'principal' => 100,
                'interest' => 0,
                'fee_amount' => 0,
                'penalt_amount' => 0,
            ],
        ]));

        $loan->setRelation('schedule', new Collection([$futureSchedule]));

        $this->assertEquals(0.0, LoanReportMetrics::settlementBalanceAsOf($loan, '2026-05-26'));

        $row = LoanReportRowBuilder::portfolioRow($loan, '2026-05-26');

        $this->assertSame(Loan::STATUS_COMPLETE, $row['status']);
        $this->assertSame(0, $row['days_in_arrears']);
        $this->assertEquals(0.0, $row['management_fees_balance']);
        $this->assertEquals(0.0, $row['outstanding_principal']);
        $this->assertEquals(0.0, $row['outstanding_interest']);
        $this->assertEquals(0.0, $row['accrued_penalties']);
        $this->assertEquals(0.0, $row['outstanding_balance']);
    }

    private function makeSchedule(
        Loan $loan,
        float $principal,
        float $interest,
        float $feeAmount,
        float $penaltyAmount,
        string $dueDate
    ): LoanSchedule {
        $schedule = new LoanSchedule([
            'principal' => $principal,
            'interest' => $interest,
            'fee_amount' => $feeAmount,
            'penalty_amount' => $penaltyAmount,
            'due_date' => $dueDate,
            'status' => 'active',
        ]);

        $schedule->setRelation('loan', $loan);
        $schedule->setRelation('repayments', new Collection());

        return $schedule;
    }

    private function stubProduct(): object
    {
        return new class {
            public function usesDailyInterestAccrual(): bool
            {
                return false;
            }
        };
    }
}
