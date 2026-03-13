<?php

namespace App\Jobs;

use App\Helpers\SmsHelper;
use App\Models\Loan;
use App\Models\LoanSchedule;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RepaymentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    public function __construct()
    {
    }

    public function handle()
    {
        Log::info('Starting repayment reminder job');

        // Check master enable toggle (default: enabled)
        $enabled = filter_var(
            \App\Models\SystemSetting::getValue('sms_reminder_enabled', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($enabled === false) {
            Log::info('Repayment reminder job skipped — disabled in system settings.');
            return;
        }

        // Build target dates from per-day settings
        $today = Carbon::today();
        $targetDates = [];

        $dayMap = [
            'sms_reminder_3_days_before' => 3,
            'sms_reminder_2_days_before' => 2,
            'sms_reminder_1_day_before'  => 1,
            'sms_reminder_on_due_date'   => 0,
        ];

        foreach ($dayMap as $settingKey => $daysAhead) {
            $active = filter_var(
                \App\Models\SystemSetting::getValue($settingKey, $daysAhead !== 1),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
            if ($active !== false) {
                $targetDates[] = $today->copy()->addDays($daysAhead)->toDateString();
            }
        }

        if (empty($targetDates)) {
            Log::info('Repayment reminder job skipped — no reminder days are enabled.');
            return;
        }

        $schedules = LoanSchedule::with(['loan.product', 'loan.customer', 'repayments'])
            ->whereIn('due_date', $targetDates)
            ->get();

        $remindersSent = 0;

        foreach ($schedules as $schedule) {
            $loan = $schedule->loan;
            if (!$loan || !$loan->customer) {
                continue;
            }

            // Prevent duplicate reminder per schedule per day
            $cacheKey = 'repayment_reminder_sent_' . $schedule->id . '_' . Carbon::today()->toDateString();
            if (!Cache::add($cacheKey, true, Carbon::now()->endOfDay())) {
                continue; // already sent today
            }

            // Compute unpaid difference (schedule total - repayments sum for this schedule)
            $schedule->loadMissing('repayments');
            $totalDue = ($schedule->principal ?? 0) + ($schedule->interest ?? 0) + ($schedule->fee_amount ?? 0) + ($schedule->penalty_amount ?? 0);
            $totalPaid = $schedule->repayments->sum(function ($repayment) {
                return ($repayment->principal ?? 0) + ($repayment->interest ?? 0) + ($repayment->fee_amount ?? 0) + ($repayment->penalt_amount ?? 0);
            });
            $unpaid = max(0, round($totalDue - $totalPaid, 2));

            if ($unpaid <= 0) {
                continue;
            }

            $this->sendReminderSms($loan, $schedule, $unpaid);
            $remindersSent++;
        }

        Log::info("Repayment reminder job completed. Sent {$remindersSent} reminders for schedules due on: " . implode(', ', $targetDates));
    }

    private function sendReminderSms($loan, $schedule, float $unpaid): void
    {
        try {
            $customer = $loan->customer;
            if (!$customer || empty($customer->phone1)) {
                Log::warning("Cannot send reminder SMS - customer phone missing for loan {$loan->loanNo}");
                return;
            }

            $amount = number_format($unpaid, 2);
            $due = Carbon::parse($schedule->due_date);
            $dueDate = $due->format('d/m/Y');
            $daysUntil = Carbon::today()->diffInDays($due, false);
            
            // Determine reminder type and message
            if ($daysUntil === 3) {
                $reminderType = "Kumbusho la kwanza";
                $daysText = "siku 3 zijazo";
            } elseif ($daysUntil === 2) {
                $reminderType = "Kumbusho la pili";
                $daysText = "siku 2 zijazo";
            } elseif ($daysUntil <= 0) {
                $reminderType = "Kumbusho la mwisho";
                $daysText = "leo";
            } else {
                $reminderType = "Kumbusho";
                $daysText = "siku {$daysUntil} zijazo";
            }

            $templateVars = [
                'customer_name' => $customer->name,
                'amount'        => $amount,
                'days_overdue'  => '',
                'loan_no'       => $loan->loanNo,
                'due_date'      => $dueDate,
                'reminder_type' => $reminderType,
                'company_name'  => '',
                'company_phone' => '',
            ];
            $message = SmsHelper::resolveTemplate('loan_arrears_reminder', $templateVars)
                ?? "Habari {$customer->name}. {$reminderType} la malipo ya mkopo namba {$loan->loanNo}. Kiasi kinachodaiwa ni TZS {$amount}, tarehe ya mwisho ya malipo ni {$dueDate} ({$daysText}). Tafadhali lipa kwa wakati ili kuepuka faini.";

            $phone = normalize_phone_number($customer->phone1);
            SmsHelper::send($phone, $message, 'loan_arrears_reminder');
            Log::info("Reminder SMS sent to customer {$customer->id} for loan {$loan->loanNo}, schedule {$schedule->id}: TZS {$amount} ({$reminderType})");
        } catch (\Throwable $e) {
            Log::error("Failed to send repayment reminder SMS for loan {$loan->loanNo}: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('RepaymentReminderJob failed: ' . $exception->getMessage());
    }
}


