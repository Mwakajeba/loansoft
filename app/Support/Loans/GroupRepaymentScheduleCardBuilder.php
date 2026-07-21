<?php

namespace App\Support\Loans;

use App\Models\CashCollateral;
use App\Models\Group;
use App\Models\Loan;
use App\Models\LoanSchedule;
use Carbon\Carbon;

class GroupRepaymentScheduleCardBuilder
{
    /**
     * Build group repayment schedule card data for members with active/defaulted loans.
     *
     * @return array{
     *     group: Group|null,
     *     schedule_dates: array<int, Carbon>,
     *     date_keys: array<int, string>,
     *     rows: array<int, array<string, mixed>>,
     *     column_totals: array<string, float>
     * }
     */
    public static function build(int $groupId, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $group = Group::with(['groupLeader', 'loanOfficer', 'branch'])->find($groupId);

        if (! $group) {
            return [
                'group' => null,
                'schedule_dates' => [],
                'date_keys' => [],
                'rows' => [],
                'column_totals' => [],
            ];
        }

        $loans = Loan::with(['customer', 'schedule.repayments', 'schedule.loan.product'])
            ->where('group_id', $groupId)
            ->whereIn('status', [Loan::STATUS_ACTIVE, Loan::STATUS_DEFAULTED])
            ->get()
            ->sortBy(fn (Loan $loan) => $loan->customer->name ?? '');

        $customerIds = $loans->pluck('customer_id')->unique()->values();
        $securityByCustomer = CashCollateral::query()
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, SUM(amount) as total_security')
            ->groupBy('customer_id')
            ->pluck('total_security', 'customer_id');

        $cycleByCustomer = Loan::query()
            ->where('group_id', $groupId)
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, COUNT(*) as loan_count')
            ->groupBy('customer_id')
            ->pluck('loan_count', 'customer_id');

        $dateKeys = [];
        foreach ($loans as $loan) {
            foreach ($loan->schedule as $schedule) {
                $due = Carbon::parse($schedule->due_date);
                if ($due->between($start, $end)) {
                    $dateKeys[$due->format('Y-m-d')] = $due->copy();
                }
            }
        }
        ksort($dateKeys);

        $columnTotals = array_fill_keys(array_keys($dateKeys), 0.0);
        $rows = [];
        $rowNum = 0;

        foreach ($loans as $loan) {
            $rowNum++;
            $customer = $loan->customer;
            $schedulesByDate = $loan->schedule->keyBy(
                fn (LoanSchedule $schedule) => Carbon::parse($schedule->due_date)->format('Y-m-d')
            );

            $dateAmounts = [];
            $cRealization = 0.0;

            foreach ($dateKeys as $dateKey => $dueDate) {
                if (! isset($schedulesByDate[$dateKey])) {
                    $dateAmounts[$dateKey] = null;
                    continue;
                }

                $schedule = $schedulesByDate[$dateKey];
                $instalment = self::scheduleInstalmentAmount($schedule);

                $dateAmounts[$dateKey] = $instalment;
                $columnTotals[$dateKey] += $instalment;
                $cRealization += $instalment;
            }

            $firstSchedule = $loan->schedule->sortBy('due_date')->first();
            $breakdown = $loan->getOutstandingBalanceBreakdown();

            $rows[] = [
                'no' => $rowNum,
                'member_name' => $customer->name ?? 'N/A',
                'cycle' => str_pad((string) ($cycleByCustomer[$loan->customer_id] ?? 1), 2, '0', STR_PAD_LEFT),
                'security' => (float) ($securityByCustomer[$loan->customer_id] ?? 0),
                'loan_no' => $loan->loanNo,
                'ds_amount' => (float) $loan->amount,
                'ds_date' => $loan->disbursed_on,
                'installment_size' => $firstSchedule ? self::scheduleInstalmentAmount($firstSchedule) : 0.0,
                'c_realization' => round($cRealization, 2),
                'os_balance' => (float) ($breakdown['total_balance'] ?? 0),
                'date_amounts' => $dateAmounts,
            ];
        }

        return [
            'group' => $group,
            'schedule_dates' => array_values($dateKeys),
            'date_keys' => array_keys($dateKeys),
            'rows' => $rows,
            'column_totals' => $columnTotals,
        ];
    }

    private static function scheduleInstalmentAmount(LoanSchedule $schedule): float
    {
        return round(
            (float) $schedule->principal
            + (float) $schedule->balance_interest_component
            + (float) ($schedule->fee_amount ?? 0),
            2
        );
    }
}
