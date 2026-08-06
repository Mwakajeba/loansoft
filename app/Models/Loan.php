<?php

namespace App\Models;

use App\Services\PenaltyAccrualService;
use App\Support\Loans\LoanRounding;
use App\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Optional if you want soft deletes
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class Loan extends Model
{
    // Uncomment if using soft deletes
    // use SoftDeletes;
    use HasFactory, LogsActivity;

    protected $fillable = [
        'customer_id',
        'group_id',
        'product_id',
        'amount',
        'interest',
        'interest_amount',
        'period',
        'amount_total',
        'bank_account_id',
        'date_applied',
        'disbursed_on',
        'status',
        'sector',
        'interest_cycle',
        'loan_officer_id',
        'loanNo',
        'top_up_id',
        'first_repayment_date',
        'last_repayment_date',
        'branch_id',
        'custom_fee_amounts',
    ];

    protected $casts = [
        'custom_fee_amounts' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'total_amount_to_settle',
        'total_principal_paid',
        'total_interest_paid'
    ];

    // Loan status constants
    const STATUS_APPLIED = 'applied';
    const STATUS_CHECKED = 'checked';
    const STATUS_APPROVED = 'approved';
    const STATUS_AUTHORIZED = 'authorized';
    const STATUS_ACTIVE = 'active';
    const STATUS_REJECTED = 'rejected';
    const STATUS_DEFAULTED = 'defaulted';
    const STATUS_COMPLETE = 'completed';
    const STATUS_RESTRUCTURED = 'restructured';

    /** Outstanding balance below this amount (TZS) means the loan is fully settled. */
    public const OUTSTANDING_CLOSURE_THRESHOLD = 20.0;

    /** Days in arrears above this threshold classify a loan as defaulted (portfolio loss bucket). */
    public const DEFAULTED_ARREARS_DAYS_THRESHOLD = 90;

    public static function isNegligibleBalance(float $amount): bool
    {
        return $amount <= self::OUTSTANDING_CLOSURE_THRESHOLD;
    }

    public static function hasCollectibleBalance(float $amount): bool
    {
        return $amount > self::OUTSTANDING_CLOSURE_THRESHOLD;
    }

    /** Statuses that cannot be edited or deleted (disbursed or post-disbursement). */
    const MODIFICATION_LOCKED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DEFAULTED,
        self::STATUS_COMPLETE,
        self::STATUS_RESTRUCTURED,
    ];

    /** Active/defaulted loans may be edited when the user has the "edit loan" permission. */
    const PERMISSION_EDITABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DEFAULTED,
    ];

    /** Application / approval pipeline statuses (before disbursement). */
    const APPLICATION_WORKFLOW_STATUSES = [
        self::STATUS_APPLIED,
        self::STATUS_CHECKED,
        self::STATUS_APPROVED,
        self::STATUS_AUTHORIZED,
        self::STATUS_REJECTED,
    ];

    /** @deprecated Use APPLICATION_WORKFLOW_STATUSES */
    const APPLICATION_EDITABLE_STATUSES = self::APPLICATION_WORKFLOW_STATUSES;

    /**
     * Loan created via direct entry (loans/create) or import — not the application workflow.
     */
    public function isDirectLoan(): bool
    {
        if ($this->approvals()->exists()) {
            return false;
        }

        // Application loans get bank_account_id only after approval/disbursement
        if ($this->hasDisbursementBankAccount()) {
            return true;
        }

        // Bulk import may set bank account after create; active + no approvals = direct entry
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Resolve bank_account_id even when the model was loaded with a partial select (e.g. DataTables).
     */
    protected function hasDisbursementBankAccount(): bool
    {
        if (!array_key_exists('bank_account_id', $this->attributes)) {
            if (!$this->exists) {
                return false;
            }

            $this->setAttribute(
                'bank_account_id',
                static::whereKey($this->getKey())->value('bank_account_id')
            );
        }

        return $this->bank_account_id !== null;
    }

    /**
     * Loan created via the application workflow (loans/application).
     */
    public function isApplicationLoan(): bool
    {
        return in_array($this->status, self::APPLICATION_WORKFLOW_STATUSES, true)
            && !$this->isDirectLoan();
    }

    /**
     * Completed / restructured loans must never be edited or deleted.
     */
    public function isPermanentlyLocked(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_RESTRUCTURED], true);
    }

    public function canBeEdited(?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (!$user?->can('edit loan')) {
            return false;
        }

        if ($this->isPermanentlyLocked()) {
            return false;
        }

        // Direct loans (create/import) stay editable while active — e.g. fix data before repayments
        if ($this->isDirectLoan()) {
            return true;
        }

        if (in_array($this->status, self::PERMISSION_EDITABLE_STATUSES, true)) {
            return true;
        }

        if (in_array($this->status, self::MODIFICATION_LOCKED_STATUSES, true)) {
            return false;
        }

        return $this->isApplicationLoan();
    }

    public function canBeDeleted(?User $user = null): bool
    {
        $user = $user ?? auth()->user();

        if (!$user?->can('delete loan')) {
            return false;
        }

        if ($this->isPermanentlyLocked()) {
            return false;
        }

        if (! $user->hasRole('super-admin')
            && $this->status === self::STATUS_ACTIVE
            && $this->repayments()->exists()) {
            return false;
        }

        if ($this->isDirectLoan()) {
            return true;
        }

        if ($this->isApplicationLoan() || $this->status === self::STATUS_REJECTED) {
            return true;
        }

        // Disbursed application loans (active/defaulted).
        if ($this->followedApplicationWorkflow()
            && in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_DEFAULTED], true)) {
            return true;
        }

        if (in_array($this->status, self::MODIFICATION_LOCKED_STATUSES, true)) {
            return false;
        }

        return false;
    }

    public function usesApplicationEditForm(): bool
    {
        return $this->isApplicationLoan();
    }

    protected static function boot()
    {
        parent::boot();

        // Ensure a provisional loanNo exists to satisfy NOT NULL before insert
        static::creating(function ($loan) {
            if (empty($loan->loanNo)) {
                $loan->loanNo = 'TMP-' . uniqid();
            }
        });

        // Set loan number AFTER the record has an ID to avoid heavy queries/loops
        static::created(function ($loan) {
            $startNumber = 1000000;
            $loan->loanNo = 'SF-' . ($startNumber + (int) $loan->id);
            // Save quietly to avoid triggering observers again
            $loan->saveQuietly();
        });
    }


    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function product()
    {
        return $this->belongsTo(LoanProduct::class, 'product_id');
    }

    /**
     * Schedule "interest" component for balances uses accrued_interest only for these products
     * (same rule as CalculateDailyInterestJob).
     */
    public function usesDailyInterestAccrual(): bool
    {
        $product = $this->product;
        if (!$product && $this->product_id) {
            $product = $this->product()->first();
        }
        if (!$product) {
            return false;
        }

        return $product->usesDailyInterestAccrual();
    }

    public function usesAsExpectedInterestAccrual(): bool
    {
        $product = $this->product;
        if (!$product && $this->product_id) {
            $product = $this->product()->first();
        }

        return $product ? $product->usesAsExpectedInterestAccrual() : false;
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function topUpLoan()
    {
        return $this->belongsTo(Loan::class, 'top_up_id');
    }

    public function topUpChildren()
    {
        return $this->hasMany(Loan::class, 'top_up_id');
    }

    public function schedule()
    {
        return $this->hasMany(LoanSchedule::class, 'loan_id');
    }

    public function bills()
    {
        return $this->hasMany(LoanBill::class, 'loan_id');
    }

    public function repayments()
    {
        return $this->hasMany(Repayment::class, 'loan_id');
    }

    public function loanFiles()
    {
        return $this->hasMany(LoanFile::class, 'loan_id');
    }

    public function collaterals()
    {
        return $this->hasMany(\App\Models\LoanCollateral::class, 'loan_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function guarantors()
    {
        return $this->belongsToMany(Customer::class, 'loan_guarantor')
            ->withPivot('relation')
            ->withTimestamps();
    }

    public function loanOfficer()
    {
        return $this->belongsTo(User::class);
    }

    // New approval relationships
    public function approvals()
    {
        return $this->hasMany(LoanApproval::class);
    }

    public function currentApproval()
    {
        return $this->approvals()->latest()->first();
    }

    // Dynamic approval methods based on roles
    public function getApprovalRoles()
    {
        if (!$this->product || !$this->product->has_approval_levels) {
            return [];
        }

        $roles = is_array($this->product->approval_levels) ? $this->product->approval_levels : explode(",", $this->product->approval_levels);
        $filteredRoles = array_filter($roles); // Remove empty values

        // Convert to integers for proper comparison
        return array_map('intval', $filteredRoles);
    }

    public function getCurrentApprovalLevel()
    {
        $lastApproval = $this->currentApproval();
        return $lastApproval ? $lastApproval->approval_level : 0;
    }

    public function getNextApprovalLevel()
    {
        $approvalRoles = $this->getApprovalRoles();
        if (empty($approvalRoles)) {
            return null;
        }

        $currentLevel = $this->getCurrentApprovalLevel();
        return $currentLevel < count($approvalRoles) ? $currentLevel + 1 : null;
    }

    public function getRequiredApprovalLevels()
    {
        return $this->getApprovalRoles();
    }

    public function getNextApprovalRole()
    {
        $approvalRoles = $this->getApprovalRoles();
        $nextLevel = $this->getNextApprovalLevel();

        if (!$nextLevel || $nextLevel > count($approvalRoles)) {
            return null;
        }

        return $approvalRoles[$nextLevel - 1];
    }

    public function canBeApprovedByUser($user)
    {
        $nextRoleId = $this->getNextApprovalRole();
        if (!$nextRoleId) {
            return false;
        }

        $userRoles = $user->roles->pluck('id')->toArray();
        return in_array($nextRoleId, $userRoles);
    }

    public function hasUserApproved($user)
    {
        return $this->approvals()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function canBeRejected()
    {
        $rejectableStatuses = [self::STATUS_APPLIED, self::STATUS_CHECKED, self::STATUS_APPROVED];
        return in_array($this->status, $rejectableStatuses);
    }

    public function isFullyApproved()
    {
        $approvalRoles = $this->getApprovalRoles();
        if (empty($approvalRoles)) {
            return false;
        }

        $requiredLevels = count($approvalRoles);
        $approvedLevels = $this->approvals()->where('action', '!=', 'rejected')->count();

        return $approvedLevels >= $requiredLevels;
    }

    public function isReadyForDisbursement()
    {
        $approvalRoles = $this->getApprovalRoles();
        if (empty($approvalRoles)) {
            return $this->status === self::STATUS_ACTIVE;
        }

        // Check if all levels except the last (accountant) are approved
        $requiredLevels = count($approvalRoles);
        $approvedLevels = $this->approvals()->where('action', '!=', 'rejected')->count();

        return $approvedLevels >= ($requiredLevels - 1); // All except accountant
    }

    public function getApprovalStatus()
    {
        if ($this->status === self::STATUS_REJECTED) {
            return 'rejected';
        }

        if ($this->status === self::STATUS_ACTIVE) {
            return 'disbursed';
        }

        $approvalRoles = $this->getApprovalRoles();
        if (empty($approvalRoles)) {
            return $this->status;
        }

        $currentLevel = $this->getCurrentApprovalLevel();
        $totalLevels = count($approvalRoles);

        if ($currentLevel === 0) {
            return 'pending_first_approval';
        }

        if ($currentLevel < $totalLevels) {
            $roleName = $this->getRoleNameById($approvalRoles[$currentLevel]);
            return "pending_{$roleName}_approval";
        }

        return 'fully_approved';
    }

    public function getRoleNameById($roleId)
    {
        $role = \App\Models\Role::find($roleId);
        return $role ? strtolower(str_replace(' ', '_', $role->name)) : 'unknown';
    }

    public function getNextApprovalAction()
    {
        $approvalRoles = $this->getApprovalRoles();
        $nextLevel = $this->getNextApprovalLevel();

        if (!$nextLevel) {
            return null;
        }

        $roleId = $approvalRoles[$nextLevel - 1];
        $role = \App\Models\Role::find($roleId);

        if (!$role) {
            return null;
        }

        $totalLevels = count($approvalRoles);

        // If last level → disburse
        if ($nextLevel === $totalLevels) {
            return 'disburse';
        }

        // Map flows by total level count
        // 2 levels: Approve → Disburse
        if ($totalLevels === 2) {
            return 'approve';
        }

        // 3 levels: Check → Approve → Disburse
        if ($totalLevels === 3) {
            return $nextLevel === 1 ? 'check' : 'approve';
        }

        // 4+ levels: Checked → Approved → Authorized → Disbursed (intermediate levels default to authorize)
        if ($nextLevel === 1) {
            return 'check';
        }
        if ($nextLevel === 2) {
            return 'approve';
        }
        // level 3 and any additional middle levels before last
        return 'authorize';
    }

    public function getApprovalLevelName($level)
    {
        $approvalRoles = $this->getApprovalRoles();
        if (!isset($approvalRoles[$level - 1])) {
            return 'Unknown';
        }

        $roleId = $approvalRoles[$level - 1];
        $role = \App\Models\Role::find($roleId);

        return $role ? $role->name : 'Unknown';
    }


    public function calculateInterestAmount(?float $rate = null, bool $returnSchedule = false): float|array
    {
        $product = $this->product;
        if (!$product)
            return $returnSchedule ? [] : 0;

        $principal = $this->amount;
        $rate = $rate ?? $this->interest ?? $product->interest ?? 0;

        $period = $this->period;
        $method = $product->interest_method ?? 'flat_rate';

        $ratePerPeriod = $rate / 100;

        $schedule = [];
        $interestAmount = 0;

        switch ($method) {
            case 'flat_rate':
                $interestAmount = $principal * $ratePerPeriod * $period; // Flat rate: interest on principal only
                if ($returnSchedule) {
                    $monthlyPrincipal = $principal / $period;
                    $monthlyInterest = $interestAmount / $period;
                    for ($i = 1; $i <= $period; $i++) {
                        $schedule[] = [
                            'principal' => LoanRounding::roundAmount($monthlyPrincipal),
                            'interest' => LoanRounding::roundAmount($monthlyInterest),
                            'total' => LoanRounding::roundAmount($monthlyPrincipal + $monthlyInterest),
                        ];
                    }
                }
                break;

            case 'reducing_balance_with_equal_installment':
                $r = $ratePerPeriod;
                $n = $period;
                $P = $principal;

                $emi = ($P * $r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);
                $totalPayable = $emi * $n;
                $interestAmount = $totalPayable - $P;

                if ($returnSchedule) {
                    $balance = $P;
                    for ($i = 1; $i <= $n; $i++) {
                        $interest = $balance * $r;
                        $principalPart = $emi - $interest;
                        $balance -= $principalPart;

                        $schedule[] = [
                            'principal' => LoanRounding::roundAmount($principalPart),
                            'interest' => LoanRounding::roundAmount($interest),
                            'total' => LoanRounding::roundAmount($emi),
                        ];
                    }
                }
                break;

            case 'reducing_balance_with_equal_principal':
                $monthlyPrincipal = $principal / $period;
                $balance = $principal;
                $totalInterest = 0;

                if ($returnSchedule) {
                    for ($i = 1; $i <= $period; $i++) {
                        $interest = $balance * $ratePerPeriod;
                        $totalInterest += $interest;
                        $schedule[] = [
                            'principal' => LoanRounding::roundAmount($monthlyPrincipal),
                            'interest' => LoanRounding::roundAmount($interest),
                            'total' => LoanRounding::roundAmount($monthlyPrincipal + $interest),
                        ];
                        $balance -= $monthlyPrincipal;
                    }
                } else {
                    for ($i = 1; $i <= $period; $i++) {
                        $interest = $balance * $ratePerPeriod;
                        $totalInterest += $interest;
                        $balance -= $monthlyPrincipal;
                    }
                }

                $interestAmount = $totalInterest;
                break;

            default:
                $interestAmount = $principal * $ratePerPeriod; // Flat rate: interest on principal only
                break;
        }

        if ($returnSchedule && count($schedule) > 0) {
            // Reconcile rounding drift on the last row so sums match principal + computed interest amount.
            $sumPrincipal = 0.0;
            $sumInterest = 0.0;
            foreach ($schedule as $row) {
                $sumPrincipal += (float) ($row['principal'] ?? 0);
                $sumInterest += (float) ($row['interest'] ?? 0);
            }

            $lastIndex = count($schedule) - 1;
            $schedule[$lastIndex]['principal'] = round((float) $schedule[$lastIndex]['principal'] + ($principal - $sumPrincipal), 2);
            $schedule[$lastIndex]['interest'] = round((float) $schedule[$lastIndex]['interest'] + ($interestAmount - $sumInterest), 2);
            $schedule[$lastIndex]['total'] = round((float) $schedule[$lastIndex]['principal'] + (float) $schedule[$lastIndex]['interest'], 2);
        }

        return $returnSchedule ? $schedule : round($interestAmount, 2);
    }



    public function getRepaymentDates()
    {
        $cycle = $this->interest_cycle; // e.g., monthly, weekly
        $period = $this->period;
        $disbursedOn = Carbon::parse($this->disbursed_on);

        // 1. Get first repayment date
        switch (strtolower($cycle)) {
            case 'daily':
                $first = $disbursedOn->copy()->addDay();
                $last = $first->copy()->addDays($period - 1);
                break;

            case 'weekly':
                $first = $disbursedOn->copy()->addWeek();
                $last = $first->copy()->addWeeks($period - 1);
                break;

            case 'bimonthly':
                $first = $disbursedOn->copy()->addMonths(2);
                $last = $first->copy()->addMonths(2 * ($period - 1));
                break;

            case 'monthly':
                $first = $disbursedOn->copy()->addMonth();
                $last = $first->copy()->addMonths($period - 1);
                break;

            case 'quarterly':
                $first = $disbursedOn->copy()->addMonths(3);
                $last = $first->copy()->addMonths(3 * ($period - 1));
                break;

            case 'semi_annually':
                $first = $disbursedOn->copy()->addMonths(6);
                $last = $first->copy()->addMonths(6 * ($period - 1));
                break;

            case 'annually':
                $first = $disbursedOn->copy()->addYear();
                $last = $first->copy()->addYears($period - 1);
                break;

            default:
                // fallback: monthly
                $first = $disbursedOn->copy()->addMonth();
                $last = $first->copy()->addMonths($period - 1);
        }

        return [
            'first_repayment_date' => $first->toDateString(),
            'last_repayment_date' => $last->toDateString(),
        ];
    }

    /**
     * Resolve first/last repayment dates. If $firstRepaymentOverride is set, last date is derived from
     * the same stepping rules as generateRepaymentSchedule; otherwise dates follow disbursement + cycle.
     */
    public function resolveRepaymentDates(?string $firstRepaymentOverride = null): array
    {
        $override = $firstRepaymentOverride ? trim($firstRepaymentOverride) : null;
        if ($override === '' || $override === null) {
            return $this->getRepaymentDates();
        }

        $first = Carbon::parse($override)->startOfDay();
        $disbursed = Carbon::parse($this->disbursed_on ?? $this->date_applied)->startOfDay();
        if ($first->lt($disbursed)) {
            throw new \InvalidArgumentException('First repayment date cannot be before the disbursement date.');
        }

        $method = $this->getDateIncrementMethod();
        $idx = max(0, (int) $this->period - 1);
        $last = $first->copy()->{$method}($this->getDateIncrementValue($idx));

        return [
            'first_repayment_date' => $first->toDateString(),
            'last_repayment_date' => $last->toDateString(),
        ];
    }

    /**
     * Get the date increment method based on interest cycle
     */
    public function getDateIncrementMethod(): string
    {
        $cycle = strtolower($this->interest_cycle);

        switch ($cycle) {
            case 'daily':
                return 'addDay';
            case 'weekly':
                return 'addWeek';
            case 'bimonthly':
                return 'addMonths';
            case 'monthly':
                return 'addMonth';
            case 'quarterly':
                return 'addMonths';
            case 'semi_annually':
                return 'addMonths';
            case 'annually':
                return 'addYear';
            default:
                return 'addMonth';
        }
    }

    /**
     * Get the date increment value based on interest cycle
     */
    public function getDateIncrementValue(int $index): int
    {
        $cycle = strtolower($this->interest_cycle);

        switch ($cycle) {
            case 'daily':
                return $index;
            case 'weekly':
                return $index;
            case 'bimonthly':
                return $index * 2; // 2 months per bi-monthly period
            case 'monthly':
                return $index;
            case 'quarterly':
                return $index * 3; // 3 months per quarter
            case 'semi_annually':
                return $index * 6; // 6 months per semi-annual period
            case 'annually':
                return $index;
            default:
                return $index;
        }
    }

    public function generateRepaymentSchedule(float $rate)
    {
        $product = $this->product;
        if (!$product)
            return;

        $principal = $this->amount;
        $interestAmount = $this->interest_amount;
        $period = $this->period;
        $method = strtolower($product->interest_method ?? 'flat_rate');
        $startDate = Carbon::parse($this->first_repayment_date);
        $gracePeriod = $product->grace_period ?? 0;

        // For opening balance/imported loans, do not charge product fees at all
        $isOpeningBalance = $this->created_at && $this->created_at->diffInSeconds($this->disbursed_on ?? $this->date_applied ?? $this->created_at) < 5
            && $this->status === self::STATUS_ACTIVE
            && $this->loanNo && str_starts_with((string) $this->loanNo, 'SF-') === false;

        $fees = $isOpeningBalance ? [] : $product->getFeesAttribute();
        \Log::info('[LoanSchedule] Fees: ' . json_encode($fees) . ' | is_opening_balance=' . ($isOpeningBalance ? 'true' : 'false'));

        $isReducing = in_array($method, [
            'reducing_balance_with_equal_installment',
            'reducing_balance_with_equal_principal'
        ]);

        $schedule = $isReducing
            ? $this->calculateInterestAmount($rate, true)
            : array_fill(0, $period, [
                'principal' => LoanRounding::roundAmount($principal / $period),
                'interest' => LoanRounding::roundAmount($interestAmount / $period),
            ]);

        if (!empty($schedule)) {
            // Reconcile rounding drift (esp. for step rounding) on the last row.
            $sumP = 0.0;
            $sumI = 0.0;
            foreach ($schedule as $row) {
                $sumP += (float) ($row['principal'] ?? 0);
                $sumI += (float) ($row['interest'] ?? 0);
            }
            $last = count($schedule) - 1;
            $schedule[$last]['principal'] = round((float) $schedule[$last]['principal'] + ($principal - $sumP), 2);
            $schedule[$last]['interest'] = round((float) $schedule[$last]['interest'] + ($interestAmount - $sumI), 2);
        }


        // === Fees on release date ===
        // For opening balance/imported loans we skip creating any release-date fee journals/GL
        if (!$isOpeningBalance) {
            $product = $this->product;
            $bankAccountId = $this->bank_account_id;
            $bankAccount = $bankAccountId ? \App\Models\BankAccount::find($bankAccountId) : null;
            $bankChartAccountId = $bankAccount ? $bankAccount->chart_account_id : null;

            $releaseFeeIds = [];
            if ($product && $product->fees_ids) {
                $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
                if (is_array($feeIds)) {
                    $releaseFeeIds = \DB::table('fees')
                        ->whereIn('id', $feeIds)
                        ->where('deduction_criteria', 'charge_fee_on_release_date')
                        ->where('status', 'active')
                        ->pluck('id')
                        ->toArray();
                }
                Log::info('fee ids >>>>>>>>>>>>>: ' . json_encode($releaseFeeIds));
            }

            if (!empty($releaseFeeIds) && $bankChartAccountId) {
                $releaseFees = \DB::table('fees')->whereIn('id', $releaseFeeIds)->get();
                foreach ($releaseFees as $releaseFee) {
                    $feeName = $releaseFee->name;
                    $chartAccountId = $releaseFee->chart_account_id;

                    if ($chartAccountId) {
                        $feeModel = \App\Models\Fee::find($releaseFee->id);
                        $totalFeeFloat = $feeModel
                            ? $feeModel->monetaryAmountForPrincipal((float) $principal, $this->custom_fee_amounts)
                            : 0;

                        // Create journal and GL transaction for release fee
                        $journal = \App\Models\Journal::create([
                            'reference' => $this->id,
                            'reference_type' => 'Loan Disbursement',
                            'customer_id' => $this->customer_id,
                            'description' => "{$feeName}  Fee for loan #{$this->id}",
                            'branch_id' => $this->branch_id,
                            'user_id' => auth()->id() ?? 1,
                            'date' => $this->disbursed_on,
                        ]);

                        // Credit fee income account
                        \App\Models\JournalItem::create([
                            'journal_id' => $journal->id,
                            'chart_account_id' => $chartAccountId,
                            'amount' => $totalFeeFloat,
                            'description' => "{$feeName}  for loan #{$this->id}",
                            'nature' => 'credit',
                        ]);
                        // Debit bank account chart account
                        \App\Models\JournalItem::create([
                            'journal_id' => $journal->id,
                            'chart_account_id' => $bankChartAccountId,
                            'amount' => $totalFeeFloat,
                            'description' => "{$feeName} for loan #{$this->id}",
                            'nature' => 'debit',
                        ]);

                        \App\Models\GlTransaction::create([
                            'chart_account_id' => $chartAccountId,
                            'customer_id' => $this->customer_id,
                            'amount' => $totalFeeFloat,
                            'nature' => 'credit',
                            'transaction_id' => $this->id,
                            'transaction_type' => 'Loan Disbursement',
                            'date' => $this->disbursed_on,
                            'description' => "{$feeName}  for loan #{$this->id}",
                            'branch_id' => $this->branch_id,
                            'user_id' => auth()->id() ?? 1,
                        ]);
                    }
                }
            }
        }

        // Accrual Interest Calculation Method:
        // - as_expected_interest: seed accrued_interest = scheduled interest
        // - otherwise: keep accrued_interest = 0 (daily accrual will build it up later)
        // Backward compatibility: legacy value 'full_amount' is treated as 'as_expected_interest'
        $seedAccruedInterest = $product->usesAsExpectedInterestAccrual();

        foreach ($schedule as $i => $row) {
            $dueDate = $startDate->copy()->{$this->getDateIncrementMethod()}($this->getDateIncrementValue($i));
            $endDate = $dueDate->copy()->addDays(5);
            $endGraceDate = $dueDate->copy()->addDays($gracePeriod);

            // === Fees ===
            $loanFee = 0;
            if (!empty($fees) && is_iterable($fees)) {
                foreach ($fees as $fee) {
                    $criteria = $fee->deduction_criteria;
                    $includeInSchedule = $fee->include_in_schedule;
                    $status = $fee->status;

                    \Log::info('[LoanSchedule] Repayment #' . $i . ' Fee ID: ' . $fee->id . ' include_in_schedule: ' . ($includeInSchedule ? 'true' : 'false') . ', status: ' . $status);

                    if ($includeInSchedule && $status === 'active') {
                        $totalFeeFloat = $fee->monetaryAmountForPrincipal((float) $principal, $this->custom_fee_amounts);

                        $feeValue = 0;
                        switch ($criteria) {
                            case 'distribute_fee_evenly_to_all_repayments':
                                // Spread the total fee evenly across all installments
                                $feeValue = round($totalFeeFloat / max(1, $period), 2);
                                break;

                            case 'charge_same_fee_to_all_repayments':
                                // Charge the same fee amount on every installment (no division)
                                $feeValue = round($totalFeeFloat, 2);
                                break;

                            case 'charge_fee_on_first_repayment':
                                $feeValue = $i === 0 ? round($totalFeeFloat, 2) : 0;
                                break;

                            case 'charge_fee_on_last_repayment':
                                $feeValue = $i === ($period - 1) ? round($totalFeeFloat, 2) : 0;
                                break;

                            case 'charge_fee_on_release_date':
                                $feeValue = 0; // Already handled above
                                break;

                            case 'do_not_include_in_loan_schedule':
                            default:
                                $feeValue = 0; // Not applied on schedule rows
                                break;
                        }

                        $loanFee += $feeValue;
                        \Log::info('[LoanSchedule] Repayment #' . $i . ' Fee criteria: ' . $criteria . ' Applied amount: ' . $feeValue);
                    }
                }
            }

            LoanSchedule::create([
                'loan_id' => $this->id,
                'customer_id' => $this->customer_id,
                'due_date' => $dueDate,
                'end_date' => $endDate,
                'end_grace_date' => $endGraceDate,
                'principal' => $row['principal'],
                'interest' => $row['interest'],
                'fee_amount' => $loanFee,
                'penalty_amount' => 0,
                'accrued_interest' => $seedAccruedInterest ? (float) $row['interest'] : 0,
            ]);
        }
    }
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'reference')
            ->where('reference_type', 'loan');
    }

    /**
     * Calculate the total amount in arrears (overdue amount)
     */
    private function suppressCollectionsState(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_RESTRUCTURED], true);
    }

    public function getArrearsAmountAttribute()
    {
        if ($this->suppressCollectionsState()) {
            return 0;
        }

        $today = Carbon::now();
        $totalArrears = 0;

        foreach ($this->schedule as $scheduleItem) {
            // Exclude restructured schedules from arrears calculation
            if ($scheduleItem->status === 'restructured') {
                continue;
            }

            $dueDate = Carbon::parse($scheduleItem->due_date);

            // If the due date has passed and there's a remaining amount
            if ($dueDate->lt($today) && $scheduleItem->remaining_amount > 0) {
                $totalArrears += $scheduleItem->remaining_amount;
            }
        }

        return $totalArrears;
    }

    /**
     * Calculate the number of days in arrears (days since first overdue payment)
     */
    public function getDaysInArrearsAttribute()
    {
        if ($this->suppressCollectionsState()) {
            return 0;
        }

        $today = Carbon::now();
        $firstOverdueDate = null;

        foreach ($this->schedule->sortBy('due_date') as $scheduleItem) {
            // Exclude restructured schedules from arrears calculation
            if ($scheduleItem->status === 'restructured') {
                continue;
            }

            $dueDate = Carbon::parse($scheduleItem->due_date);

            // If the due date has passed and there's a remaining amount
            if ($dueDate->lt($today) && $scheduleItem->remaining_amount > 0) {
                $firstOverdueDate = $dueDate;
                break; // We found the first overdue date
            }
        }

        if ($firstOverdueDate) {
            return round($firstOverdueDate->diffInDays($today));
        }

        return 0; // No arrears
    }

    /**
     * Check if the loan is in arrears
     */
    public function getIsInArrearsAttribute()
    {
        return $this->arrears_amount > 0;
    }

    /**
     * Check if the loan is eligible for top-up based on product settings
     *
     * @return bool
     */
    public function isEligibleForTopUp(): bool
    {
        $product = $this->product;

        // Step 1: Check if product exists and top-up is allowed
        if (!$product || !$product->top_up_type || !$product->top_up_type_value) {
            info('Top-up eligibility check failed: Product or top-up settings not found', [
                'loan_id' => $this->id,
                'product_id' => $this->product_id
            ]);
            return false;
        }

        // Check if loan is active
        if ($this->status !== self::STATUS_ACTIVE) {
            info('Top-up eligibility check failed: Loan is not active', [
                'loan_id' => $this->id,
                'status' => $this->status
            ]);
            return false;
        }

        // Check if loan has arrears
        if ($this->is_in_arrears) {
            info('Top-up eligibility check failed: Loan has arrears', [
                'loan_id' => $this->id,
                'arrears_amount' => $this->arrears_amount
            ]);
            return false;
        }


        // Step 2: Fetch loan schedules
        $schedules = $this->schedule;

        // Step 3: Get top-up type and value
        $type = $product->top_up_type;
        $value = $product->top_up_type_value;

        info('Top-up eligibility check data', [
            'loan_id' => $this->id,
            'type' => $type,
            'value' => $value,
            'schedules_count' => $schedules->count()
        ]);

        switch ($type) {
            case 'number_of_installment':
                // Get paid amount and calculate total amount for required installments
                $paidAmount = $this->getTotalPaidAmount();
                $installmentAmount = $this->getInstallmentAmount();

                if ($installmentAmount <= 0) {
                    info('Top-up eligibility check failed: Invalid installment amount', [
                        'loan_id' => $this->id,
                        'installment_amount' => $installmentAmount
                    ]);
                    return false;
                }

                // Calculate total amount for the required number of installments
                $requiredInstallmentsAmount = $installmentAmount * $value;

                info('Top-up eligibility check - installments amount', [
                    'loan_id' => $this->id,
                    'paid_amount' => $paidAmount,
                    'installment_amount' => $installmentAmount,
                    'required_installments' => $value,
                    'required_installments_amount' => $requiredInstallmentsAmount,
                    'is_eligible' => $paidAmount >= $requiredInstallmentsAmount
                ]);

                return $paidAmount >= $requiredInstallmentsAmount;

            case 'percentage':
                // Calculate percentage of total amount paid
                $totalToPay = $this->getTotalAmountToPay();
                $totalPaid = $this->getTotalPaidAmount();

                if ($totalToPay <= 0) {
                    info('Top-up eligibility check failed: Invalid total amount to pay', [
                        'loan_id' => $this->id,
                        'total_to_pay' => $totalToPay
                    ]);
                    return false;
                }

                $paidPercentage = ($totalPaid / $totalToPay) * 100;

                info('Top-up eligibility check - percentage', [
                    'loan_id' => $this->id,
                    'total_paid' => $totalPaid,
                    'total_to_pay' => $totalToPay,
                    'paid_percentage' => $paidPercentage,
                    'required_percentage' => $value,
                    'is_eligible' => $paidPercentage >= $value
                ]);

                return $paidPercentage >= $value;

            case 'fixed_amount':
                // Check if paid amount has reached the required fixed amount for top-up
                $paidAmount = $this->getTotalPaidAmount();
                $requiredAmount = $value;
                $isEligible = $paidAmount >= $requiredAmount;

                info('Top-up eligibility check - fixed amount', [
                    'loan_id' => $this->id,
                    'paid_amount' => $paidAmount,
                    'required_amount' => $requiredAmount,
                    'is_eligible' => $isEligible
                ]);

                return $isEligible;

            default:
                info('Top-up eligibility check failed: Unknown top-up type', [
                    'loan_id' => $this->id,
                    'type' => $type
                ]);
                return false;
        }
    }



    /**
     * Get the calculated top-up amount for this loan
     * The top-up amount is the remaining balance of the loan
     *
     * @return float
     */
    public function getCalculatedTopUpAmount(): float
    {
        if (!$this->isEligibleForTopUp()) {
            return 0;
        }

        // Calculate the outstanding balance from schedule and repayments
        $totalOutstanding = 0;

        // Ensure schedule is loaded as a collection
        $schedules = $this->schedule;
        if (!$schedules) {
            $schedules = $this->schedule()->get();
        }
        // Only iterate if schedules is a valid collection
        if ($schedules && ($schedules instanceof \Illuminate\Database\Eloquent\Collection)) {
            foreach ($schedules as $scheduleItem) {
                $totalOutstanding += $scheduleItem->remaining_amount;
            }
        }

        return max(0, round($totalOutstanding, 2));
    }

    /**
     * Outstanding balance by component (principal, interest, penalty, fees) from schedules.
     *
     * @return array{outstanding_principal: float, outstanding_interest: float, outstanding_penalty: float, outstanding_fees: float, total_balance: float}
     */
    public function getOutstandingBalanceBreakdown(): array
    {
        if ($this->suppressCollectionsState()) {
            return [
                'outstanding_principal' => 0.0,
                'outstanding_interest' => 0.0,
                'outstanding_penalty' => 0.0,
                'outstanding_fees' => 0.0,
                'total_balance' => 0.0,
            ];
        }

        $schedules = $this->schedule;
        if (!$schedules) {
            $schedules = $this->schedule()->with(['repayments', 'loan'])->get();
        } else {
            $schedules = $schedules instanceof \Illuminate\Database\Eloquent\Collection
                ? $schedules->load(['repayments', 'loan'])
                : $this->schedule()->with(['repayments', 'loan'])->get();
        }

        $schedules->each(function ($schedule) {
            if (!$schedule->relationLoaded('loan')) {
                $schedule->setRelation('loan', $this);
            }
        });

        $totalPaidPrincipal = $schedules->sum(function ($schedule) {
            return $schedule->repayments ? $schedule->repayments->sum('principal') : 0;
        });
        $outstandingPrincipal = max(0, $this->amount - $totalPaidPrincipal);

        $outstandingInterest = 0;
        $outstandingPenalty = 0;
        $outstandingFees = 0;
        foreach ($schedules as $schedule) {
            $repayments = $schedule->repayments ?? collect();
            $paidInterest = $repayments->sum('interest');
            $paidPenalty = $repayments->sum('penalt_amount');
            $paidFees = $repayments->sum('fee_amount');
            $interestDue = (float) $schedule->balance_interest_component;
            $outstandingInterest += max(0, $interestDue - $paidInterest);
            $outstandingPenalty += max(0, ($schedule->penalty_amount ?? 0) - $paidPenalty);
            $outstandingFees += max(0, ($schedule->fee_amount ?? 0) - $paidFees);
        }

        $outstandingPrincipal = round($outstandingPrincipal, 2);
        $outstandingInterest = round($outstandingInterest, 2);
        $outstandingPenalty = round($outstandingPenalty, 2);
        $outstandingFees = round($outstandingFees, 2);
        $totalBalance = round(
            $outstandingPrincipal + $outstandingInterest + $outstandingPenalty + $outstandingFees,
            2
        );

        return [
            'outstanding_principal' => $outstandingPrincipal,
            'outstanding_interest' => $outstandingInterest,
            'outstanding_penalty' => $outstandingPenalty,
            'outstanding_fees' => $outstandingFees,
            'total_balance' => $totalBalance,
        ];
    }

    public function getTopUpBalanceBreakdown(): array
    {
        if (!$this->isEligibleForTopUp()) {
            return [
                'outstanding_principal' => 0,
                'outstanding_interest' => 0,
                'outstanding_penalty' => 0,
                'outstanding_fees' => 0,
                'total_balance' => 0,
            ];
        }

        $breakdown = $this->getOutstandingBalanceBreakdown();

        return [
            'outstanding_principal' => $breakdown['outstanding_principal'],
            'outstanding_interest' => $breakdown['outstanding_interest'],
            'outstanding_penalty' => $breakdown['outstanding_penalty'],
            'total_balance' => $breakdown['total_balance'],
        ];
    }

    /**
     * Get the total amount paid for this loan
     *
     * @return float
     */
    public function getTotalPaidAmount(): float
    {
        $repayments = $this->repayments ?? collect();
        return $repayments->sum(function ($repayment) {
            return $repayment->principal + $repayment->interest + $repayment->fee_amount + $repayment->penalt_amount;
        });
    }

    /**
     * Get the total amount to pay for this loan (from schedule)
     *
     * @return float
     */
    public function getTotalAmountToPay(): float
    {
        $schedules = $this->schedule;
        if (!$schedules) {
            $schedules = $this->schedule()->get();
        }
        if (!$schedules || !($schedules instanceof \Illuminate\Database\Eloquent\Collection)) {
            return 0;
        }
        return $schedules->sum(function ($scheduleItem) {
            return $scheduleItem->principal + $scheduleItem->interest + $scheduleItem->fee_amount + $scheduleItem->penalty_amount;
        });
    }

    /**
     * Get the installment amount (average amount per installment)
     *
     * @return float
     */
    public function getInstallmentAmount(): float
    {
        $totalAmount = $this->getTotalAmountToPay();
        $totalInstallments = $this->period;

        if ($totalInstallments <= 0) {
            return 0;
        }

        return round($totalAmount / $totalInstallments, 2);
    }

    /**
     * Get the period unit based on interest cycle
     */
    public function getPeriodUnit(): string
    {
        $cycle = strtolower($this->interest_cycle);

        switch ($cycle) {
            case 'daily':
                return 'days';
            case 'weekly':
                return 'weeks';
            case 'monthly':
                return 'months';
            case 'quarterly':
                return 'quarters';
            case 'semi_annually':
                return 'semi-annual periods';
            case 'annually':
                return 'years';
            default:
                return 'months';
        }
    }

    /**
     * Get the installment unit based on interest cycle
     */
    public function getInstallmentUnit(): string
    {
        $cycle = strtolower($this->interest_cycle);

        switch ($cycle) {
            case 'daily':
                return 'Daily';
            case 'weekly':
                return 'Weekly';
            case 'monthly':
                return 'Monthly';
            case 'quarterly':
                return 'Quarterly';
            case 'semi_annually':
                return 'Semi-Annual';
            case 'annually':
                return 'Annual';
            default:
                return 'Monthly';
        }
    }

    /**
     * Post matured interest for schedules that are due before today
     * This is used when creating past loans to ensure interest is properly recorded
     */
    public function postMaturedInterestForPastLoan()
    {
        if ($this->usesDailyInterestAccrual()) {
            return 0;
        }

        $today = Carbon::today();

        // Find schedules that are due before today and have interest
        $maturedSchedules = $this->schedule()
            ->where('due_date', '<', $today)
            ->where('interest', '>', 0)
            ->get();

        if ($maturedSchedules->isEmpty()) {
            return 0;
        }

        $totalInterestPosted = 0;
        $product = $this->product;

        if (!$product || !$product->interest_receivable_account_id || !$product->interest_revenue_account_id) {
            Log::warning("Missing interest accounts for product {$product->id} - cannot post matured interest");
            return 0;
        }

        foreach ($maturedSchedules as $schedule) {
            // Load repayments for this schedule
            $schedule->loadMissing('repayments');

            $schedule->setRelation('loan', $this);
            $totalInterest = (float) $schedule->balance_interest_component;
            $paidInterest = (float) $schedule->repayments->sum('interest');
            $unpaidInterest = round($totalInterest - $paidInterest, 2);

            if ($unpaidInterest <= 0) {
                continue;
            }

            // Check if mature interest already posted for this schedule
            $exists = GlTransaction::where('chart_account_id', $product->interest_receivable_account_id)
                ->where('customer_id', $this->customer_id)
                ->where('transaction_id', $schedule->id)
                ->where('transaction_type', 'Mature Interest')
                ->exists();

            if ($exists) {
                continue;
            }

            // Post mature interest on the schedule's due date
            $this->createMatureInterestTransactions($schedule, $unpaidInterest, $product);
            $totalInterestPosted += $unpaidInterest;
        }

        if ($totalInterestPosted > 0) {
            Log::info("Posted mature interest for past loan {$this->loanNo}: TZS " . number_format($totalInterestPosted, 2));
        }

        return $totalInterestPosted;
    }

    /**
     * Accrue penalties for overdue schedules when creating a back-dated loan.
     * Uses the product's penalty settings and accounting penalty configuration.
     */
    public function accruePenaltiesForPastLoan(): float
    {
        $this->loadMissing(['product']);

        $product = $this->product;
        if (!$product || empty($product->penalty_ids)) {
            return 0.0;
        }

        $today = Carbon::today();

        $hasOverdueSchedule = $this->schedule()
            ->whereNotIn('status', ['restructured', 'paid', 'cancelled'])
            ->whereDate('due_date', '<', $today)
            ->exists();

        if (!$hasOverdueSchedule) {
            return 0.0;
        }

        $this->unsetRelation('schedule');
        $this->load(['schedule.repayments']);

        return PenaltyAccrualService::forDate($today->toDateString())
            ->catchUpForLoan($this, $today);
    }

    /**
     * Accrue penalties after the current DB transaction commits (create/import flows).
     * When not in a transaction, accrues immediately.
     */
    public function accruePenaltiesForPastLoanWhenReady(): float
    {
        if (DB::transactionLevel() === 0) {
            return $this->accruePenaltiesForPastLoan();
        }

        $loanId = $this->id;
        DB::afterCommit(function () use ($loanId) {
            $loan = Loan::with(['product', 'schedule.repayments'])->find($loanId);
            if ($loan) {
                $loan->accruePenaltiesForPastLoan();
            }
        });

        return 0.0;
    }

    /**
     * Create GL transactions for mature interest
     */
    private function createMatureInterestTransactions($schedule, $unpaidInterest, $product)
    {
        $userId = auth()->id() ?? 1; // Use logged-in user or fallback to 1

        // Debit Receivable
        GlTransaction::create([
            'chart_account_id' => $product->interest_receivable_account_id,
            'customer_id' => $this->customer_id,
            'amount' => $unpaidInterest,
            'nature' => 'debit',
            'transaction_id' => $schedule->id,
            'transaction_type' => 'Mature Interest',
            'date' => $schedule->due_date,
            'description' => "Mature interest for loan {$this->loanNo}, schedule {$schedule->id}",
            'branch_id' => $this->branch_id,
            'user_id' => $userId,
        ]);

        // Credit Revenue
        GlTransaction::create([
            'chart_account_id' => $product->interest_revenue_account_id,
            'customer_id' => $this->customer_id,
            'amount' => $unpaidInterest,
            'nature' => 'credit',
            'transaction_id' => $schedule->id,
            'transaction_type' => 'Mature Interest',
            'date' => $schedule->due_date,
            'description' => "Mature interest income for loan {$this->loanNo}, schedule {$schedule->id}",
            'branch_id' => $this->branch_id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Close the loan by checking if all schedules are fully paid
     * Changes status to 'completed' if all payments are made
     *
     * @return bool True if loan was closed, false if not eligible for closing
     */
    public function closeLoan(): bool
    {
        // Only active loans can be closed
        if ($this->status !== self::STATUS_ACTIVE) {
            Log::info("Loan {$this->loanNo} cannot be closed - status is {$this->status}");
            return false;
        }

        if (!$this->isEligibleForClosing()) {
            Log::info("Loan {$this->loanNo} cannot be closed - outstanding remains above closure threshold");
            return false;
        }

        $this->status = self::STATUS_COMPLETE;
        $this->save();

        Log::info("Loan {$this->loanNo} has been successfully closed");

        return true;
    }

    /**
     * Check if the loan is eligible for closing
     *
     * @return bool
     */
    public function isEligibleForClosing(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->getPendingBillsOutstanding() > 0) {
            return false;
        }

        return $this->isLoanFullyPaidForSettlement() || $this->isOutstandingBelowClosureThreshold();
    }

    public function getTotalContractOutstanding(): float
    {
        return (float) $this->getOutstandingBalanceBreakdown()['total_balance'];
    }

    public function isOutstandingBelowClosureThreshold(?float $threshold = null): bool
    {
        $threshold = $threshold ?? self::OUTSTANDING_CLOSURE_THRESHOLD;

        return $this->getTotalContractOutstanding() < $threshold;
    }

    public function syncCompletionStatusIfEligible(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE || !$this->isEligibleForClosing()) {
            return false;
        }

        $this->status = self::STATUS_COMPLETE;
        $this->save();

        Log::info("Loan {$this->loanNo} auto-completed — outstanding below closure threshold");

        return true;
    }

    public static function syncActiveLoansEligibleForCompletion(): int
    {
        $updated = 0;

        static::query()
            ->where('status', self::STATUS_ACTIVE)
            ->with(['schedule.repayments'])
            ->chunkById(100, function ($loans) use (&$updated) {
                foreach ($loans as $loan) {
                    if ($loan->syncCompletionStatusIfEligible()) {
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    /**
     * Get the total outstanding amount across all schedules
     *
     * @return float
     */
    public function getTotalOutstandingAmount(): float
    {
        if ($this->suppressCollectionsState()) {
            return 0;
        }

        $schedules = $this->schedule;
        if (!$schedules) {
            $schedules = $this->schedule()->get();
        }
        if (!$schedules || !($schedules instanceof \Illuminate\Database\Eloquent\Collection)) {
            return $this->getPendingBillsOutstanding();
        }

        return round((float) $schedules->sum('remaining_amount') + $this->getPendingBillsOutstanding(), 2);
    }

    /**
     * Remaining balance on open follow-up / other loan bills.
     */
    public function getPendingBillsOutstanding(): float
    {
        $bills = $this->relationLoaded('bills')
            ? $this->bills
            : $this->bills()->open()->get();

        return round((float) $bills
            ->filter(fn ($bill) => $bill->isOpen())
            ->sum(fn ($bill) => $bill->remaining_amount), 2);
    }

    /**
     * Get the total paid amount across all schedules
     *
     * @return float
     */
    public function getTotalPaidAmountFromSchedules(): float
    {
        $schedules = $this->schedule;
        if (!$schedules) {
            $schedules = $this->schedule()->get();
        }
        if (!$schedules || !($schedules instanceof \Illuminate\Database\Eloquent\Collection)) {
            return 0;
        }
        return $schedules->sum('paid_amount');
    }

    public function buildSettlementPlan(?string $asOfDate = null): array
    {
        $schedules = $this->schedule;
        if (!$schedules) {
            $schedules = $this->schedule()->with('repayments')->get();
        }

        if (!$schedules || !($schedules instanceof \Illuminate\Database\Eloquent\Collection) || $schedules->isEmpty()) {
            $pendingBills = $this->getPendingBillsOutstanding();

            return [
                'due_schedule_payments' => [],
                'future_principal_payments' => [],
                'total_due_components' => 0.0,
                'total_future_principal' => 0.0,
                'pending_bills' => $pendingBills,
                'settle_amount' => $pendingBills,
            ];
        }

        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : now()->endOfDay();
        $dueSchedulePayments = [];
        $futurePrincipalPayments = [];
        $totalDueComponents = 0.0;
        $totalFuturePrincipal = 0.0;

        foreach ($schedules->sortBy('due_date') as $schedule) {
            if (in_array(($schedule->status ?? null), ['restructured', 'paid', 'cancelled'], true)) {
                continue;
            }

            if (!$schedule->relationLoaded('loan')) {
                $schedule->setRelation('loan', $this);
            }

            $repayments = $schedule->repayments ?? collect();
            $principalPaid = (float) $repayments->sum('principal');
            $interestPaid = (float) $repayments->sum('interest');
            $feesPaid = (float) $repayments->sum('fee_amount');
            $penaltyPaid = (float) $repayments->sum('penalt_amount');

            $remainingPrincipal = round(max(0.0, (float) $schedule->principal - $principalPaid), 2);
            $remainingInterest = round(max(0.0, (float) $schedule->balance_interest_component - $interestPaid), 2);
            $remainingFees = round(max(0.0, (float) ($schedule->fee_amount ?? 0) - $feesPaid), 2);
            $remainingPenalty = round(max(0.0, (float) ($schedule->penalty_amount ?? 0) - $penaltyPaid), 2);
            $remainingTotal = round($remainingPrincipal + $remainingInterest + $remainingFees + $remainingPenalty, 2);

            if ($remainingTotal <= self::OUTSTANDING_CLOSURE_THRESHOLD) {
                continue;
            }

            $payment = [
                'schedule' => $schedule,
                'schedule_id' => $schedule->id,
                'principal' => $remainingPrincipal,
                'interest' => $remainingInterest,
                'fee_amount' => $remainingFees,
                'penalty_amount' => $remainingPenalty,
                'amount' => $remainingTotal,
            ];

            if (Carbon::parse($schedule->due_date)->endOfDay()->lte($asOf)) {
                $dueSchedulePayments[] = $payment;
                $totalDueComponents += $remainingTotal;
                continue;
            }

            if ($remainingPrincipal > self::OUTSTANDING_CLOSURE_THRESHOLD) {
                $futurePayment = $payment;
                $futurePayment['interest'] = 0.0;
                $futurePayment['fee_amount'] = 0.0;
                $futurePayment['penalty_amount'] = 0.0;
                $futurePayment['amount'] = $remainingPrincipal;
                $futurePrincipalPayments[] = $futurePayment;
                $totalFuturePrincipal += $remainingPrincipal;
            }
        }

        $pendingBills = $this->getPendingBillsOutstanding();

        return [
            'due_schedule_payments' => $dueSchedulePayments,
            'future_principal_payments' => $futurePrincipalPayments,
            'total_due_components' => round($totalDueComponents, 2),
            'total_future_principal' => round($totalFuturePrincipal, 2),
            'pending_bills' => $pendingBills,
            'settle_amount' => round($totalDueComponents + $totalFuturePrincipal + $pendingBills, 2),
        ];
    }

    // Get the total amount to settle the loan:
    // clear all due/overdue components first, then pay remaining principal on future schedules.
    public function getTotalAmountToSettle(): float
    {
        if ($this->suppressCollectionsState()) {
            return 0;
        }

        return (float) ($this->buildSettlementPlan()['settle_amount'] ?? 0);
    }

    /**
     * Get the total amount to settle the loan as an attribute
     * This makes it accessible as $loan->total_amount_to_settle
     */
    public function getTotalAmountToSettleAttribute(): float
    {
        return $this->getTotalAmountToSettle();
    }

    /**
     * Get the total principal paid for this loan
     *
     * @return float
     */
    public function getTotalPrincipalPaid(): float
    {
        $repayments = $this->repayments ?? collect();
        return $repayments->sum('principal');
    }

    /**
     * Get the total principal paid as an attribute
     * This makes it accessible as $loan->total_principal_paid
     */
    public function getTotalPrincipalPaidAttribute(): float
    {
        return $this->getTotalPrincipalPaid();
    }

    /**
     * Get the total interest paid for this loan
     *
     * @return float
     */
    public function getTotalInterestPaid(): float
    {
        $repayments = $this->repayments ?? collect();
        return $repayments->sum('interest');
    }

    /**
     * Get the total interest paid as an attribute
     * This makes it accessible as $loan->total_interest_paid
     */
    public function getTotalInterestPaidAttribute(): float
    {
        return $this->getTotalInterestPaid();
    }

    /**
     * Process settle repayment - pays current interest and all remaining principal
     *
     * @param float $amount The settle amount to be paid
     * @param array $paymentData Payment data including bank account, payment date, etc.
     * @return array Result of the settlement
     */
    public function processSettleRepayment(float $amount, array $paymentData): array
    {
        DB::beginTransaction();

        try {
            // Get current unpaid/partially paid schedule
            $currentSchedule = $this->schedule->where('is_fully_paid', false)->first();

            if (!$currentSchedule) {
                throw new \Exception('No unpaid schedule found for settlement');
            }

            // Calculate current interest (remaining interest from current schedule)
            $interestPaid = $currentSchedule->repayments->sum('interest');
            $currentInterest = max(0, $currentSchedule->interest - $interestPaid);

            // Calculate total outstanding principal from all schedules
            $outstandingPrincipal = $this->schedule->sum('principal') - $this->schedule->sum(function ($schedule) {
                return $schedule->repayments->sum('principal');
            });

            // Validate settle amount
            $expectedSettleAmount = $currentInterest + $outstandingPrincipal;
            if (abs($amount - $expectedSettleAmount) > 0.01) {
                throw new \Exception("Settle amount mismatch. Expected: {$expectedSettleAmount}, Provided: {$amount}");
            }

            // Create repayment record for current schedule (interest only)
            if ($currentInterest > 0) {
                $currentRepayment = Repayment::create([
                    'customer_id' => $this->customer_id,
                    'loan_id' => $this->id,
                    'loan_schedule_id' => $currentSchedule->id,
                    'bank_account_id' => $paymentData['bank_chart_account_id'] ?? null,
                    'payment_date' => $paymentData['payment_date'] ?? now(),
                    'due_date' => $currentSchedule->due_date,
                    'principal' => 0,
                    'interest' => $currentInterest,
                    'fee_amount' => 0,
                    'penalt_amount' => 0,
                    'cash_deposit' => $currentInterest,
                ]);

                // Create GL transactions for current interest
                $this->createSettleInterestGL($currentRepayment, $currentInterest, $paymentData);
            }

            // Create repayment records for all remaining principal across all schedules
            $remainingAmount = $amount - $currentInterest;
            $processedSchedules = [];

            foreach ($this->schedule as $schedule) {
                if ($remainingAmount <= 0)
                    break;

                $principalPaid = $schedule->repayments->sum('principal');
                $remainingPrincipal = $schedule->principal - $principalPaid;

                if ($remainingPrincipal > 0) {
                    $principalToPay = min($remainingAmount, $remainingPrincipal);

                    $principalRepayment = Repayment::create([
                        'customer_id' => $this->customer_id,
                        'loan_id' => $this->id,
                        'loan_schedule_id' => $schedule->id,
                        'bank_account_id' => $paymentData['bank_chart_account_id'] ?? null,
                        'payment_date' => $paymentData['payment_date'] ?? now(),
                        'due_date' => $schedule->due_date,
                        'principal' => $principalToPay,
                        'interest' => 0,
                        'fee_amount' => 0,
                        'penalt_amount' => 0,
                        'cash_deposit' => $principalToPay,
                    ]);

                    // Create GL transactions for principal
                    $this->createSettlePrincipalGL($principalRepayment, $principalToPay, $paymentData);

                    $remainingAmount -= $principalToPay;
                    $processedSchedules[] = [
                        'schedule_id' => $schedule->id,
                        'principal_paid' => $principalToPay
                    ];
                }
            }

            // Check if loan should be closed
            $shouldClose = $this->isLoanFullyPaidForSettlement();
            if ($shouldClose) {
                $this->status = self::STATUS_COMPLETE;
                $this->save();
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Loan settled successfully',
                'current_interest_paid' => $currentInterest,
                'total_principal_paid' => $amount - $currentInterest,
                'processed_schedules' => $processedSchedules,
                'loan_closed' => $shouldClose
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Settle repayment failed', [
                'loan_id' => $this->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create GL transactions for settle interest payment
     */
    private function createSettleInterestGL(Repayment $repayment, float $interestAmount, array $paymentData)
    {
        // Debit: Bank/Cash account
        GlTransaction::create([
            'chart_account_id' => $paymentData['bank_chart_account_id'],
            'customer_id' => $this->customer_id,
            'amount' => $interestAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Interest',
            'date' => $repayment->payment_date,
            'description' => "Settle interest payment for loan {$this->loanNo}",
            'branch_id' => $this->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Interest receivable or revenue account
        $interestAccountId = $this->product->interest_receivable_account_id ?? $this->product->interest_revenue_account_id;
        if ($interestAccountId) {
            GlTransaction::create([
                'chart_account_id' => $interestAccountId,
                'customer_id' => $this->customer_id,
                'amount' => $interestAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Interest',
                'date' => $repayment->payment_date,
                'description' => "Settle interest payment for loan {$this->loanNo}",
                'branch_id' => $this->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Create GL transactions for settle principal payment
     */
    private function createSettlePrincipalGL(Repayment $repayment, float $principalAmount, array $paymentData)
    {
        // Debit: Bank/Cash account
        GlTransaction::create([
            'chart_account_id' => $paymentData['bank_chart_account_id'],
            'customer_id' => $this->customer_id,
            'amount' => $principalAmount,
            'nature' => 'debit',
            'transaction_id' => $repayment->id,
            'transaction_type' => 'Settle Principal',
            'date' => $repayment->payment_date,
            'description' => "Settle principal payment for loan {$this->loanNo}",
            'branch_id' => $this->branch_id,
            'user_id' => auth()->id(),
        ]);

        // Credit: Principal receivable account
        $principalAccountId = $this->product->principal_receivable_account_id;
        if ($principalAccountId) {
            GlTransaction::create([
                'chart_account_id' => $principalAccountId,
                'customer_id' => $this->customer_id,
                'amount' => $principalAmount,
                'nature' => 'credit',
                'transaction_id' => $repayment->id,
                'transaction_type' => 'Settle Principal',
                'date' => $repayment->payment_date,
                'description' => "Settle principal payment for loan {$this->loanNo}",
                'branch_id' => $this->branch_id,
                'user_id' => auth()->id(),
            ]);
        }
    }

    /**
     * Check if loan is fully paid for settlement purposes
     * Compares total principal paid against loan amount
     */
    public function isLoanFullyPaidForSettlement(): bool
    {
        return $this->getTotalAmountToSettle() <= self::OUTSTANDING_CLOSURE_THRESHOLD;
    }
    /**
     * Loan was created through the application approval pipeline (has approval records).
     */
    public function followedApplicationWorkflow(): bool
    {
        if ($this->relationLoaded('approvals')) {
            return $this->approvals->isNotEmpty();
        }

        return $this->approvals()->exists();
    }

    /**
     * Bulk opening-balance imports are created without a disbursement bank account
     * in the same request as schedule generation.
     */
    protected function isOpeningBalanceImport(): bool
    {
        if ($this->bank_account_id) {
            return false;
        }

        if ($this->status !== self::STATUS_ACTIVE || empty($this->loanNo)) {
            return false;
        }

        $disbursedAt = $this->disbursed_on ?? $this->date_applied ?? $this->created_at;
        if (!$this->created_at || !$disbursedAt) {
            return false;
        }

        return $this->created_at->diffInSeconds(Carbon::parse($disbursedAt), true) < 5;
    }

    public function isDefaultedByArrears(int $minDays = self::DEFAULTED_ARREARS_DAYS_THRESHOLD): bool
    {
        return $this->is_in_arrears && $this->days_in_arrears > $minDays;
    }
}
