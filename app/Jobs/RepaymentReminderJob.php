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

        $schedules = LoanSchedule::with(['loan.product', 'loan.customer', 'loan.branch.company', 'repayments'])
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
            $daysUntil = (int) Carbon::today()->diffInDays($due, false);

            // Reminder labels + phrasing for SMS
            if ($daysUntil === 3) {
                $reminderType = 'Kumbusho la kwanza';
            } elseif ($daysUntil === 2) {
                $reminderType = 'Kumbusho la pili';
            } elseif ($daysUntil === 1) {
                $reminderType = 'Kumbusho';
            } elseif ($daysUntil <= 0) {
                $reminderType = 'Kumbusho la mwisho';
            } else {
                $reminderType = 'Kumbusho';
            }

            // Full phrase for built-in fallback: (siku 2 zijazo) or (leo)
            $daysPhraseForSms = $daysUntil <= 0
                ? 'leo'
                : 'siku ' . $daysUntil . ' zijazo';

            // Template {days_overdue}: omit trailing "zijazo" so custom templates like
            // "... ({days_overdue} zijazo)" do not become "siku 2 zijazo zijazo".
            $daysOverdueForTemplate = $daysUntil <= 0 ? 'leo' : 'siku ' . $daysUntil;

            // Resolve company name and phone: branch → company, then customer company, then current_company()
            $company = $loan->branch && $loan->branch->company ? $loan->branch->company : null;
            if (!$company && $customer->company_id) {
                $company = \App\Models\Company::find($customer->company_id);
            }
            if (!$company && function_exists('current_company')) {
                $company = current_company();
            }
            $companyName = $company ? $company->name : 'SMARTFINANCE';
            $companyPhone = $company ? ($company->phone ?? '') : '';

            // Ensure placeholders always get a string (SmsHelper uses str_replace; empty or non-string can break)
            $templateVars = [
                'customer_name' => (string) ($customer->name ?? ''),
                'amount'        => (string) $amount,
                'days_overdue'  => (string) $daysOverdueForTemplate,
                'loan_no'       => (string) ($loan->loanNo ?? ''),
                'due_date'      => (string) $dueDate,
                'reminder_type' => (string) $reminderType,
                'company_name'  => (string) $companyName,
                'company_phone' => (string) $companyPhone,
            ];
            $message = SmsHelper::resolveTemplate('loan_arrears_reminder', $templateVars)
                ?? "Habari {$customer->name}. {$reminderType} la malipo ya mkopo namba {$loan->loanNo}. Kiasi kinachodaiwa ni TZS {$amount}, tarehe ya mwisho ya malipo ni {$dueDate} ({$daysPhraseForSms}). Tafadhali lipa kwa wakati ili kuepuka faini.";

            // Templates often use "({days_overdue} zijazo)"; when due is today, {days_overdue} is "leo" — drop the stray " zijazo".
            $message = preg_replace('/\(leo\s+zijazo\)/u', '(leo)', $message);

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


