# Loan Management System Documentation

## Table of Contents

1. [Overview](#overview)
2. [Database Structure](#database-structure)
3. [Models](#models)
4. [Controllers](#controllers)
5. [Services](#services)
6. [Routes](#routes)
7. [Loan Lifecycle](#loan-lifecycle)
8. [Loan Repayment System](#loan-repayment-system)
9. [Loan Product Management](#loan-product-management)
10. [Loan Schedule System](#loan-schedule-system)
11. [Approval Workflow](#approval-workflow)
12. [Features and Operations](#features-and-operations)

---

## Overview

The Loan Management System is a comprehensive Laravel-based application that handles the complete lifecycle of loans from application to completion. The system supports multiple loan products, flexible repayment schedules, approval workflows, collateral management, and comprehensive accounting integration.

### Key Components

- **Loan Model**: Core entity representing individual loans
- **LoanProduct Model**: Defines loan product configurations
- **LoanSchedule Model**: Manages repayment schedules
- **Repayment Model**: Tracks individual repayment transactions
- **LoanController**: Handles loan CRUD operations and workflows
- **LoanRepaymentController**: Manages repayment operations
- **LoanProductController**: Manages loan product configurations
- **LoanRepaymentService**: Business logic for repayment processing

---

## Database Structure

### Loans Table

**Migration**: `2025_07_28_150157_create_loans_table.php`

#### Schema

```sql
CREATE TABLE loans (
    id BIGINT PRIMARY KEY,
    customer_id BIGINT (Foreign Key -> customers),
    group_id BIGINT NULLABLE (Foreign Key -> groups),
    product_id BIGINT NULLABLE (Foreign Key -> loan_products),
    loan_officer_id BIGINT NULLABLE (Foreign Key -> users),
    amount DECIMAL(15,2) DEFAULT 0,
    interest DECIMAL(8,2) DEFAULT 0,
    interest_amount DECIMAL(15,2) DEFAULT 0,
    amount_total DECIMAL(15,2) DEFAULT 0,
    period INTEGER DEFAULT 0,
    bank_account_id BIGINT NULLABLE (Foreign Key -> bank_accounts),
    branch_id BIGINT NULLABLE (Foreign Key -> branches),
    date_applied DATE NULLABLE,
    disbursed_on DATE NULLABLE,
    first_repayment_date DATE NULLABLE,
    last_repayment_date DATE NULLABLE,
    interest_cycle VARCHAR,
    status VARCHAR NULLABLE,
    sector VARCHAR NULLABLE,
    loanNo VARCHAR UNIQUE,
    top_up_id BIGINT NULLABLE (Foreign Key -> loans - self-referencing),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### Key Fields

- **amount**: Principal loan amount
- **interest**: Interest rate percentage
- **interest_amount**: Total calculated interest
- **amount_total**: Principal + Interest
- **period**: Number of repayment periods
- **status**: Loan status (applied, checked, approved, authorized, active, rejected, defaulted, completed, written_off)
- **interest_cycle**: Frequency of repayments (daily, weekly, monthly, quarterly, semi_annually, annually)
- **loanNo**: Unique loan number (auto-generated as SF-{1000000 + id})
- **top_up_id**: Reference to parent loan for top-up loans

---

### Loan Products Table

**Migration**: `2025_07_28_150001_create_loan_products_table.php`

#### Schema

```sql
CREATE TABLE loan_products (
    id BIGINT PRIMARY KEY,
    name VARCHAR,
    product_type VARCHAR,
    minimum_interest_rate DECIMAL(8,2),
    maximum_interest_rate DECIMAL(8,2),
    interest_cycle VARCHAR,
    interest_method VARCHAR,
    minimum_principal DECIMAL(15,2),
    maximum_principal DECIMAL(15,2),
    minimum_period INTEGER,
    maximum_period INTEGER,
    grace_period INTEGER,
    maximum_number_of_loans INTEGER,
    top_up_type VARCHAR NULLABLE,
    top_up_type_value DECIMAL(15,2) DEFAULT 0,
    has_cash_collateral BOOLEAN DEFAULT false,
    cash_collateral_type VARCHAR NULLABLE,
    cash_collateral_value_type VARCHAR NULLABLE,
    cash_collateral_value DECIMAL(15,2) NULLABLE,
    has_approval_levels BOOLEAN DEFAULT false,
    approval_levels VARCHAR NULLABLE,
    principal_receivable_account_id BIGINT (Foreign Key -> chart_accounts),
    interest_receivable_account_id BIGINT (Foreign Key -> chart_accounts),
    interest_revenue_account_id BIGINT (Foreign Key -> chart_accounts),
    direct_writeoff_account_id BIGINT NULLABLE (Foreign Key -> chart_accounts),
    provision_writeoff_account_id BIGINT NULLABLE (Foreign Key -> chart_accounts),
    income_provision_account_id BIGINT NULLABLE (Foreign Key -> chart_accounts),
    fees_ids JSON NULLABLE,
    penalty_ids JSON NULLABLE,
    repayment_order VARCHAR NULLABLE,
    is_active BOOLEAN,
    penalt_deduction_criteria VARCHAR NULLABLE,
    allow_push_to_ess BOOLEAN,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### Key Features

- **Interest Methods**: flat_rate, reducing_balance_with_equal_installment, reducing_balance_with_equal_principal
- **Top-up Types**: percentage, fixed_amount, number_of_installments
- **Collateral Support**: Percentage or fixed amount requirements
- **Approval Levels**: Configurable multi-level approval workflow
- **Chart Account Integration**: Links to accounting chart of accounts

---

### Loan Schedules Table

**Migration**: `2025_08_03_085133_create_loan_shedules_table.php`

#### Schema

```sql
CREATE TABLE loan_schedules (
    id BIGINT PRIMARY KEY,
    loan_id BIGINT (Foreign Key -> loans, CASCADE DELETE),
    customer_id BIGINT (Foreign Key -> customers, CASCADE DELETE),
    due_date DATE NULLABLE,
    end_grace_date DATE NULLABLE,
    end_date DATE NULLABLE,
    end_pernalty_date DATE NULLABLE,
    principal DECIMAL(10,2),
    interest DECIMAL(10,2),
    fee_amount DECIMAL(10,2),
    penalty_amount DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### Purpose

Stores individual repayment installments for each loan, including principal, interest, fees, and penalties.

---

### Repayments Table

**Migration**: (Referenced in Repayment model)

#### Schema

```sql
CREATE TABLE repayments (
    id BIGINT PRIMARY KEY,
    customer_id BIGINT (Foreign Key -> customers),
    loan_id BIGINT (Foreign Key -> loans),
    loan_schedule_id BIGINT (Foreign Key -> loan_schedules),
    bank_account_id BIGINT,
    principal DECIMAL,
    interest DECIMAL,
    penalt_amount DECIMAL,
    fee_amount DECIMAL,
    due_date DATE,
    cash_deposit DECIMAL,
    payment_date DATE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## Models

### Loan Model

**Location**: `app/Models/Loan.php`

#### Relationships

```php
// Belongs To
- customer() -> Customer
- group() -> Group
- product() -> LoanProduct
- bankAccount() -> BankAccount
- branch() -> Branch
- topUpLoan() -> Loan (self-referencing)
- loanOfficer() -> User

// Has Many
- schedule() -> LoanSchedule[]
- repayments() -> Repayment[]
- loanFiles() -> LoanFile[]
- collaterals() -> LoanCollateral[]
- topUpChildren() -> Loan[]
- approvals() -> LoanApproval[]

// Belongs To Many
- guarantors() -> Customer[] (pivot: loan_guarantor)
```

#### Status Constants

```php
const STATUS_APPLIED = 'applied';
const STATUS_CHECKED = 'checked';
const STATUS_APPROVED = 'approved';
const STATUS_AUTHORIZED = 'authorized';
const STATUS_ACTIVE = 'active';
const STATUS_REJECTED = 'rejected';
const STATUS_DEFAULTED = 'defaulted';
const STATUS_COMPLETE = 'completed';
```

#### Key Methods

##### Interest Calculation

```php
calculateInterestAmount(?float $rate = null, bool $returnSchedule = false): float|array
```

Calculates interest based on product's interest method:
- **flat_rate**: Simple interest on principal
- **reducing_balance_with_equal_installment**: EMI calculation
- **reducing_balance_with_equal_principal**: Equal principal with reducing interest

##### Repayment Schedule Generation

```php
generateRepaymentSchedule(float $rate): void
```

Generates repayment schedule based on:
- Interest cycle (daily, weekly, monthly, etc.)
- Interest method
- Grace period
- Fee distribution rules
- Penalty calculations

##### Approval Workflow Methods

```php
getApprovalRoles(): array
getCurrentApprovalLevel(): int
getNextApprovalLevel(): ?int
getNextApprovalRole(): ?int
canBeApprovedByUser($user): bool
getNextApprovalAction(): ?string
```

##### Top-up Eligibility

```php
isEligibleForTopUp(): bool
getCalculatedTopUpAmount(): float
```

Checks eligibility based on:
- Product top-up configuration
- Payment percentage/amount/installments
- Loan status and arrears

##### Settlement Methods

```php
getTotalAmountToSettle(): float
processSettleRepayment(float $amount, array $paymentData): array
isLoanFullyPaidForSettlement(): bool
closeLoan(): bool
```

##### Accessors

```php
getTotalAmountToSettleAttribute(): float
getTotalPrincipalPaidAttribute(): float
getTotalInterestPaidAttribute(): float
getArrearsAmountAttribute(): float
getDaysInArrearsAttribute(): int
getIsInArrearsAttribute(): bool
```

#### Auto-Generated Loan Number

The loan number is automatically generated using the model's `boot()` method:

```php
// Temporary number during creation
'TMP-' . uniqid()

// Final number after creation
'SF-' . (1000000 + loan_id)
```

---

### LoanProduct Model

**Location**: `app/Models/LoanProduct.php`

#### Relationships

```php
// Belongs To
- principalReceivableAccount() -> ChartAccount
- interestReceivableAccount() -> ChartAccount
- interestRevenueAccount() -> ChartAccount
- directWriteoffAccount() -> ChartAccount
- provisionWriteoffAccount() -> ChartAccount
- incomeProvisionAccount() -> ChartAccount

// Has Many
- loans() -> Loan[]
```

#### Key Methods

##### Collateral Management

```php
requiresCollateral(): bool
calculateRequiredCollateral(float $loanAmount): float
getCollateralTypeDescription(): string
```

##### Loan Limits Validation

```php
isAmountWithinLimits(float $amount): bool
isPeriodWithinLimits(int $period): bool
hasReachedMaxLoans(int $customerId): bool
getRemainingLoans(int $customerId): int
```

##### Top-up Calculation

```php
topupAmount(float $loanAmount): float
```

Supports:
- **percentage**: Percentage of loan amount
- **fixed_amount**: Fixed amount
- **number_of_installments**: Based on installment count

##### Fee and Penalty Access

```php
getFeesAttribute(): Collection
penalties(): Collection
getPenaltyAttribute(): ?Penalty
```

---

### LoanSchedule Model

**Location**: `app/Models/LoanSchedule.php`

#### Relationships

```php
// Belongs To
- loan() -> Loan
- customer() -> Customer

// Has Many
- repayments() -> Repayment[]
```

#### Key Accessors

```php
getPaidAmountAttribute(): float
getRemainingAmountAttribute(): float
getIsFullyPaidAttribute(): bool
getTotalDueAttribute(): float
getPaymentPercentageAttribute(): float
```

#### Methods

```php
fullPrincipalPaid(): bool
fullPenaltyPaid(): bool
PenaltyPaid(): bool
isPenaltyRemovalAllowed(): bool
```

---

### Repayment Model

**Location**: `app/Models/Repayment.php`

#### Relationships

```php
// Belongs To
- customer() -> Customer
- loan() -> Loan
- schedule() -> LoanSchedule
- chartAccount() -> ChartAccount

// Has One
- receipt() -> Receipt
```

#### Key Accessors

```php
getAmountPaidAttribute(): float
getArrearsAmountAttribute(): float
getScheduleIdAttribute(): int
getRemainScheduleAttribute(): float
```

---

## Controllers

### LoanController

**Location**: `app/Http/Controllers/LoanController.php`

#### Main Methods

##### Index and Listing

```php
index(): View
listLoans(): View
getLoansData(Request $request): DataTables
loansByStatus($status): View
```

##### CRUD Operations

```php
create(): View
store(Request $request): RedirectResponse
edit($encodedId): View
update(Request $request, $encodedId): RedirectResponse
show($encodedId): View
destroy($encodedId): RedirectResponse
```

##### Loan Application Workflow

```php
applicationIndex(Request $request): View
applicationCreate(): View
applicationStore(Request $request): RedirectResponse
applicationShow($encodedId): View
applicationEdit($encodedId): View
applicationUpdate(Request $request, $encodedId): RedirectResponse
applicationApprove($encodedId): RedirectResponse
applicationReject($encodedId): RedirectResponse
applicationDelete($encodedId): RedirectResponse
```

##### Approval Methods

```php
approveLoan($encodedId, Request $request): RedirectResponse|JsonResponse
rejectLoan($encodedId, Request $request): RedirectResponse
checkLoan($encodedId, Request $request): RedirectResponse
authorizeLoan($encodedId, Request $request): RedirectResponse
disburseLoan($encodedId, Request $request): RedirectResponse
```

The `approveLoan()` method is the main approval handler that:
1. Validates user permissions
2. Determines next approval action (check, approve, authorize, disburse)
3. Updates loan status
4. Creates approval records
5. Processes disbursement if applicable

##### Import/Export

```php
importLoans(Request $request): RedirectResponse
downloadTemplate(): Response
downloadOpeningBalanceTemplate(Request $request): Response
storeOpeningBalance(Request $request): RedirectResponse
exportLoanDetails($encodedId): Response
```

##### Document Management

```php
loanDocument(Request $request): RedirectResponse
destroyLoanDocument(LoanFile $loanFile): JsonResponse
```

##### Guarantor Management

```php
addGuarantor(Request $request, Loan $loan): RedirectResponse
removeGuarantor(Loan $loan, $guarantorId): RedirectResponse
```

##### Special Operations

```php
writeoff($hashid): View|RedirectResponse
defaultLoan($encodedId, Request $request): RedirectResponse
changeStatus(Request $request): JsonResponse
settleRepayment(Request $request, $id): RedirectResponse
feesReceipt($encodedId): View
storeReceipt(Request $request, $encodedId): RedirectResponse
```

##### Helper Methods

```php
validateProductLimits(array $data, LoanProduct $product): void
validateLoanRow($rowData, $rowNumber): array
createLoanFromImport($validated, $product, $accountId, $userId, $branchId): void
processLoanDisbursement($loan, $disbursementDate = null): void
```

---

### LoanRepaymentController

**Location**: `app/Http/Controllers/LoanRepaymentController.php`

#### Main Methods

```php
index(): View
create(): View
store(Request $request): RedirectResponse
show(string $id): View
edit($id): View
update(Request $request, $id): RedirectResponse
destroy($id): RedirectResponse
bulkDestroy(Request $request): JsonResponse
getRepaymentHistory($loanId): JsonResponse
getScheduleDetails($scheduleId): JsonResponse
removePenalty(Request $request, $scheduleId): JsonResponse
calculateSchedule(Request $request, $loanId): JsonResponse
bulkRepayment(Request $request): JsonResponse
printReceipt($id): Response
storeSettlementRepayment(Request $request): JsonResponse
```

---

### LoanProductController

**Location**: `app/Http/Controllers/LoanProductController.php`

#### Main Methods

```php
index(): View
create(): View
store(Request $request): RedirectResponse
show($encodedId): View
edit($encodedId): View
update(Request $request, $encodedId): RedirectResponse
destroy($encodedId): RedirectResponse
toggleStatus($encodedId): JsonResponse
```

---

## Services

### LoanRepaymentService

**Location**: `app/Services/LoanRepaymentService.php`

#### Main Methods

##### Repayment Processing

```php
processRepayment($loanId, $amount, $paymentData, $calculationMethod = 'flat_rate'): array
```

Main repayment processing method that:
1. Validates loan and amount
2. Gets unpaid schedules
3. Processes payments according to repayment order
4. Creates repayment records
5. Creates receipts and GL transactions
6. Creates journal entries
7. Updates loan status if fully paid

##### Schedule Calculation

```php
calculateSchedule($loan, $method = 'flat_rate'): array
```

Calculates repayment schedule using:
- `calculateFlatRateSchedule($loan)`
- `calculateReducingEqualInstallmentSchedule($loan)`
- `calculateReducingEqualPrincipalSchedule($loan)`

##### Settlement Processing

```php
processSettleRepayment($loanId, float $amount, array $paymentData): array
```

Processes full loan settlement:
1. Pays current interest
2. Pays all remaining principal
3. Creates GL transactions
4. Closes loan if fully paid

##### Penalty Management

```php
removePenalty($scheduleId, $reason = null, $amount = null, $loanId = null): array
checkAndRemovePenaltyForOnTimePayment($schedule, $paymentData): void
``````


##### Repayment Deletion

```php
deleteRepayment($repaymentId): array
```

Handles:
- Receipt deletion
- Journal entry deletion
- GL transaction reversal
- Cash deposit restoration
- Loan status updates

##### Helper Methods

```php
getUnpaidSchedules($loan): Collection
getRepaymentOrder($loan): array
getPaidAmountsForSchedule($schedule): array
createRepaymentRecord($loan, $schedule, $schedulePayment, $paymentData): Repayment
createReceiptAndGL($loan, $repayment, $schedulePayment, $paymentData): void
createJournalEntry($loan, $repayment, $schedulePayment, $paymentData): void
getChartAccounts($loan): array
isLoanFullyPaid($loan): bool
```

---

## Routes

### Loan Routes

**Location**: `routes/web.php`

#### Main Loan Routes

```php
// Index and Listing
GET  /loans                    -> loans.index
GET  /loans/list               -> loans.list
GET  /loans/data               -> loans.data
GET  /loans/status/{status}    -> loans.by-status

// CRUD Operations
GET  /loans/create             -> loans.create
POST /loans                    -> loans.store
GET  /loans/{loan}             -> loans.show
GET  /loans/{encodedId}/edit   -> loans.edit
PUT  /loans/{encodedId}        -> loans.update
DELETE /loans/{loan}           -> loans.destroy

// Loan Application Routes
GET  /loans/application                    -> loans.application.index
GET  /loans/application/create             -> loans.application.create
POST /loans/application                    -> loans.application.store
GET  /loans/application/{encodedId}        -> loans.application.show
GET  /loans/application/{encodedId}/edit   -> loans.application.edit
PUT  /loans/application/{encodedId}        -> loans.application.update
PATCH /loans/application/{encodedId}/approve -> loans.application.approve
PATCH /loans/application/{encodedId}/reject  -> loans.application.reject
DELETE /loans/application/{encodedId}         -> loans.application.delete

// Approval Routes
POST /loans/{encodedId}/check      -> loans.check
POST /loans/{encodedId}/approve    -> loans.approve
POST /loans/{encodedId}/authorize  -> loans.authorize
POST /loans/{encodedId}/disburse   -> loans.disburse
POST /loans/{encodedId}/reject     -> loans.reject
POST /loans/{encodedId}/default    -> loans.default
POST /loans/{encodedId}/settle     -> loans.settle
POST /loans/change-status          -> loans.change-status

// Import/Export
POST /loans/import                    -> loans.import
GET  /loans/import-template           -> loans.import-template
GET  /loans/opening-balance/template  -> loans.opening-balance.template
POST /loans/opening-balance           -> loans.opening-balance.store
GET  /loans/{encodedId}/export-details -> loans.export-details

// Document Management
POST   /loan-files                    -> loan-documents.store
DELETE /loan-documents/{loanFile}     -> loan-documents.destroy

// Guarantor Management
POST   /loans/{loan}/guarantors       -> loans.addGuarantor
DELETE /loans/{loan}/guarantors/{guarantor} -> loans.removeGuarantor

// Special Operations
GET  /loans/{hashid}/writeoff         -> loans.writeoff
POST /loans/{hashid}/writeoff         -> loans.writeoff.confirm
GET  /loans/{encodedId}/fees-receipt  -> loans.fees_receipt
GET  /loans/chart-accounts/{type}     -> loans.chart-accounts
GET  /loans/writtenoff                -> loans.writtenoff
GET  /loans/writtenoff/data           -> loans.writtenoff.data
```

#### Repayment Routes

```php
// CRUD
GET    /repayments                    -> repayments.index
POST   /repayments                    -> repayments.store
GET    /repayments/{id}               -> repayments.show
GET    /repayments/{id}/edit         -> repayments.edit
PUT    /repayments/{id}               -> repayments.update
DELETE /repayments/{id}               -> repayments.destroy

// Special Operations
GET  /repayments/history/{loanId}              -> repayments.history
GET  /repayments/schedule/{scheduleId}         -> repayments.schedule-details
POST /repayments/remove-penalty/{scheduleId}   -> repayments.remove-penalty
POST /repayments/calculate-schedule/{loanId}   -> repayments.calculate-schedule
POST /repayments/bulk                          -> repayments.bulk
DELETE /repayments/bulk-delete                 -> repayments.bulk-delete
POST /repayments/settle-loan                   -> repayments.settle
GET  /repayments/{id}/print                    -> repayments.print
```

#### Loan Product Routes

```php
GET    /loan-products                    -> loan-products.index
GET    /loan-products/create             -> loan-products.create
POST   /loan-products                    -> loan-products.store
GET    /loan-products/{encodedId}        -> loan-products.show
GET    /loan-products/{encodedId}/edit  -> loan-products.edit
PUT    /loan-products/{encodedId}        -> loan-products.update
DELETE /loan-products/{encodedId}        -> loan-products.destroy
PATCH  /loan-products/{encodedId}/toggle-status -> loan-products.toggle-status
```

---

## Loan Lifecycle

### 1. Loan Application

**Status**: `applied`

- Customer submits loan application
- Loan created with status `applied`
- No disbursement occurs
- Approval workflow begins

**Controller Method**: `applicationStore()`

### 2. Approval Workflow

The approval workflow depends on the loan product's `approval_levels` configuration:

#### Two-Level Approval
1. **Level 1**: Approve → Status: `approved`
2. **Level 2**: Disburse → Status: `active`

#### Three-Level Approval
1. **Level 1**: Check → Status: `checked`
2. **Level 2**: Approve → Status: `approved`
3. **Level 3**: Disburse → Status: `active`

#### Four+ Level Approval
1. **Level 1**: Check → Status: `checked`
2. **Level 2**: Approve → Status: `approved`
3. **Level 3+**: Authorize → Status: `authorized`
4. **Final Level**: Disburse → Status: `active`

**Controller Method**: `approveLoan()`

### 3. Disbursement

**Status**: `active`

When a loan is disbursed:
1. Bank account is selected
2. Disbursement date is set
3. Repayment schedule is generated
4. Payment record is created
5. GL transactions are posted:
   - Credit: Bank Account
   - Debit: Principal Receivable Account
6. Release-date fees are processed
7. Penalty GL transactions (if applicable)
8. Matured interest is posted for past loans

**Controller Method**: `processLoanDisbursement()`

### 4. Active Loan

**Status**: `active`

- Loan is active and repayments can be made
- Repayment schedule is available
- Penalties may accrue on overdue payments
- Interest matures on schedule due dates

### 5. Loan Completion

**Status**: `completed`

- All principal is paid
- Loan is automatically closed
- Status changes from `active` to `completed`

**Method**: `closeLoan()`

### 6. Alternative Statuses

- **rejected**: Loan application was rejected
- **defaulted**: Loan marked as defaulted
- **written_off**: Loan written off

---

## Loan Repayment System

### Repayment Order

The repayment order is configured in the loan product's `repayment_order` field. Common orders:

- `penalty,interest,principal,fee`
- `interest,principal,penalty,fee`
- `principal,interest,penalty,fee`

### Repayment Processing Flow

1. **Validation**
   - Loan exists and is active
   - Amount is valid
   - Bank account is selected

2. **Get Unpaid Schedules**
   - Find schedules with remaining amounts
   - Order by due date

3. **Process Payment**
   - Apply payment according to repayment order
   - Create repayment records
   - Update schedule paid amounts

4. **Create Receipt**
   - Generate receipt voucher
   - Link to repayment

5. **GL Transactions**
   - Debit: Bank/Cash Account
   - Credit: Principal/Interest/Fee/Penalty Receivable Accounts

6. **Journal Entry**
   - Create double-entry journal
   - Link to repayment

7. **Loan Status Update**
   - Check if loan is fully paid
   - Close loan if applicable

### Settlement Repayment

Settlement repayment pays:
1. Current interest (from current unpaid schedule)
2. All remaining principal (from all schedules)

**Service Method**: `processSettleRepayment()`

### Penalty Management

Penalties are calculated based on:
- Penalty type (percentage or fixed)
- Deduction criteria (overdue principal, interest, or both)
- Due date vs payment date
- Grace period

Penalties can be:
- Automatically calculated on overdue schedules
- Manually removed with reason
- Automatically removed for on-time payments

---

## Loan Product Management

### Product Configuration

#### Interest Methods

1. **Flat Rate**
   - Simple interest on principal
   - Interest = Principal × Rate × Period

2. **Reducing Balance - Equal Installment**
   - EMI calculation
   - Equal monthly installments
   - Interest calculated on reducing balance

3. **Reducing Balance - Equal Principal**
   - Equal principal payments
   - Interest calculated on reducing balance
   - Decreasing total payment

#### Interest Cycles

- Daily
- Weekly
- Monthly
- Quarterly
- Semi-Annually
- Annually

#### Collateral Requirements

- **Type**: Cash deposit, property, etc.
- **Value Type**: Percentage or fixed amount
- **Value**: Percentage or fixed amount

#### Top-up Configuration

- **Type**: percentage, fixed_amount, number_of_installments
- **Value**: Based on type
- **Eligibility**: Based on payment percentage/amount/installments

#### Approval Levels

- Configurable role-based approval
- Stored as comma-separated role IDs
- Each level requires specific role

#### Chart Account Integration

Each product links to:
- Principal Receivable Account
- Interest Receivable Account
- Interest Revenue Account
- Direct Writeoff Account (optional)
- Provision Writeoff Account (optional)
- Income Provision Account (optional)

---

## Loan Schedule System

### Schedule Generation

Schedules are generated when a loan is disbursed using `generateRepaymentSchedule()`:

1. **Calculate Installments**
   - Based on interest method
   - Based on period and interest cycle

2. **Apply Fees**
   - Distribute fees based on deduction criteria:
     - `distribute_fee_evenly_to_all_repayments`
     - `charge_same_fee_to_all_repayments`
     - `charge_fee_on_first_repayment`
     - `charge_fee_on_last_repayment`
     - `charge_fee_on_release_date`
     - `do_not_include_in_loan_schedule`

3. **Calculate Penalties**
   - Based on penalty configuration
   - Applied if payment is overdue

4. **Set Dates**
   - Due date
   - End date (due date + 5 days)
   - End grace date (due date + grace period)
   - End penalty date

### Schedule Accessors

- `paid_amount`: Total paid for this schedule
- `remaining_amount`: Amount still due
- `is_fully_paid`: Whether schedule is complete
- `total_due`: Principal + Interest + Fees + Penalties

---

## Approval Workflow

### Dynamic Approval System

The approval system is dynamic and based on the loan product's `approval_levels` configuration.

#### Approval Actions

- **check**: First level approval
- **approve**: Second level approval
- **authorize**: Intermediate level approvals
- **disburse**: Final approval and disbursement

#### Approval Process

1. **Check Permissions**
   ```php
   $loan->canBeApprovedByUser($user)
   ```

2. **Determine Next Action**
   ```php
   $nextAction = $loan->getNextApprovalAction();
   ```

3. **Update Status**
   - Based on action, update loan status

4. **Create Approval Record**
   - Store approval in `loan_approvals` table
   - Record user, role, level, comments, timestamp

5. **Process Disbursement** (if applicable)
   - Generate schedule
   - Create payment records
   - Post GL transactions

### Approval Record Structure

```php
LoanApproval {
    loan_id
    user_id
    role_name
    approval_level
    action (checked, approved, authorized, rejected, active)
    comments
    approved_at
}
```

---

## Features and Operations

### Loan Import

**Method**: `importLoans()`

Supports CSV/XLSX import with columns:
- customer_no
- amount
- period
- interest
- date_applied
- interest_cycle
- loan_officer
- group_id
- sector

Features:
- Validation of all fields
- Product limits checking
- Collateral validation
- Duplicate loan checking
- Error reporting with tips
- Skip errors option

### Opening Balance Import

**Method**: `storeOpeningBalance()`

For importing existing loans with:
- Customer information
- Loan amounts
- Payment history
- Disbursement dates

### Loan Write-off

**Method**: `writeoff()`

Supports two write-off types:
1. **Direct Write-off**: Immediate write-off
2. **Provision Write-off**: Through provision account

Creates GL transactions:
- Debit: Write-off account
- Credit: Principal receivable account

### Loan Top-up

Top-up loans are created when:
- Customer has paid required percentage/amount/installments
- Loan is active
- No arrears
- Product allows top-ups

Top-up amount = Outstanding balance of parent loan

### Guarantor Management

- Add guarantors to loans
- Track guarantor relationships
- Store relation type (family, friend, business partner, etc.)

### Document Management

- Upload loan documents
- Multiple file types supported
- Link to file types
- Download and view documents

### Fees Receipt

**Method**: `feesReceipt()`

Creates receipt vouchers for loan fees:
- Release-date fees
- Excluded fees (not in schedule)
- Links to chart accounts
- Creates GL transactions

### Loan Reports

Multiple report types available:
- Loan Disbursement Report
- Loan Repayment Report
- Loan Aging Report
- Portfolio Tracking Report
- Loan Arrears Report
- Expected vs Collected Report
- Portfolio at Risk (PAR) Report
- Internal Portfolio Analysis Report
- Non-Performing Loan (NPL) Report

---

## Accounting Integration

### GL Transactions

The system creates GL transactions for:

#### Loan Disbursement
- Credit: Bank Account (disbursement amount)
- Debit: Principal Receivable Account (loan amount)

#### Release-Date Fees
- Credit: Fee Income Account
- Debit: Bank Account

#### Penalty (on disbursement)
- Debit: Penalty Receivable Account
- Credit: Penalty Income Account

#### Mature Interest
- Debit: Interest Receivable Account
- Credit: Interest Revenue Account

#### Repayment
- Debit: Bank/Cash Account
- Credit: Principal/Interest/Fee/Penalty Receivable Accounts

#### Settlement
- Debit: Bank Account
- Credit: Interest Receivable/Revenue Account (interest)
- Credit: Principal Receivable Account (principal)

#### Write-off
- Debit: Write-off Account
- Credit: Principal Receivable Account

### Journal Entries

Journal entries are created for:
- Loan disbursements
- Repayments
- Fee charges
- Penalty charges

Each journal entry contains:
- Reference to loan/repayment
- Customer information
- Branch information
- Date
- Description
- Journal items (debit/credit entries)

---

## Security and Permissions

### Permission Checks

The system uses Laravel's permission system with checks for:
- `view loan details`
- `edit loan`
- `delete loan`
- `create receipt voucher`
- Role-based approval permissions

### Branch Scoping

Loans are scoped to user's branch:
- Users can only see loans from their branch
- Branch ID is automatically set from authenticated user

### Company Scoping

Multi-tenant support:
- Loans belong to companies
- Users belong to companies
- Data isolation between companies

---

## Best Practices

### Loan Creation

1. Always validate product limits
2. Check collateral requirements
3. Verify customer eligibility
4. Validate maximum loan limits
5. Set appropriate interest cycle

### Repayment Processing

1. Always use `LoanRepaymentService`
2. Validate amounts before processing
3. Check repayment order
4. Handle errors gracefully
5. Update loan status appropriately

### Approval Workflow

1. Check user permissions
2. Validate loan status
3. Create approval records
4. Log all actions
5. Send notifications if applicable

### Schedule Generation

1. Use product's interest method
2. Apply fees correctly
3. Set dates based on interest cycle
4. Calculate penalties accurately
5. Handle grace periods

---

## Troubleshooting

### Common Issues

#### Loan Not Disbursing
- Check bank account is selected
- Verify approval levels are complete
- Check product configuration

#### Schedule Not Generating
- Verify loan is disbursed
- Check interest method is set
- Validate period and interest cycle

#### Repayment Not Processing
- Check loan status is active
- Verify amount is valid
- Check repayment order configuration

#### GL Transactions Missing
- Verify chart accounts are configured
- Check product account mappings
- Validate transaction creation logic

---

## Conclusion

This documentation covers the complete loan management system including database structure, models, controllers, services, routes, and operational workflows. The system is designed to be flexible, scalable, and integrated with accounting systems.

For additional information or support, refer to:
- Code comments in respective files
- Laravel documentation
- Accounting system documentation
- API documentation (if applicable)

