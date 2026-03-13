<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CashCollateral;
use App\Models\Customer;
use App\Models\Filetype;
use App\Models\GlTransaction;
use App\Models\Group;
use App\Models\Loan;
use App\Models\LoanTopup;
use App\Models\LoanApproval;
use App\Models\LoanFile;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\ChartAccount;
use App\Models\Payment;
use App\Models\PaymentItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;


class LoanTopUpController extends Controller
{
    /**
     * Show the loan top-up form.
     */
    public function show($encodedId)
    {
        $decoded = \Vinkla\Hashids\Facades\Hashids::decode($encodedId);
        if (empty($decoded)) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }
        $loan = Loan::find($decoded[0]);
        if (!$loan) {
            return redirect()->route('loans.list')->withErrors(['Loan not found.']);
        }
        $loan->encodedId = $encodedId;
        return view('loans.top_up', compact('loan'));
    }

    /**
     * Handle the loan top-up submission.
     */
    public function store(Request $request, $encodedId)
    {
        try {
            // Debug logging
            Log::info('Top-up request received', [
                'encoded_id' => $encodedId,
                'request_data' => $request->all(),
                'is_ajax' => $request->ajax()
            ]);
            
        $decoded = \Vinkla\Hashids\Facades\Hashids::decode($encodedId);
            Log::info('Decoded ID', ['decoded' => $decoded]);
            
            if (empty($decoded)) {
                Log::error('Failed to decode loan ID', ['encoded_id' => $encodedId]);
                if ($request->ajax()) {
                    return response()->json(['success' => false, 'message' => 'Loan not found - invalid ID.']);
                }
                return redirect()->route('loans.list')->withErrors(['Loan not found.']);
            }
            
            $loan = Loan::find($decoded[0]);
            if (!$loan) {
                if ($request->ajax()) {
                    return response()->json(['success' => false, 'message' => 'Loan not found.']);
                }
                return redirect()->route('loans.list')->withErrors(['Loan not found.']);
            }

            $balanceBreakdown = $loan->getTopUpBalanceBreakdown();
            $totalBalance = $balanceBreakdown['total_balance'] ?? $loan->getCalculatedTopUpAmount();
            $currentBalance = $loan->getCalculatedTopUpAmount();

            // Validate the request
            $rules = [
                'new_loan_amount' => ['required', 'numeric', 'min:1'],
                'purpose' => 'required|string|max:500',
                'period' => 'required|integer|min:1|max:60',
                'interest' => ['required', 'numeric', 'min:0'],
                'bank_account_id' => 'nullable|exists:bank_accounts,id',
            ];
            $request->validate($rules);

            $newLoanAmount = (float) $request->new_loan_amount;
            $topupType = 'restructure';
            $newPeriod = (int) $request->period; // total period like create-loan
            $newInterest = (float) $request->interest;

            // Enforce product limits for interest and total period
            $product = $loan->product;
            if ($product) {
                // Check new total period within product min/max
                if (!$product->isPeriodWithinLimits($newPeriod)) {
                    $message = 'Total period must be within product limits (' .
                        $product->minimum_period . ' - ' . $product->maximum_period . ' months).';

                    if ($request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'message' => $message,
                        ]);
                    }

                    return redirect()->back()
                        ->withErrors(['period' => $message])
                        ->withInput();
                }

                // Check interest within product min/max range
                if ($newInterest < (float) $product->minimum_interest_rate || $newInterest > (float) $product->maximum_interest_rate) {
                    $message = 'Interest rate must be within product limits (' .
                        $product->minimum_interest_rate . '% - ' . $product->maximum_interest_rate . '%).';

                    if ($request->ajax()) {
                        return response()->json([
                            'success' => false,
                            'message' => $message,
                        ]);
                    }

                    return redirect()->back()
                        ->withErrors(['interest' => $message])
                        ->withInput();
                }
            }

            if ($newLoanAmount < $totalBalance) {
                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'New loan amount must be greater than or equal to the capitalized amount (TZS ' . number_format($totalBalance, 2) . ').',
                    ]);
                }
                return redirect()->back()->withErrors(['new_loan_amount' => 'New loan amount must be at least the total balance.']);
            }

            $bankAccountId = $request->filled('bank_account_id')
                ? (int) $request->bank_account_id
                : $loan->bank_account_id;

            DB::beginTransaction();

            // RESTRUCTURE: Close old loan, create new larger loan
            $customerReceives = max(0, $newLoanAmount - $totalBalance);

            // Create new loan (replaces old loan)
            $newLoan = Loan::create([
                'customer_id'      => $loan->customer_id,
                'group_id'         => $loan->group_id,
                'product_id'       => $loan->product_id,
                'amount'           => $newLoanAmount,
                'interest'         => $newInterest,
                'period'           => $newPeriod,
                'bank_account_id'  => $bankAccountId,
                'date_applied'     => now(),
                'disbursed_on'     => now(),
                'status'           => 'active',
                'sector'           => $loan->sector,
                'interest_cycle'   => $loan->interest_cycle,
                'loan_officer_id'  => $loan->loan_officer_id,
                'branch_id'        => $loan->branch_id,
                'top_up_id'        => $loan->id,
                'description'      => $request->purpose,
            ]);

            // Calculate interest and update loan
            $interestAmount = $newLoan->calculateInterestAmount($newLoan->interest);
            $repaymentDates = $newLoan->getRepaymentDates();
            $newLoan->update([
                'interest_amount' => $interestAmount,
                'amount_total' => $newLoan->amount + $interestAmount,
                'first_repayment_date' => $repaymentDates['first_repayment_date'],
                'last_repayment_date' => $repaymentDates['last_repayment_date'],
            ]);

            // Generate repayment schedule
            $newLoan->generateRepaymentSchedule($newLoan->interest);

            // Create GL Transactions for Restructure Top-Up (use totalBalance for amount being capitalized)
            $this->createRestructureTopUpGlTransactions($loan, $newLoan, $totalBalance, $customerReceives);

            // Close the old loan
            $loan->update(['status' => 'restructured']);

            // Create top-up record
            LoanTopup::create([
                'old_loan_id'   => $loan->id,
                'new_loan_id'   => $newLoan->id,
                'old_balance'   => $totalBalance,
                'topup_amount'  => $customerReceives,
                'topup_type'    => 'restructure',
            ]);

            DB::commit();

            $loanTopupPayload = [
                'new_loan_amount' => (float) $newLoan->amount,
                'customer_receives' => (float) $customerReceives,
                'topup_type' => $topupType,
                'capitalized_amount' => (float) $totalBalance,
                'outstanding_principal' => (float) ($balanceBreakdown['outstanding_principal'] ?? 0),
                'outstanding_interest' => (float) ($balanceBreakdown['outstanding_interest'] ?? 0),
                'outstanding_penalty' => (float) ($balanceBreakdown['outstanding_penalty'] ?? 0),
            ];

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Top-up loan created successfully!',
                    'new_loan_id' => $newLoan->id,
                    'new_loan_encoded_id' => Hashids::encode($newLoan->id),
                    'loan_topup' => $loanTopupPayload,
                ]);
            }

            return redirect()->route('loans.show', $encodedId)
                ->with('success', 'Loan top-up submitted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Top-up creation failed: ' . $e->getMessage());
            
            if ($request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Failed to create top-up loan. Please try again.']);
            }
            
            return redirect()->back()->withErrors(['Failed to create top-up loan. Please try again.']);
        }
    }

    /**
     * Create GL transactions for restructure top-up loan
     */
    private function createRestructureTopUpGlTransactions($oldLoan, $newLoan, $currentBalance, $customerReceives)
    {
        $userId = auth()->id() ?? 1;
        $branchId = auth()->user()->branch_id ?? 1;
        $product = $oldLoan->product;
        $bankAccount = $newLoan->bankAccount ?? $oldLoan->bankAccount;

        // Use a single transaction id/type for the whole top-up so debits/credits are grouped together
        $transactionId = $newLoan->id;
        $transactionType = 'Loan Top-Up - Restructure';

        // 1. Close old loan receivable (Credit the old loan receivable)
        GlTransaction::create([
            'chart_account_id' => $product->principal_receivable_account_id,
            'customer_id' => $oldLoan->customer_id,
            'amount' => $currentBalance,
            'nature' => 'credit',
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'date' => now(),
            'description' => "Restructure Top-up: Close old loan receivable (Old Loan #{$oldLoan->id})",
            'branch_id' => $branchId,
            'user_id' => $userId,
        ]);

        // 2. Create new loan receivable (Debit the new loan receivable)
        GlTransaction::create([
            'chart_account_id' => $product->principal_receivable_account_id,
            'customer_id' => $newLoan->customer_id,
            'amount' => $newLoan->amount,
            'nature' => 'debit',
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'date' => now(),
            'description' => "Restructure Top-up: Create new loan receivable (New Loan #{$newLoan->id})",
            'branch_id' => $branchId,
            'user_id' => $userId,
        ]);

        // 3. Disburse cash to customer (Credit bank account for amount customer receives)
        if ($customerReceives > 0 && $bankAccount) {
            GlTransaction::create([
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $newLoan->customer_id,
                'amount' => $customerReceives,
                'nature' => 'credit',
                'transaction_id' => $transactionId,
                'transaction_type' => $transactionType,
                'date' => now(),
                'description' => "Restructure Top-up: Cash disbursement to customer (New Loan #{$newLoan->id})",
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]);
        }

        Log::info('Restructure Top-up GL transactions created', [
            'old_loan_id' => $oldLoan->id,
            'new_loan_id' => $newLoan->id,
            'current_balance' => $currentBalance,
            'customer_receives' => $customerReceives,
            'new_loan_amount' => $newLoan->amount
        ]);
    }

    /**
     * Create GL transactions for additional top-up loan
     */
    private function createAdditionalTopUpGlTransactions($oldLoan, $newLoan, $customerReceives)
    {
        $userId = auth()->id() ?? 1;
        $branchId = auth()->user()->branch_id ?? 1;
        $product = $oldLoan->product;
        $bankAccount = $newLoan->bankAccount ?? $oldLoan->bankAccount;

        // Use a single transaction id/type for the whole additional top-up
        $transactionId = $newLoan->id;
        $transactionType = 'Loan Top-Up - Additional';

        // 1. Create new loan receivable (Debit the new loan receivable)
        GlTransaction::create([
            'chart_account_id' => $product->principal_receivable_account_id,
            'customer_id' => $newLoan->customer_id,
            'amount' => $newLoan->amount,
            'nature' => 'debit',
            'transaction_id' => $transactionId,
            'transaction_type' => $transactionType,
            'date' => now(),
            'description' => "Additional Top-up: Create new loan receivable (Loan #{$newLoan->id})",
            'branch_id' => $branchId,
            'user_id' => $userId,
        ]);

        // 2. Disburse cash to customer (Credit bank account for full amount)
        if ($bankAccount) {
            GlTransaction::create([
                'chart_account_id' => $bankAccount->chart_account_id,
                'customer_id' => $newLoan->customer_id,
                'amount' => $customerReceives,
                'nature' => 'credit',
                'transaction_id' => $transactionId,
                'transaction_type' => $transactionType,
                'date' => now(),
                'description' => "Additional Top-up: Cash disbursement to customer (Loan #{$newLoan->id})",
                'branch_id' => $branchId,
                'user_id' => $userId,
            ]);
        }

        Log::info('Additional Top-up GL transactions created', [
            'old_loan_id' => $oldLoan->id,
            'new_loan_id' => $newLoan->id,
            'customer_receives' => $customerReceives,
            'new_loan_amount' => $newLoan->amount
        ]);
    }
}
