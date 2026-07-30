<?php

namespace Tests\Unit;

use App\Models\LoanSchedule;
use App\Models\Repayment;
use App\Support\Loans\GroupRepaymentAllocator;
use Illuminate\Support\Collection;
use Tests\TestCase;

class GroupRepaymentAllocatorTest extends TestCase
{
    public function test_overpayment_is_applied_to_later_schedules_first(): void
    {
        $result = GroupRepaymentAllocator::allocate([
            $this->schedule(1, 10_000, 2_000, ['principal' => 9_000, 'interest' => 1_400]),
            $this->schedule(2, 40_000, 10_000),
        ], 50_000, ['interest', 'principal']);

        $this->assertSame(0.0, $result['cash_collateral']);
        $this->assertCount(2, $result['allocations']);
        $this->assertSame(1_600.0, $result['allocations'][0]['total']);
        $this->assertSame(48_400.0, $result['allocations'][1]['total']);
        $this->assertSame(10_000.0, $result['allocations'][1]['interest']);
        $this->assertSame(38_400.0, $result['allocations'][1]['principal']);
    }

    public function test_amount_beyond_all_remaining_schedules_becomes_cash_collateral(): void
    {
        $result = GroupRepaymentAllocator::allocate([
            $this->schedule(1, 10_000, 0),
            $this->schedule(2, 20_000, 0),
        ], 50_000, ['principal']);

        $this->assertCount(2, $result['allocations']);
        $this->assertSame(20_000.0, $result['cash_collateral']);
    }

    private function schedule(
        int $id,
        float $principal,
        float $interest,
        array $paid = []
    ): LoanSchedule {
        $schedule = new LoanSchedule([
            'principal' => $principal,
            'interest' => $interest,
            'fee_amount' => 0,
            'penalty_amount' => 0,
        ]);
        $schedule->id = $id;

        $repayment = new Repayment([
            'principal' => $paid['principal'] ?? 0,
            'interest' => $paid['interest'] ?? 0,
            'fee_amount' => $paid['fee_amount'] ?? 0,
            'penalt_amount' => $paid['penalt_amount'] ?? 0,
        ]);
        $schedule->setRelation('repayments', new Collection([$repayment]));

        return $schedule;
    }
}
