<?php

namespace App\Support\Loans;

use App\Models\Group;
use App\Models\Loan;

class GroupRepaymentDataBuilder
{
    /**
     * @return array{repaymentData: array<int, array<string, mixed>>, totalAmountToPay: float}
     */
    public static function build(Group $group): array
    {
        $customers = $group->members()->with([
            'loans' => function ($query) use ($group) {
                $query->where('group_id', $group->id)
                    ->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DEFAULTED])
                    ->with(['schedule.repayments']);
            },
        ])->get();

        $repaymentData = [];
        $totalAmountToPay = 0.0;

        foreach ($customers as $customer) {
            if ($customer->loans->isEmpty()) {
                continue;
            }

            $customerData = [
                'customer' => $customer,
                'loans' => [],
            ];

            foreach ($customer->loans as $loan) {
                $unpaidSchedule = $loan->schedule
                    ->sortBy('due_date')
                    ->first(function ($schedule) {
                        $amountDue = $schedule->principal
                            + $schedule->interest
                            + $schedule->fee_amount
                            + $schedule->penalty_amount;

                        $totalPaid = $schedule->repayments->sum(function ($repayment) {
                            return $repayment->principal
                                + $repayment->interest
                                + $repayment->penalt_amount
                                + $repayment->fee_amount;
                        });

                        return $totalPaid < $amountDue;
                    });

                if (! $unpaidSchedule) {
                    continue;
                }

                $totalDue = $unpaidSchedule->principal
                    + $unpaidSchedule->interest
                    + $unpaidSchedule->penalty_amount
                    + $unpaidSchedule->fee_amount;

                $amountAlreadyPaid = $unpaidSchedule->repayments->sum(function ($repayment) {
                    return $repayment->principal
                        + $repayment->interest
                        + $repayment->penalt_amount
                        + $repayment->fee_amount;
                });

                $remainingAmountToPay = $totalDue - $amountAlreadyPaid;
                $totalAmountToPay += $remainingAmountToPay;

                $customerData['loans'][] = [
                    'loan' => $loan,
                    'schedule' => $unpaidSchedule,
                    'amount_to_pay' => $remainingAmountToPay,
                    'installment_amount' => $unpaidSchedule->principal + $unpaidSchedule->interest,
                    'penalty_amount' => $unpaidSchedule->penalty_amount,
                    'fee_amount' => $unpaidSchedule->fee_amount,
                    'total_due' => $totalDue,
                    'amount_already_paid' => $amountAlreadyPaid,
                ];
            }

            if (! empty($customerData['loans'])) {
                $repaymentData[] = $customerData;
            }
        }

        return [
            'repaymentData' => $repaymentData,
            'totalAmountToPay' => $totalAmountToPay,
        ];
    }

    /**
     * Flatten repayment data for Excel export.
     *
     * @param  array<int, array<string, mixed>>  $repaymentData
     * @return array<int, array<int, mixed>>
     */
    public static function flattenForExport(array $repaymentData): array
    {
        $rows = [];

        foreach ($repaymentData as $customerData) {
            $customer = $customerData['customer'];
            foreach ($customerData['loans'] as $loanData) {
                $loan = $loanData['loan'];
                $schedule = $loanData['schedule'];

                $rows[] = [
                    $customer->customerNo ?? '',
                    $customer->name ?? '',
                    $loan->loanNo ?? '',
                    $customer->id,
                    $loan->id,
                    $schedule->id,
                    \Carbon\Carbon::parse($schedule->due_date)->format('Y-m-d'),
                    round((float) $loanData['installment_amount'], 2),
                    round((float) $loanData['fee_amount'], 2),
                    round((float) $loanData['penalty_amount'], 2),
                    round((float) $loanData['amount_already_paid'], 2),
                    round((float) $loanData['total_due'], 2),
                    round((float) $loanData['amount_to_pay'], 2),
                ];
            }
        }

        return $rows;
    }
}
