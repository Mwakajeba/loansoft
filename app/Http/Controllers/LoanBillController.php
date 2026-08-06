<?php

namespace App\Http\Controllers;

use App\Models\ChartAccount;
use App\Models\GlTransaction;
use App\Models\Loan;
use App\Models\LoanBill;
use App\Services\LoanRepaymentService;
use App\Services\LoanSmsNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Vinkla\Hashids\Facades\Hashids;

class LoanBillController extends Controller
{
    private const RECEIVABLE_ACCOUNT_CODE = '1144';

    private const INCOME_ACCOUNT_CODE = '4433';

    public function create($encodedId)
    {
        $loan = $this->findLoan($encodedId);
        $companyId = auth()->user()?->company_id;

        $accounts = ChartAccount::with('accountClassGroup.accountClass')
            ->whereHas('accountClassGroup', fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('account_code')
            ->get()
            ->values();

        // Template with placeholders so the preview can update live as the form is filled.
        $currentOutstanding = $loan->getTotalOutstandingAmount();
        $smsTemplate = app(LoanSmsNotificationService::class)->buildLoanBillMessage(
            $loan,
            0,
            '%DESCRIPTION%',
            $currentOutstanding
        );
        $smsTemplate = str_replace('una bili ya Tsh 0', 'una bili ya Tsh %AMOUNT%', $smsTemplate);
        $smsTemplate = str_replace(
            'Jumla ya salio la mkopo pamoja na bili hii ni Tsh '.number_format($currentOutstanding, 0),
            'Jumla ya salio la mkopo pamoja na bili hii ni Tsh %OUTSTANDING%',
            $smsTemplate
        );

        return view('loans.bills.create', [
            'loan' => $loan,
            'encodedId' => $encodedId,
            'accounts' => $accounts,
            'defaultReceivableAccount' => $accounts->firstWhere('account_code', self::RECEIVABLE_ACCOUNT_CODE),
            'defaultIncomeAccount' => $accounts->firstWhere('account_code', self::INCOME_ACCOUNT_CODE),
            'smsTemplate' => $smsTemplate,
            'customerPhone' => $loan->customer->phone1 ?? null,
            'currentOutstanding' => $currentOutstanding,
        ]);
    }

    public function store(Request $request, $encodedId)
    {
        $loan = $this->findLoan($encodedId);
        $user = auth()->user();
        $companyId = $user?->company_id;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'bill_date' => 'required|date',
            'receivable_account_id' => 'required|integer',
            'income_account_id' => 'required|integer|different:receivable_account_id',
        ]);

        $accounts = ChartAccount::whereHas(
            'accountClassGroup',
            fn ($query) => $query->where('company_id', $companyId)
        )
            ->whereIn('id', [
                $validated['receivable_account_id'],
                $validated['income_account_id'],
            ])
            ->get()
            ->keyBy('id');
        $receivableAccount = $accounts->get((int) $validated['receivable_account_id']);
        $incomeAccount = $accounts->get((int) $validated['income_account_id']);

        if (! $receivableAccount || ! $incomeAccount) {
            return back()->withErrors([
                'accounts' => 'One or more selected chart accounts are invalid for this company.',
            ])->withInput();
        }

        $amount = round((float) $validated['amount'], 2);
        $branchId = (int) ($user?->branch_id ?? $loan->branch_id);

        $bill = DB::transaction(function () use (
            $loan,
            $validated,
            $amount,
            $branchId,
            $user,
            $receivableAccount,
            $incomeAccount
        ) {
            $bill = LoanBill::create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'paid_amount' => 0,
                'description' => $validated['description'],
                'bill_date' => $validated['bill_date'],
                'receivable_account_id' => $receivableAccount->id,
                'income_account_id' => $incomeAccount->id,
                'status' => LoanBill::STATUS_PENDING,
                'due_date' => null,
                'notes' => null,
                'created_by' => $user->id,
            ]);

            $description = "Loan bill: {$bill->description} (Loan {$loan->loanNo})";

            // Accrue the bill: Dr customer receivable, Cr bill income.
            GlTransaction::create([
                'chart_account_id' => $bill->receivable_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $amount,
                'nature' => 'debit',
                'transaction_id' => $bill->id,
                'transaction_type' => 'loan_bill',
                'date' => $bill->bill_date,
                'description' => $description,
                'branch_id' => $branchId,
                'user_id' => $user->id,
            ]);

            GlTransaction::create([
                'chart_account_id' => $bill->income_account_id,
                'customer_id' => $loan->customer_id,
                'amount' => $amount,
                'nature' => 'credit',
                'transaction_id' => $bill->id,
                'transaction_type' => 'loan_bill',
                'date' => $bill->bill_date,
                'description' => $description,
                'branch_id' => $branchId,
                'user_id' => $user->id,
            ]);

            return $bill;
        });

        app(LoanSmsNotificationService::class)->sendLoanBillNotification($loan, $bill);

        return redirect()
            ->route('loans.show', $encodedId)
            ->with('success', 'Loan bill created successfully. SMS notification processed.');
    }

    public function pay(Request $request, $encodedBillId)
    {
        $decoded = Hashids::decode($encodedBillId);
        if (empty($decoded)) {
            return back()->withErrors(['Bill not found.']);
        }

        $bill = LoanBill::with('loan')->findOrFail($decoded[0]);
        $loan = $bill->loan;

        if (! $bill->isOpen()) {
            return back()->withErrors(['This bill is already paid or cancelled.']);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.((float) $bill->remaining_amount),
            'payment_date' => 'required|date',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'comments' => 'nullable|string|max:500',
        ]);

        try {
            $result = app(LoanRepaymentService::class)->processBillPayment(
                $bill,
                (float) $validated['amount'],
                [
                    'payment_date' => $validated['payment_date'],
                    'bank_account_id' => $validated['bank_account_id'],
                    'comments' => $validated['comments'] ?? ('Loan bill: '.$bill->description),
                    'user_id' => auth()->id(),
                    'branch_id' => auth()->user()?->branch_id ?? $loan->branch_id,
                ]
            );

            $bill->refresh();
            app(LoanSmsNotificationService::class)->sendLoanBillPaymentNotification(
                $bill,
                (float) $result['paid_amount']
            );

            return back()->with(
                'success',
                'Bill payment of TZS '.number_format($result['paid_amount'], 2).' recorded. SMS notification processed.'
            );
        } catch (\Throwable $e) {
            Log::error('Loan bill payment failed', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['Failed to record bill payment: '.$e->getMessage()]);
        }
    }

    public function destroy($encodedBillId)
    {
        $decoded = Hashids::decode($encodedBillId);
        if (empty($decoded)) {
            return back()->withErrors(['Bill not found.']);
        }

        $bill = LoanBill::with('loan')->findOrFail($decoded[0]);

        if ((float) $bill->paid_amount > 0) {
            return back()->withErrors(['Cannot cancel a bill that has payments.']);
        }

        if ($bill->status === LoanBill::STATUS_CANCELLED) {
            return back()->withErrors(['This bill is already cancelled.']);
        }

        DB::transaction(function () use ($bill) {
            $user = auth()->user();
            $loan = $bill->loan;
            $branchId = (int) ($user?->branch_id ?? $loan->branch_id);
            $hasAccrual = GlTransaction::where('transaction_id', $bill->id)
                ->where('transaction_type', 'loan_bill')
                ->exists();
            $hasReversal = GlTransaction::where('transaction_id', $bill->id)
                ->where('transaction_type', 'loan_bill_reversal')
                ->exists();

            if ($hasAccrual && ! $hasReversal && $bill->receivable_account_id && $bill->income_account_id) {
                $description = "Cancelled loan bill: {$bill->description} (Loan {$loan->loanNo})";

                GlTransaction::create([
                    'chart_account_id' => $bill->income_account_id,
                    'customer_id' => $loan->customer_id,
                    'amount' => $bill->amount,
                    'nature' => 'debit',
                    'transaction_id' => $bill->id,
                    'transaction_type' => 'loan_bill_reversal',
                    'date' => now(),
                    'description' => $description,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                ]);

                GlTransaction::create([
                    'chart_account_id' => $bill->receivable_account_id,
                    'customer_id' => $loan->customer_id,
                    'amount' => $bill->amount,
                    'nature' => 'credit',
                    'transaction_id' => $bill->id,
                    'transaction_type' => 'loan_bill_reversal',
                    'date' => now(),
                    'description' => $description,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                ]);
            }

            $bill->update(['status' => LoanBill::STATUS_CANCELLED]);
        });

        return back()->with('success', 'Loan bill cancelled.');
    }

    private function findLoan(string $encodedId): Loan
    {
        $decoded = Hashids::decode($encodedId);
        if (empty($decoded)) {
            abort(404, 'Loan not found.');
        }

        return Loan::with(['customer', 'product', 'bills'])->findOrFail($decoded[0]);
    }
}
