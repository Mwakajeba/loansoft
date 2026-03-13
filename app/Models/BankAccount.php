<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasFactory,LogsActivity;

    protected $table = 'bank_accounts';
    protected $fillable = [
        'chart_account_id', 
        'name', 
        'account_number',
        'branch_id',
        'is_all_branches'
    ];
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'branch_id' => 'integer',
        'is_all_branches' => 'boolean',
    ];

    /**
     * Get the chart account that owns the bank account.
     */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'chart_account_id');
    }

    /**
     * Get the branch this account is scoped to (when not is_all_branches).
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Get the GL transactions for this bank account.
     */
    public function glTransactions(): HasMany
    {
        return $this->hasMany(GlTransaction::class, 'chart_account_id', 'chart_account_id');
    }

    public function repaymente(){
        return $this->hasMany(Repayment::class,'bank_account_id');
    }

    /**
     * Calculate the current balance of the bank account.
     */
    public function getBalanceAttribute()
    {
        if (!$this->chart_account_id) {
            return 0;
        }

        $debits = $this->glTransactions()
            ->where('nature', 'debit')
            ->sum('amount');

        $credits = $this->glTransactions()
            ->where('nature', 'credit')
            ->sum('amount');

        // For bank accounts, debits increase balance, credits decrease balance
        // This is because bank accounts are asset accounts (debit to increase, credit to decrease)
        return $debits - $credits;
    }

    /**
     * Get formatted balance.
     */
    public function getFormattedBalanceAttribute()
    {
        return number_format($this->balance, 2);
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * Scope to filter bank accounts by user's assigned branches
     */
    public function scopeForUserBranches($query, $user = null)
    {
        $user = $user ?? auth()->user();
        if (!$user) {
            return $query->whereRaw('1 = 0'); // Return empty if no user
        }

        // Determine the active branch context (current branch or user's primary branch)
        $currentBranchId = function_exists('current_branch_id') ? current_branch_id() : null;
        if (!$currentBranchId) {
            $currentBranchId = $user->branch_id;
        }

        if (!$currentBranchId) {
            // If we don't know which branch is active, return no accounts
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($currentBranchId) {
            // 1) Accounts available to all branches
            $q->where('is_all_branches', true);

            // 2) Accounts explicitly scoped to the active branch via `branch_id`
            $q->orWhere('branch_id', $currentBranchId);
        });
    }
}