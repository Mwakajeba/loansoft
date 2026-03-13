<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccruedPenalty extends Model
{
    use HasFactory, LogsActivity;

    protected $table = 'accrued_penalties';

    protected $fillable = [
        'loan_id',
        'loan_schedule_id',
        'customer_id',
        'branch_id',
        'penalty_amount',
        'accrual_date',
        'penalty_type',
        'penalty_rate',
        'calculation_basis',
        'days_overdue',
        'journal_id',
        'posted_to_gl',
        'description',
        'user_id',
        'reversed_at',
        'reversal_journal_id',
    ];

    protected $casts = [
        'accrual_date' => 'date',
        'penalty_amount' => 'decimal:2',
        'penalty_rate' => 'decimal:4',
        'posted_to_gl' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    /**
     * Get the loan associated with this accrued penalty
     */
    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Get the loan schedule associated with this accrued penalty
     */
    public function loanSchedule()
    {
        return $this->belongsTo(LoanSchedule::class);
    }

    /**
     * Get the customer associated with this accrued penalty
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the branch associated with this accrued penalty
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the journal entry associated with this accrued penalty
     */
    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * Get the reversal journal entry associated with this accrued penalty
     */
    public function reversalJournal()
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    /**
     * Check if this penalty has been reversed
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * Get the user who created this accrued penalty
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the GL transactions for this accrued penalty
     */
    public function glTransactions()
    {
        return GlTransaction::where('transaction_type', 'Accrued Penalty')
            ->where('transaction_id', $this->id)
            ->get();
    }

    /**
     * Scope for filtering by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('accrual_date', [$startDate, $endDate]);
    }

    /**
     * Scope for filtering by loan
     */
    public function scopeForLoan($query, $loanId)
    {
        return $query->where('loan_id', $loanId);
    }

    /**
     * Scope for filtering by schedule
     */
    public function scopeForSchedule($query, $scheduleId)
    {
        return $query->where('loan_schedule_id', $scheduleId);
    }

    /**
     * Get total accrued penalty amount for a loan
     */
    public static function getTotalForLoan($loanId)
    {
        return self::where('loan_id', $loanId)->sum('penalty_amount');
    }

    /**
     * Get total accrued penalty amount for a schedule
     */
    public static function getTotalForSchedule($scheduleId)
    {
        return self::where('loan_schedule_id', $scheduleId)->sum('penalty_amount');
    }
}
