#!/usr/bin/env bash
# Smoke-check loandev non-DCB sync features after MySQL is up.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Migrate"
php artisan migrate --force

echo "==> Routes"
php artisan route:list | grep -E 'customer_statement|group_repayment|principal-loans|rmw\.deposit|sms-reminder|waive-accrued' || true

echo "==> sms_logs.sms_type column"
php artisan tinker --execute="echo Schema::hasColumn('sms_logs','sms_type') ? 'sms_type: OK' : 'sms_type: MISSING';"

echo "==> Key methods"
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach ([
  [App\Http\Controllers\DashboardController::class, 'principalLoans'],
  [App\Http\Controllers\LoanReportController::class, 'customerLoanStatementReport'],
  [App\Http\Controllers\LoanReportController::class, 'groupRepaymentScheduleReport'],
  [App\Http\Controllers\CashCollateralController::class, 'rmwDeposit'],
  [App\Http\Controllers\LoanRepaymentController::class, 'waiveAccruedInterest'],
  [App\Http\Controllers\SettingsController::class, 'smsReminderLogsData'],
] as [\$c, \$m]) {
  echo (method_exists(\$c, \$m) ? 'OK' : 'MISSING') . \" \$c::\$m\\n\";
}
"

echo "==> Manual UI checks"
cat <<'EOF'
1. Dashboard → click Principal Disbursed → /dashboard/principal-loans
2. Reports → Loans → Customer Loan Statement
3. Reports → Loans → Group Repayment Schedule
4. Cash Collaterals → Bulk / RMW Deposit
5. Settings → System → SMS Reminders → Reminder SMS Log
6. Loan show → Schedule → Waive Interest (when unpaid accrued interest exists)
EOF

echo "Done."
