<?php

namespace App\Jobs;

use App\Helpers\SmsHelper;
use App\Jobs\CalculateDailyInterestJob;
use App\Models\JobLog;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Services\PenaltyAccrualService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AccruePenaltyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public $tries = 3;

    public function __construct(
        protected $accrualDate = null,
        protected bool $runDailyInterestAfter = true,
        protected ?int $loanId = null,
        protected bool $force = false
    ) {
        $this->accrualDate = $accrualDate ? Carbon::parse($accrualDate)->toDateString() : Carbon::today()->toDateString();
    }

    public function handle(): void
    {
        @set_time_limit(600);

        $accrualDate = Carbon::parse($this->accrualDate)->startOfDay();
        $cacheKey = 'penalty_accrual_job_ran_' . $accrualDate->toDateString()
            . ($this->loanId ? '_loan_' . $this->loanId : '');

        if (!$this->force && !Cache::add($cacheKey, true, $accrualDate->copy()->endOfDay())) {
            Log::info('AccruePenaltyJob already completed for ' . $accrualDate->toDateString() . ', skipping duplicate run');

            return;
        }

        $service = PenaltyAccrualService::forDate($accrualDate->toDateString());
        $startTime = now();

        $jobLog = JobLog::create([
            'job_name' => 'AccruePenaltyJob',
            'status' => 'running',
            'started_at' => $startTime,
        ]);

        Log::info('Starting Penalty Accrual Engine', [
            'date' => $accrualDate->toDateString(),
            'job_log_id' => $jobLog->id,
            'loan_id' => $this->loanId,
        ]);

        $totalProcessed = 0;
        $totalSuccessful = 0;
        $totalFailed = 0;
        $totalPenaltyAccrued = 0.0;
        $perScheduleDetails = [];

        try {
            $query = Loan::where('status', 'active')
                ->with([
                    'product',
                    'customer',
                    'branch.company',
                    'schedule' => function ($q) {
                        $q->where('status', '!=', 'restructured')->with('repayments');
                    },
                ]);

            if ($this->loanId) {
                $query->where('id', $this->loanId);
            }

            $activeLoans = $query->get();

            foreach ($activeLoans as $loan) {
                try {
                    $result = $this->processLoan($loan, $service);

                    if ($result && $result['penalty_amount'] > 0) {
                        $totalProcessed++;
                        $totalSuccessful++;
                        $totalPenaltyAccrued += $result['penalty_amount'];
                        foreach ($result['schedules'] as $detail) {
                            $perScheduleDetails[] = $detail;
                        }
                    }
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $perScheduleDetails[] = [
                        'loan_id' => $loan->id,
                        'loan_no' => $loan->loanNo ?? ('#' . $loan->id),
                        'error' => $e->getMessage(),
                    ];
                    Log::error("Penalty accrual failed for loan {$loan->id}: " . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $endTime = now();
            $duration = $startTime->diffInSeconds($endTime);

            if (!empty($perScheduleDetails)) {
                Cache::put('penalty_accrual_job_details_' . $jobLog->id, $perScheduleDetails, now()->addDays(30));
            }

            $jobLog->update([
                'status' => 'completed',
                'processed' => $totalProcessed,
                'successful' => $totalSuccessful,
                'failed' => $totalFailed,
                'total_amount' => $totalPenaltyAccrued,
                'summary' => "Processed {$totalProcessed} loans. Penalty accrued: TZS " . number_format($totalPenaltyAccrued, 2),
                'completed_at' => $endTime,
                'duration_seconds' => $duration,
            ]);

            if ($this->runDailyInterestAfter) {
                try {
                    dispatch_sync(new CalculateDailyInterestJob($accrualDate->toDateString()));
                } catch (\Throwable $e) {
                    Log::error('CalculateDailyInterestJob after penalty accrual failed: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            $jobLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e;
        }
    }

    private function processLoan(Loan $loan, PenaltyAccrualService $service): ?array
    {
        $penalty = $service->resolvePenaltyForLoan($loan);
        if (!$penalty) {
            return null;
        }

        if (!$penalty->penalty_receivables_account_id || !$penalty->penalty_income_account_id) {
            Log::warning("Loan {$loan->loanNo}: Penalty #{$penalty->id} missing GL accounts");

            return null;
        }

        $totalPenaltyAccrued = 0.0;
        $schedules = [];

        foreach ($loan->schedule as $schedule) {
            $calculation = $service->shouldAccrueSchedule($loan, $schedule, $penalty);
            if (!$calculation) {
                continue;
            }

            try {
                $service->postAccrual($loan, $schedule, $penalty, $calculation);
                $this->sendPenaltySms($loan, $schedule, $calculation['penalty_amount'], $calculation['days_overdue']);

                $totalPenaltyAccrued += $calculation['penalty_amount'];
                $schedules[] = [
                    'loan_id' => $loan->id,
                    'loan_no' => $loan->loanNo,
                    'schedule_id' => $schedule->id,
                    'due_date' => $schedule->due_date,
                    'penalty_amount' => $calculation['penalty_amount'],
                    'base_amount' => $calculation['base_amount'],
                    'days_overdue' => $calculation['days_overdue'],
                    'accrual_date' => $service->accrualDate->toDateString(),
                ];
            } catch (\Throwable $e) {
                Log::error("Failed schedule {$schedule->id} loan {$loan->loanNo}: " . $e->getMessage());
                throw $e;
            }
        }

        if ($totalPenaltyAccrued <= 0) {
            return null;
        }

        return [
            'penalty_amount' => $totalPenaltyAccrued,
            'schedules' => $schedules,
        ];
    }

    private function sendPenaltySms(Loan $loan, LoanSchedule $schedule, float $penaltyAmount, int $daysOverdue): void
    {
        try {
            $customer = $loan->customer;
            if (!$customer || empty($customer->phone1)) {
                return;
            }

            $company = ($loan->branch && $loan->branch->company) ? $loan->branch->company : null;
            $companyName = $company?->name ?? 'SMARTFINANCE';
            $companyPhone = $company?->phone ?? '';
            $daysText = $daysOverdue <= 0 ? 'leo' : "siku {$daysOverdue} zilizopita";

            $templateVars = [
                'customer_name' => (string) ($customer->name ?? ''),
                'amount' => number_format($penaltyAmount, 2),
                'days_overdue' => $daysText,
                'loan_no' => (string) ($loan->loanNo ?? ''),
                'due_date' => $schedule->due_date ? Carbon::parse($schedule->due_date)->format('d/m/Y') : '',
                'company_name' => $companyName,
                'company_phone' => $companyPhone,
            ];

            $message = SmsHelper::resolveTemplate('loan_penalty', $templateVars)
                ?? "Habari {$templateVars['customer_name']}. Adhabu TZS {$templateVars['amount']} imeongezwa kwenye mkopo {$templateVars['loan_no']}. Asante.";

            SmsHelper::send(normalize_phone_number($customer->phone1), $message, 'loan_penalty');
        } catch (\Throwable $e) {
            Log::error("Penalty SMS failed loan {$loan->loanNo}: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AccruePenaltyJob failed: ' . $exception->getMessage());
    }
}
