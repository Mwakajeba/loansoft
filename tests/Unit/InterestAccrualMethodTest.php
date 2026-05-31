<?php

namespace Tests\Unit;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Support\Loans\InterestAccrualMethod;
use PHPUnit\Framework\TestCase;

class InterestAccrualMethodTest extends TestCase
{
    public function test_daily_detection(): void
    {
        $this->assertTrue(InterestAccrualMethod::isDaily('daily'));
        $this->assertTrue(InterestAccrualMethod::isDaily('daily_bases'));
        $this->assertFalse(InterestAccrualMethod::isDaily('as_expected_interest'));
    }

    public function test_balance_interest_daily_uses_accrued_only(): void
    {
        $product = new LoanProduct(['penalt_deduction_criteria' => 'daily']);
        $loan = new Loan();
        $loan->setRelation('product', $product);

        $schedule = new LoanSchedule([
            'interest' => 10000,
            'accrued_interest' => 150.50,
        ]);
        $schedule->setRelation('loan', $loan);

        $this->assertEquals(150.50, $schedule->balance_interest_component);
    }

    public function test_balance_interest_as_expected_uses_scheduled(): void
    {
        $product = new LoanProduct(['penalt_deduction_criteria' => 'as_expected_interest']);
        $loan = new Loan();
        $loan->setRelation('product', $product);

        $schedule = new LoanSchedule([
            'interest' => 10000,
            'accrued_interest' => 10000,
        ]);
        $schedule->setRelation('loan', $loan);

        $this->assertEquals(10000.0, $schedule->balance_interest_component);
    }
}
