<?php

namespace App\Support\Loans;

use App\Models\Group;
use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GroupLoansRowBuilder
{
    public static function loansForGroup(Group $group): Collection
    {
        try {
            return $group->loans()->with(['customer', 'repayments', 'schedule'])->get();
        } catch (\Throwable $e) {
            return $group->getGroupLoans()->with(['customer', 'repayments', 'schedule'])->get();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function buildRows(Group $group, bool $forExport = false): array
    {
        return self::loansForGroup($group)
            ->map(fn (Loan $loan) => self::mapLoan($loan, $forExport))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapLoan(Loan $loan, bool $forExport = false): array
    {
        $totalPaid = $loan->repayments->sum(function ($repayment) {
            return $repayment->principal
                + $repayment->interest
                + $repayment->penalt_amount
                + $repayment->fee_amount;
        });

        $amountWithInterest = $loan->amount_total ?? ($loan->amount + ($loan->interest_amount ?? 0));
        $outstanding = $amountWithInterest - $totalPaid;
        $disbursedOn = $loan->disbursed_on
            ? Carbon::parse($loan->disbursed_on)
            : ($loan->created_at ? Carbon::parse($loan->created_at) : null);
        $expiryDate = $loan->last_repayment_date
            ? Carbon::parse($loan->last_repayment_date)
            : null;

        if ($forExport) {
            return [
                $loan->loanNo ?? $loan->id,
                $loan->customer->customerNo ?? '',
                $loan->customer->name ?? '',
                round((float) $amountWithInterest, 2),
                round((float) $totalPaid, 2),
                round((float) $outstanding, 2),
                $disbursedOn?->format('Y-m-d') ?? '',
                $expiryDate?->format('Y-m-d') ?? '',
                ucfirst((string) ($loan->status ?? '')),
            ];
        }

        return [
            'loan_no' => $loan->loanNo ?? $loan->id,
            'customer_no' => $loan->customer->customerNo ?? '',
            'customer' => $loan->customer->name ?? '',
            'amount_with_interest' => number_format($amountWithInterest, 2),
            'total_paid' => number_format($totalPaid, 2),
            'outstanding' => number_format($outstanding, 2),
            'disbursed_on' => $disbursedOn ? $disbursedOn->format('M d, Y') : '',
            'last_repayment_date' => $expiryDate ? $expiryDate->format('M d, Y') : '',
            'show_url' => route('loans.show', [\Vinkla\Hashids\Facades\Hashids::encode($loan->id)]),
        ];
    }
}
