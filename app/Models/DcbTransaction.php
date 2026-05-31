<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DcbTransaction extends Model
{
    use LogsActivity;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const REF_LOAN_DISBURSEMENT = 'loan_disbursement';

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'reference_type',
        'reference_id',
        'client_reference',
        'destination_account',
        'institution_code',
        'institution_name',
        'amount',
        'beneficiary_name',
        'msisdn',
        'sender_name',
        'purpose',
        'status',
        'transfer_reference',
        'response_code',
        'message',
        'gateway_response',
        'callback_payload',
        'meta',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'gateway_response' => 'array',
        'callback_payload' => 'array',
        'meta' => 'array',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markSubmitted(array $gatewayResponse): void
    {
        $data = $gatewayResponse['data'] ?? [];

        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'gateway_response' => $gatewayResponse,
            'transfer_reference' => $data['transferReference'] ?? $data['transfer_reference'] ?? $this->transfer_reference,
            'response_code' => $data['responseCode'] ?? $data['response_code'] ?? null,
            'message' => $data['message'] ?? ($gatewayResponse['message'] ?? null),
            'submitted_at' => now(),
        ]);
    }

    public function markSuccess(array $payload): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'callback_payload' => $payload,
            'transfer_reference' => $payload['transferReference']
                ?? $payload['transfer_reference']
                ?? $this->transfer_reference,
            'response_code' => $payload['responseCode'] ?? $payload['response_code'] ?? $this->response_code,
            'message' => $payload['message'] ?? $this->message,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(?string $message = null, ?array $payload = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'message' => $message ?? $this->message,
            'callback_payload' => $payload ?? $this->callback_payload,
            'completed_at' => now(),
        ]);
    }
}
