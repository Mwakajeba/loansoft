<?php

namespace Tests\Unit;

use App\Models\Penalty;
use App\Support\Accounting\PenaltyConfiguration;
use Tests\TestCase;

class PenaltyConfigurationTest extends TestCase
{
    public function test_rejects_percentage_over_100(): void
    {
        $v = PenaltyConfiguration::validateRequest([
            'name' => 'Test',
            'penalty_income_account_id' => 1,
            'penalty_receivables_account_id' => 2,
            'penalty_type' => 'percentage',
            'charge_frequency' => 'one_time',
            'frequency_cycle' => 'monthly',
            'amount' => 150,
            'deduction_type' => 'over_due_principal_and_interest',
            'status' => 'active',
        ], PenaltyConfiguration::rulesWithoutDatabase());

        $this->assertTrue($v->fails());
    }

    public function test_rejects_limit_days_on_one_time(): void
    {
        $v = PenaltyConfiguration::validateRequest([
            'name' => 'Test',
            'penalty_income_account_id' => 1,
            'penalty_receivables_account_id' => 2,
            'penalty_type' => 'fixed',
            'charge_frequency' => 'one_time',
            'frequency_cycle' => 'monthly',
            'penalty_limit_days' => 30,
            'amount' => 1000,
            'deduction_type' => 'over_due_principal_and_interest',
            'status' => 'active',
        ], PenaltyConfiguration::rulesWithoutDatabase());

        $this->assertTrue($v->fails());
    }

    public function test_normalizes_one_time_limit_to_null(): void
    {
        $data = PenaltyConfiguration::normalizeAttributes([
            'charge_frequency' => 'one_time',
            'penalty_limit_days' => 30,
            'frequency_cycle' => '',
        ]);

        $this->assertNull($data['penalty_limit_days']);
        $this->assertEquals('monthly', $data['frequency_cycle']);
    }

    public function test_assert_acrual_ready_passes_valid_penalty(): void
    {
        $p = new Penalty([
            'name' => 'OK',
            'status' => 'active',
            'amount' => 1,
            'penalty_type' => 'percentage',
            'charge_frequency' => 'one_time',
            'frequency_cycle' => 'monthly',
            'deduction_type' => 'over_due_principal_and_interest',
            'penalty_receivables_account_id' => 10,
            'penalty_income_account_id' => 20,
        ]);

        PenaltyConfiguration::assertAccrualReady($p);
        $this->assertTrue(true);
    }
}
