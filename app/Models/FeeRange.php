<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeRange extends Model
{
    use HasFactory;

    protected $fillable = [
        'fee_id',
        'from_amount',
        'to_amount',
        'amount',
        'order',
    ];

    protected $casts = [
        'from_amount' => 'decimal:2',
        'to_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'order' => 'integer',
    ];

    /**
     * Get the fee that owns this range
     */
    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    /**
     * Check if a loan amount falls within this range
     */
    public function containsAmount($loanAmount)
    {
        return $loanAmount >= $this->from_amount && $loanAmount <= $this->to_amount;
    }
}
