# Bank Accounts Queries Using Branch - Analysis

This document lists all places where bank accounts are queried and should be filtered by branch using the `bank_branches` pivot table relationship.

## Summary
- **Total locations found**: 20+ places
- **Critical locations**: 15+ that need immediate branch filtering
- **Report locations**: 5+ that may need branch filtering for data security

---

## 1. LoanController.php

### Location 1: `index()` method (Line 80)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Loan listing page - shows all bank accounts
**Action Needed**: Filter by user's assigned branches
**Suggested Fix**: 
```php
$user = auth()->user();
$userBranchIds = $user->branches()->pluck('branches.id')->toArray();
$bankAccounts = BankAccount::whereHas('branches', function($q) use ($userBranchIds) {
    $q->whereIn('branches.id', $userBranchIds);
})->get();
```

### Location 2: `loansByStatus()` method (Line 299)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Loans filtered by status
**Action Needed**: Filter by user's assigned branches
**Suggested Fix**: Same as Location 1

### Location 3: `getChartAccountsByType()` method (Lines 554, 582)
```php
// Line 554 - for new loans
$accounts = BankAccount::whereHas('chartAccount.accountClassGroup', function ($query) {
    // ... chart account filters
})->get();

// Line 582 - for old loans  
$accounts = BankAccount::whereHas('chartAccount.accountClassGroup', function ($query) {
    // ... chart account filters
})->get();
```
**Context**: AJAX endpoint to get bank accounts by loan type
**Action Needed**: Filter by user's assigned branches
**Suggested Fix**: Add branch filter to whereHas chain

### Location 4: `storeOpeningBalance()` method (Line 1445)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Opening balance creation
**Action Needed**: Filter by user's assigned branches

### Location 5: `downloadOpeningBalanceTemplate()` method (Line 1470)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Opening balance template download
**Action Needed**: Filter by user's assigned branches

### Location 6: `store()` method (Line 1806)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Loan creation
**Action Needed**: Filter by user's assigned branches

### Location 7: `update()` method (Line 2218)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Loan update
**Action Needed**: Filter by user's assigned branches

### Location 8: `topUp()` method (Line 2386)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Loan top-up
**Action Needed**: Filter by user's assigned branches

### Location 9: `writeOff()` method (Line 2575)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Loan write-off
**Action Needed**: Filter by user's assigned branches

### Location 10: `reverseWriteOff()` method (Line 2604)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Reverse write-off
**Action Needed**: Filter by user's assigned branches

---

## 2. CashCollateralController.php

### Location 11: `create()` method (Line 370)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Cash collateral creation form
**Action Needed**: Filter by user's assigned branches

### Location 12: `edit()` method (Line 511)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Cash collateral edit form
**Action Needed**: Filter by user's assigned branches

### Location 13: `store()` method (Line 755)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Cash collateral store
**Action Needed**: Filter by user's assigned branches

### Location 14: `update()` method (Line 882)
```php
$bankAccounts = BankAccount::all();
```
**Context**: Cash collateral update
**Action Needed**: Filter by user's assigned branches

---

## 3. Accounting/Reports/CashBookReportController.php

### Location 15: `index()` method (Line 32)
```php
$bankAccounts = DB::table('bank_accounts')
    ->join('chart_accounts', 'bank_accounts.chart_account_id', '=', 'chart_accounts.id')
    ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
    ->where('account_class_groups.company_id', $company->id)
    ->select('bank_accounts.*', 'chart_accounts.account_name')
    ->get();
```
**Context**: Cash book report - bank account filter dropdown
**Action Needed**: Filter by user's assigned branches
**Suggested Fix**: Add join with bank_branches and filter

---

## 4. Accounting/ReceiptVoucherController.php

### Location 16: `create()` method (Line 175)
```php
$bankAccounts = BankAccount::with('chartAccount')
```
**Context**: Receipt voucher creation
**Action Needed**: Filter by user's assigned branches

### Location 17: `edit()` method (Line 424)
```php
$bankAccounts = BankAccount::with('chartAccount')
```
**Context**: Receipt voucher edit
**Action Needed**: Filter by user's assigned branches

### Location 18: `show()` method (Line 760)
```php
$bankAccounts = BankAccount::with('chartAccount')
```
**Context**: Receipt voucher show
**Action Needed**: Filter by user's assigned branches (if used in form)

---

## 5. Accounting/PaymentVoucherController.php

### Location 19: `create()` method (Line 198)
```php
$bankAccounts = BankAccount::with('chartAccount')
```
**Context**: Payment voucher creation
**Action Needed**: Filter by user's assigned branches

### Location 20: `edit()` method (Line 418)
```php
$bankAccounts = BankAccount::with('chartAccount')
```
**Context**: Payment voucher edit
**Action Needed**: Filter by user's assigned branches

---

## 6. Accounting/BillPurchaseController.php

### Location 21: `create()` method (Line 356)
```php
$bankAccounts = BankAccount::orderBy('name')->get();
```
**Context**: Bill purchase creation
**Action Needed**: Filter by user's assigned branches

### Location 22: `edit()` method (Line 471)
```php
$bankAccounts = BankAccount::orderBy('name')->get();
```
**Context**: Bill purchase edit
**Action Needed**: Filter by user's assigned branches

---

## 7. Reports Controllers (May need filtering for data security)

### BotLiquidAssetsController.php (Lines 25, 109)
```php
$bankAccounts = BankAccount::all();
```
**Context**: BOT liquid assets report
**Action Needed**: Consider filtering by branch for multi-branch companies

### BotDepositsBorrowingsController.php (Lines 67, 227)
```php
$bankAccounts = BankAccount::all();
```
**Context**: BOT deposits/borrowings report
**Action Needed**: Consider filtering by branch

### BotBalanceSheetController.php (Lines 25, 148)
```php
$bankAccounts = BankAccount::all();
```
**Context**: BOT balance sheet report
**Action Needed**: Consider filtering by branch

---

## 8. API Endpoints

### Api/BankAccountController.php (Line 13)
```php
$accounts = BankAccount::select('id', 'name', 'account_number')->get();
```
**Context**: API endpoint for bank accounts
**Action Needed**: Filter by authenticated user's branches

---

## Recommended Helper Method

Create a scope in `BankAccount` model to filter by user's branches:

```php
// In app/Models/BankAccount.php

/**
 * Scope to filter bank accounts by user's assigned branches
 */
public function scopeForUserBranches($query, $user = null)
{
    $user = $user ?? auth()->user();
    if (!$user) {
        return $query;
    }
    
    $userBranchIds = $user->branches()->pluck('branches.id')->toArray();
    
    if (empty($userBranchIds)) {
        // If user has no branches, return empty
        return $query->whereRaw('1 = 0');
    }
    
    return $query->whereHas('branches', function($q) use ($userBranchIds) {
        $q->whereIn('branches.id', $userBranchIds);
    });
}
```

Then use it like:
```php
$bankAccounts = BankAccount::forUserBranches()->get();
```

---

## Priority Order for Updates

1. **High Priority** (User-facing forms):
   - LoanController (all methods)
   - CashCollateralController
   - ReceiptVoucherController
   - PaymentVoucherController
   - BillPurchaseController

2. **Medium Priority** (Reports):
   - CashBookReportController
   - LoanController::getChartAccountsByType (AJAX endpoint)

3. **Low Priority** (System reports):
   - BOT report controllers (may need company-wide access)
   - API endpoints

---

## Notes

- All queries should respect the `bank_branches` pivot table relationship
- Users should only see bank accounts assigned to their branches
- Consider adding a global scope if all bank account queries should be branch-filtered
- Test thoroughly to ensure no functionality breaks after adding branch filters
