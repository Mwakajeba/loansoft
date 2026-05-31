<?php

namespace Tests\Unit;

use App\Models\Penalty;
use App\Services\PenaltyAccrualService;
use PHPUnit\Framework\TestCase;

class PenaltyAccrualServiceTest extends TestCase
{
    private function svc(): PenaltyAccrualService
    {
        return PenaltyAccrualService::forDate('2026-05-24');
    }

    private function penalty(array $attrs): Penalty
    {
        return new Penalty(array_merge([
            'deduction_type' => 'over_due_principal_and_interest',
            'status' => 'active',
            'penalty_receivables_account_id' => 1,
            'penalty_income_account_id' => 2,
        ], $attrs));
    }

    public function test_percentage_one_time(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'percentage',
            'charge_frequency' => 'one_time',
            'frequency_cycle' => 'monthly',
            'amount' => 1,
        ]);
        $this->assertEquals(1538.33, $this->svc()->calculatePenaltyAmount(153833.33, $p, 30));
    }

    public function test_percentage_daily_monthly_cycle(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'percentage',
            'charge_frequency' => 'daily',
            'frequency_cycle' => 'monthly',
            'amount' => 30,
        ]);
        $this->assertEquals(1000.0, $this->svc()->calculatePenaltyAmount(100000, $p, 5));
    }

    public function test_percentage_daily_semi_annual_cycle(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'percentage',
            'charge_frequency' => 'daily',
            'frequency_cycle' => 'semi_annually',
            'amount' => 18,
        ]);
        // 18% per 180 days => 0.1% per day => 100 on 100k base
        $this->assertEquals(100.0, $this->svc()->calculatePenaltyAmount(100000, $p, 1));
    }

    public function test_percentage_daily_daily_cycle(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'percentage',
            'charge_frequency' => 'daily',
            'frequency_cycle' => 'daily',
            'amount' => 0.5,
        ]);
        $this->assertEquals(500.0, $this->svc()->calculatePenaltyAmount(100000, $p, 1));
    }

    public function test_fixed_one_time(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'fixed',
            'charge_frequency' => 'one_time',
            'frequency_cycle' => 'monthly',
            'amount' => 5000,
        ]);
        $this->assertEquals(5000.0, $this->svc()->calculatePenaltyAmount(100000, $p, 10));
    }

    public function test_fixed_daily_monthly_cycle(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'fixed',
            'charge_frequency' => 'daily',
            'frequency_cycle' => 'monthly',
            'amount' => 3000,
        ]);
        $this->assertEquals(100.0, $this->svc()->calculatePenaltyAmount(100000, $p, 10));
    }

    public function test_zero_base_returns_zero(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'percentage',
            'charge_frequency' => 'one_time',
            'amount' => 5,
        ]);
        $this->assertEquals(0.0, $this->svc()->calculatePenaltyAmount(0, $p, 10));
    }

    /** @dataProvider dailyPercentageCycleProvider */
    public function test_percentage_daily_all_cycles(string $cycle, float $rate, float $expected): void
    {
        $p = $this->penalty([
            'penalty_type' => 'percentage',
            'charge_frequency' => 'daily',
            'frequency_cycle' => $cycle,
            'amount' => $rate,
        ]);
        $this->assertEquals($expected, $this->svc()->calculatePenaltyAmount(100000, $p, 1));
    }

    public static function dailyPercentageCycleProvider(): array
    {
        return [
            'daily' => ['daily', 1.0, 1000.0],
            'weekly' => ['weekly', 7.0, 1000.0],
            'monthly' => ['monthly', 30.0, 1000.0],
            'quarterly' => ['quarterly', 90.0, 1000.0],
            'semi_annually' => ['semi_annually', 180.0, 1000.0],
            'annually' => ['annually', 365.0, 1000.0],
            'yearly' => ['yearly', 365.0, 1000.0],
        ];
    }

    public function test_fixed_daily_quarterly_cycle(): void
    {
        $p = $this->penalty([
            'penalty_type' => 'fixed',
            'charge_frequency' => 'daily',
            'frequency_cycle' => 'quarterly',
            'amount' => 9000,
        ]);
        $this->assertEquals(100.0, $this->svc()->calculatePenaltyAmount(100000, $p, 5));
    }
}
