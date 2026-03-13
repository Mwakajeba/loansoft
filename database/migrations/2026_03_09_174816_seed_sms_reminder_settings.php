<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $settings = [
        [
            'key'         => 'sms_reminder_enabled',
            'value'       => 'true',
            'type'        => 'boolean',
            'group'       => 'notifications',
            'label'       => 'Enable Repayment Reminders',
            'description' => 'Send automatic SMS reminders to customers before their repayment due date.',
            'is_public'   => false,
        ],
        [
            'key'         => 'sms_reminder_3_days_before',
            'value'       => 'true',
            'type'        => 'boolean',
            'group'       => 'notifications',
            'label'       => 'Remind 3 Days Before Due Date',
            'description' => 'Send an SMS reminder 3 days before the repayment due date.',
            'is_public'   => false,
        ],
        [
            'key'         => 'sms_reminder_2_days_before',
            'value'       => 'true',
            'type'        => 'boolean',
            'group'       => 'notifications',
            'label'       => 'Remind 2 Days Before Due Date',
            'description' => 'Send an SMS reminder 2 days before the repayment due date.',
            'is_public'   => false,
        ],
        [
            'key'         => 'sms_reminder_1_day_before',
            'value'       => 'false',
            'type'        => 'boolean',
            'group'       => 'notifications',
            'label'       => 'Remind 1 Day Before Due Date',
            'description' => 'Send an SMS reminder 1 day before the repayment due date.',
            'is_public'   => false,
        ],
        [
            'key'         => 'sms_reminder_on_due_date',
            'value'       => 'true',
            'type'        => 'boolean',
            'group'       => 'notifications',
            'label'       => 'Remind on Due Date',
            'description' => 'Send an SMS reminder on the actual repayment due date.',
            'is_public'   => false,
        ],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->settings as $setting) {
            DB::table('system_settings')->insertOrIgnore(array_merge($setting, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        $keys = array_column($this->settings, 'key');
        DB::table('system_settings')->whereIn('key', $keys)->delete();
    }
};
