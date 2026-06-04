<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LoanSchedule extends Model
{
    use HasFactory, LogsActivity;
    protected $table = 'loan_schedules';
    protected $fillable = [
        'loan_id',
        'interest',
        'principal',
        'end_date',
        'end_grace_date',
        'end_pernalty_date',
        'customer_id',
        'due_date',
        'fee_amount',
        'penalty_amount',
        'accrued_interest',
        'status'
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function repayments()
    {
        return $this->hasMany(Repayment::class, 'loan_schedule_id');
    }


    /**
     * Get the total amount paid for this schedule
     */
    public function getPaidAmountAttribute()
    {
        return $this->repayments->sum(function ($repayment) {
            return $repayment->principal + $repayment->interest + $repayment->fee_amount + $repayment->penalt_amount;
        });
    }

    /**
     * Interest amount that counts toward total/remaining due: scheduled interest by default;
     * accrued_interest when the loan product uses daily accrual (daily / daily_bases).
     */
    public function getBalanceInterestComponentAttribute(): float
    {
        $scheduled = (float) ($this->interest ?? 0);
        $accrued = (float) ($this->accrued_interest ?? 0);

        if ($this->relationLoaded('loan') && $this->loan) {
            if ($this->loan->usesDailyInterestAccrual()) {
                return $accrued;
            }

            return max($scheduled, $accrued);
        }

        // Fallback when loan relation not loaded: accrued seeded at disbursement for as-expected products
        if ($accrued > 0 && abs($accrued - $scheduled) <= 0.02) {
            return max($scheduled, $accrued);
        }

        if ($accrued > 0 && $scheduled <= 0) {
            return $accrued;
        }

        return max($scheduled, $accrued);
    }

    /**
     * Get the remaining amount to be paid for this schedule
     */
    public function getRemainingAmountAttribute()
    {
        if (in_array($this->status, ['paid', 'cancelled', 'restructured'], true)) {
            return 0.0;
        }

        $totalDue = $this->principal + $this->balance_interest_component + $this->fee_amount + $this->penalty_amount;

        return max(0, $totalDue - $this->paid_amount);
    }

    /**
     * Expose schedule id as an accessor
     */
    public function getScheduleIdAttribute()
    {
        return $this->id;
    }

    /**
     * Alias accessor for remaining amount on the schedule
     */
    public function getRemainScheduleAttribute()
    {
        return $this->remaining_amount;
    }

    /**
     * Expose schedule date (due date) as an accessor
     */
    public function getScheduleDateAttribute()
    {
        return $this->due_date;
    }

    /**
     * Get the schedule number (position in the loan's schedule sequence)
     */
    public function getScheduleNumberAttribute()
    {
        return self::where('loan_id', $this->loan_id)
            ->where('due_date', '<=', $this->due_date)
            ->orderBy('due_date')
            ->count();
    }

    /**
     * Count of remaining schedules (including this one) from this schedule's due date onwards
     */
    public function getRemainingSchedulesCountAttribute()
    {
        // Fetch sibling schedules for the same loan from this due date onwards
        $siblingSchedules = self::with(['repayments', 'loan.product'])
            ->where('loan_id', $this->loan_id)
            ->whereDate('due_date', '>=', $this->due_date)
            ->get();

        return $siblingSchedules->filter(function ($schedule) {
            return $schedule->remaining_amount > 0;
        })->count();
    }

    /**
     * Total remaining amount across remaining schedules (including this one) from this schedule's due date onwards
     */
    public function getRemainingSchedulesAmountAttribute()
    {
        $siblingSchedules = self::with(['repayments', 'loan.product'])
            ->where('loan_id', $this->loan_id)
            ->whereDate('due_date', '>=', $this->due_date)
            ->get();

        return $siblingSchedules->sum(function ($schedule) {
            return $schedule->remaining_amount ?? 0;
        });
    }

    /**
     * Check if this schedule is fully paid
     */
    public function getIsFullyPaidAttribute()
    {
        return $this->remaining_amount <= 0;
    }

    /**
     * Get the total amount due for this schedule
     */
    public function getTotalDueAttribute()
    {
        return $this->principal + $this->balance_interest_component + $this->fee_amount + $this->penalty_amount;
    }

    /**
     * Get the percentage of payment completed
     */
    public function getPaymentPercentageAttribute()
    {
        if (in_array($this->status, ['paid', 'cancelled', 'restructured'], true)) {
            return 100;
        }

        $totalDue = $this->principal + $this->balance_interest_component + $this->fee_amount + $this->penalty_amount;
        if ($totalDue <= 0) {
            return 100;
        }

        return min(100, round(($this->paid_amount / $totalDue) * 100, 2));
    }

    /**
     * Check if the loan associated with this schedule is active
     */
    public function getIsLoanActiveAttribute()
    {
        return $this->loan && $this->loan->status === Loan::STATUS_ACTIVE;
    }

    /**
     * Check if the loan associated with this schedule is active (method version)
     */
    public function isLoanActive()
    {
        return $this->loan && $this->loan->status === Loan::STATUS_ACTIVE;
    }
    public function fullPrincipalPaid()
    {
        $totalPrincipalPaid = $this->repayments->sum('principal');
        return $totalPrincipalPaid >= $this->principal;
    }
    //checkif penalty is paid
    public function fullPenaltyPaid()
    {
        $totalPenaltyPaid = $this->repayments->sum('penalt_amount');
        return $totalPenaltyPaid >= $this->penalty_amount;
    }

    //penalty is paid
    public function PenaltyPaid(){
        return $this->repayments->sum('penalt_amount');
    }

    /**
     * Check if penalty removal is allowed
     * Penalty removal is only allowed when the paid amount is less than the penalty amount
     */
    public function isPenaltyRemovalAllowed()
    {
        $penaltyPaidAmount = $this->repayments ? $this->repayments->sum('penalt_amount') : 0;
        return $penaltyPaidAmount < $this->penalty_amount;
    }

    /**
     * Schedules due today with unpaid balance (for navbar / notifications).
     *
     * @return Collection<int, object{name: string, amount_due: float}>
     */
    public static function dueTodayNotifications(int $branchId, ?string $date = null): Collection
    {
        $dueDate = $date ?? Carbon::today()->toDateString();
        $threshold = Loan::OUTSTANDING_CLOSURE_THRESHOLD;

        return static::query()
            ->with(['customer:id,name', 'repayments', 'loan:id,status,branch_id', 'loan.product'])
            ->whereHas('loan', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->where('status', Loan::STATUS_ACTIVE);
            })
            ->whereDate('due_date', $dueDate)
            ->where(function ($query) {
                $query->whereNull('loan_schedules.status')
                    ->orWhereNotIn('loan_schedules.status', ['paid', 'cancelled', 'restructured']);
            })
            ->get()
            ->filter(fn (self $schedule) => $schedule->remaining_amount > $threshold)
            ->map(fn (self $schedule) => (object) [
                'name' => $schedule->customer->name ?? 'Unknown',
                'amount_due' => round((float) $schedule->remaining_amount, 2),
            ])
            ->values();
    }
}
