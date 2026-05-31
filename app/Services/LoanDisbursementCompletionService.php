<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanApproval;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanDisbursementCompletionService
{
    public function __construct(
        private readonly LoanDisbursementGlService $disbursementGlService,
        private readonly LoanSmsNotificationService $smsNotifications,
    ) {}

    public function isAlreadyDisbursed(Loan $loan): bool
    {
        return $loan->status === Loan::STATUS_ACTIVE
            && $this->disbursementGlService->hasDisbursementGl($loan->id);
    }

    /**
     * Mark loan active, generate schedule, and post disbursement GL.
     */
    public function complete(Loan $loan, Carbon|string|null $disbursementDate = null, ?int $userId = null, ?string $approvalComments = null, bool $createApprovalRecord = false): Loan
    {
        if ($this->isAlreadyDisbursed($loan)) {
            return $loan->fresh();
        }

        if ($this->disbursementGlService->hasDisbursementGl($loan->id)) {
            throw new \RuntimeException('Disbursement accounting entries already exist for this loan.');
        }

        if (!$loan->bank_account_id) {
            throw new \RuntimeException('Bank account must be selected before disbursement.');
        }

        $disburseDate = $disbursementDate ? Carbon::parse($disbursementDate) : now();
        $userId = $userId ?? auth()->id();

        DB::transaction(function () use ($loan, $disburseDate, $userId, $approvalComments, $createApprovalRecord) {
            $loan->update([
                'status' => Loan::STATUS_ACTIVE,
                'disbursed_on' => $disburseDate,
            ]);

            $interestAmount = $loan->calculateInterestAmount($loan->interest);
            $repaymentDates = $loan->getRepaymentDates();

            $loan->update([
                'interest_amount' => $interestAmount,
                'amount_total' => $loan->amount + $interestAmount,
                'first_repayment_date' => $repaymentDates['first_repayment_date'],
                'last_repayment_date' => $repaymentDates['last_repayment_date'],
            ]);

            $loan->generateRepaymentSchedule($loan->interest);

            if ($disburseDate->copy()->startOfDay()->lt(Carbon::today())) {
                $loan->postMaturedInterestForPastLoan();
                $loan->accruePenaltiesForPastLoanWhenReady();
            }

            $this->disbursementGlService->postDisbursement(
                $loan,
                $disburseDate,
                $userId,
                auth()->user()?->branch_id
            );

            if ($createApprovalRecord && $userId) {
                $nextLevel = $loan->getNextApprovalLevel();
                $roleName = $loan->getApprovalLevelName($nextLevel);

                LoanApproval::create([
                    'loan_id' => $loan->id,
                    'user_id' => $userId,
                    'role_name' => $roleName ?? 'system',
                    'approval_level' => $nextLevel ?? 0,
                    'action' => 'active',
                    'comments' => $approvalComments ?? 'Disbursed via DCB gateway',
                    'approved_at' => now(),
                ]);
            }
        });

        $loan = $loan->fresh();
        $this->smsNotifications->sendDisbursementNotification($loan);

        return $loan;
    }

    public function netDisbursementAmount(Loan $loan): int
    {
        $releaseFeeTotal = $this->disbursementGlService->calculateReleaseFeeTotal($loan);

        return (int) round((float) $loan->amount - $releaseFeeTotal);
    }
}
