<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyInterestAccrual extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'loan_id',
        'accrual_date',
        'principal_balance',
        'interest_rate',
        'daily_interest_amount',
        'branch_id',
        'user_id',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
