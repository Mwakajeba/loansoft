<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    public const TYPE_REPAYMENT_REMINDER = 'repayment_reminder';

    protected $fillable = [
        'customer_id',
        'phone_number',
        'message',
        'sms_type',
        'response',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function reminderMeta(): array
    {
        if (!$this->response) {
            return [];
        }

        $decoded = json_decode($this->response, true);
        if (!is_array($decoded)) {
            return [];
        }

        return is_array($decoded['reminder_meta'] ?? null) ? $decoded['reminder_meta'] : [];
    }

    public function deliveryStatus(): string
    {
        if (!$this->response) {
            return 'unknown';
        }

        $decoded = json_decode($this->response, true);
        if (!is_array($decoded)) {
            return 'unknown';
        }

        if (!empty($decoded['skipped'])) {
            return 'skipped';
        }

        return !empty($decoded['success']) ? 'sent' : 'failed';
    }
}
