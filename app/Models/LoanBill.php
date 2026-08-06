<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanBill extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'loan_id',
        'amount',
        'paid_amount',
        'description',
        'bill_date',
        'receivable_account_id',
        'income_account_id',
        'status',
        'due_date',
        'created_by',
        'paid_at',
        'receipt_id',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'bill_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'receivable_account_id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'income_account_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, round((float) $this->amount - (float) $this->paid_amount, 2));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIAL], true)
            && $this->remaining_amount > 0;
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIAL])
            ->whereColumn('paid_amount', '<', 'amount');
    }

    public function applyPayment(float $amount): float
    {
        $amount = round(max(0, $amount), 2);
        if ($amount <= 0 || ! $this->isOpen()) {
            return 0;
        }

        $applied = min($amount, $this->remaining_amount);
        $this->paid_amount = round((float) $this->paid_amount + $applied, 2);

        if ($this->paid_amount + 0.001 >= (float) $this->amount) {
            $this->paid_amount = (float) $this->amount;
            $this->status = self::STATUS_PAID;
            $this->paid_at = now();
        } else {
            $this->status = self::STATUS_PARTIAL;
        }

        $this->save();

        return $applied;
    }
}
