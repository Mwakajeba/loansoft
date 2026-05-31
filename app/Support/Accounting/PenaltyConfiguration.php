<?php

namespace App\Support\Accounting;

use App\Models\Penalty;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for penalty settings from /accounting/penalties.
 */
final class PenaltyConfiguration
{
    public const FREQUENCY_CYCLES = [
        'daily', 'weekly', 'monthly', 'quarterly', 'semi_annually', 'annually', 'yearly',
    ];

    public const DEDUCTION_TYPES = [
        'over_due_principal_amount',
        'over_due_interest_amount',
        'over_due_principal_and_interest',
        'total_principal_amount_released',
    ];

    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'penalty_income_account_id' => 'required|exists:chart_accounts,id',
            'penalty_receivables_account_id' => 'required|exists:chart_accounts,id',
            'penalty_type' => 'required|in:fixed,percentage',
            'charge_frequency' => 'required|in:daily,one_time',
            'frequency_cycle' => ['required', Rule::in(self::FREQUENCY_CYCLES)],
            'penalty_limit_days' => 'nullable|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'deduction_type' => ['required', Rule::in(self::DEDUCTION_TYPES)],
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ];
    }

    /**
     * Rules for unit tests (no chart_accounts DB lookup).
     */
    public static function rulesWithoutDatabase(): array
    {
        $rules = self::rules();
        $rules['penalty_income_account_id'] = 'required|integer|min:1';
        $rules['penalty_receivables_account_id'] = 'required|integer|min:1';

        return $rules;
    }

    public static function validateRequest(array $data, ?array $rules = null): Validator
    {
        $validator = ValidatorFacade::make($data, $rules ?? self::rules());

        $validator->after(function (Validator $v) use ($data) {
            if (($data['penalty_type'] ?? '') === 'percentage' && (float) ($data['amount'] ?? 0) > 100) {
                $v->errors()->add('amount', 'Percentage amount cannot exceed 100.');
            }

            if (($data['charge_frequency'] ?? '') !== 'daily' && !empty($data['penalty_limit_days'])) {
                $v->errors()->add(
                    'penalty_limit_days',
                    'Penalty limit days applies only when charge frequency is Daily.'
                );
            }
        });

        return $validator;
    }

    /**
     * Normalize persisted attributes (empty limit on one-time, default cycle).
     */
    public static function normalizeAttributes(array $data): array
    {
        if (($data['charge_frequency'] ?? '') !== 'daily') {
            $data['penalty_limit_days'] = null;
        }

        if (empty($data['frequency_cycle'])) {
            $data['frequency_cycle'] = 'monthly';
        }

        return $data;
    }

    /**
     * Ensure penalty record is usable for accrual engine.
     */
    public static function assertAccrualReady(Penalty $penalty): void
    {
        if (!$penalty->isActive()) {
            throw new \InvalidArgumentException("Penalty \"{$penalty->name}\" is inactive.");
        }

        if ((float) $penalty->amount <= 0) {
            throw new \InvalidArgumentException("Penalty \"{$penalty->name}\" amount must be greater than zero.");
        }

        if (!$penalty->penalty_receivables_account_id || !$penalty->penalty_income_account_id) {
            throw new \InvalidArgumentException("Penalty \"{$penalty->name}\" requires income and receivable GL accounts.");
        }

        if (!in_array($penalty->deduction_type, self::DEDUCTION_TYPES, true)) {
            throw new \InvalidArgumentException("Penalty \"{$penalty->name}\" has invalid deduction type.");
        }

        if (!in_array($penalty->charge_frequency, ['daily', 'one_time'], true)) {
            throw new \InvalidArgumentException("Penalty \"{$penalty->name}\" has invalid charge frequency.");
        }

        if (!in_array($penalty->frequency_cycle ?? 'monthly', self::FREQUENCY_CYCLES, true)) {
            throw new \InvalidArgumentException("Penalty \"{$penalty->name}\" has invalid frequency cycle.");
        }
    }
}
