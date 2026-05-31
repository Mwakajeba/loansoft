<?php

namespace App\Http\Controllers\Accounting\Reports;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Support\Accounting\GlReportQuery;
use App\Support\Accounting\GlTransactionReportFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;

class CashBookReportController extends Controller
{
    public function index(Request $request)
    {
        if (!auth()->user()->can('view cash book report')) {
            abort(403, 'Unauthorized access to this report.');
        }

        $user = Auth::user();
        $company = $user->company;

        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $bankAccountId = $request->get('bank_account_id', 'all');

        $bankAccounts = BankAccount::with('chartAccount.accountClassGroup')
            ->forUserBranches($user)
            ->whereHas('chartAccount.accountClassGroup', function ($q) use ($company) {
                $q->where('company_id', $company->id);
            })
            ->orderBy('name')
            ->get();

        $branches = $user->branches()->where('branches.company_id', $company->id)->get();
        $branchId = GlReportQuery::normalizeBranchId($user, $request->get('branch_id', 'all'), $branches);

        $cashBookData = $this->getCashBookData($startDate, $endDate, $bankAccountId, $branchId);

        return view('accounting.reports.cash-book.index', compact(
            'cashBookData',
            'startDate',
            'endDate',
            'bankAccountId',
            'branchId',
            'bankAccounts',
            'branches',
            'user'
        ));
    }

    private function getCashBookData($startDate, $endDate, $bankAccountId, $branchId)
    {
        $user = Auth::user();
        $company = $user->company;

        $chartAccountIds = GlReportQuery::bankChartAccountIds($user, $company->id, $bankAccountId, $branchId);

        if (empty($chartAccountIds)) {
            return [
                'opening_balance' => 0,
                'transactions' => [],
                'total_receipts' => 0,
                'total_payments' => 0,
                'final_balance' => 0,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'bank_account_id' => $bankAccountId,
                'branch_id' => $branchId,
            ];
        }

        $bankAccountNames = BankAccount::whereIn('chart_account_id', $chartAccountIds)
            ->pluck('name', 'chart_account_id');

        $openingBalanceQuery = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
            ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->where('account_class_groups.company_id', $company->id)
            ->whereIn('gl_transactions.chart_account_id', $chartAccountIds);

        GlReportQuery::applyOpeningBefore($openingBalanceQuery, $startDate);
        GlReportQuery::applyBranchFilter($openingBalanceQuery, $user, $branchId);

        $openingBalance = (float) ($openingBalanceQuery->selectRaw('
            COALESCE(SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END), 0) as opening_balance
        ')->value('opening_balance') ?? 0);

        $transactionsQuery = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
            ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->leftJoin('customers', 'gl_transactions.customer_id', '=', 'customers.id')
            ->where('account_class_groups.company_id', $company->id)
            ->whereIn('gl_transactions.chart_account_id', $chartAccountIds);

        GlReportQuery::applyDateRange($transactionsQuery, $startDate, $endDate);
        GlReportQuery::applyBranchFilter($transactionsQuery, $user, $branchId);

        $transactions = $transactionsQuery->select(
            'gl_transactions.id',
            'gl_transactions.date',
            'gl_transactions.description',
            'gl_transactions.nature',
            'gl_transactions.amount',
            'gl_transactions.transaction_type',
            'gl_transactions.transaction_id',
            'gl_transactions.chart_account_id',
            'chart_accounts.account_name',
            'customers.name as customer_name'
        )
            ->orderBy('gl_transactions.date', 'asc')
            ->orderBy('gl_transactions.id', 'asc')
            ->get();

        $processedTransactions = [];
        $runningBalance = $openingBalance;
        $totalReceipts = 0;
        $totalPayments = 0;

        foreach ($transactions as $transaction) {
            $debit = $transaction->nature === 'debit' ? (float) $transaction->amount : 0;
            $credit = $transaction->nature === 'credit' ? (float) $transaction->amount : 0;

            $totalReceipts += $debit;
            $totalPayments += $credit;
            $runningBalance += $debit - $credit;

            $processedTransactions[] = [
                'date' => $transaction->date,
                'description' => $transaction->description ?? 'Transaction',
                'customer_name' => $transaction->customer_name ?? 'N/A',
                'bank_account' => $bankAccountNames[$transaction->chart_account_id] ?? $transaction->account_name,
                'transaction_no' => GlReportQuery::formatVoucherNo($transaction->transaction_type, $transaction->transaction_id),
                'reference_no' => GlReportQuery::resolveReference($transaction->transaction_type, $transaction->transaction_id),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($runningBalance, 2),
            ];
        }

        return [
            'opening_balance' => round($openingBalance, 2),
            'transactions' => $processedTransactions,
            'total_receipts' => round($totalReceipts, 2),
            'total_payments' => round($totalPayments, 2),
            'final_balance' => round($runningBalance, 2),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'bank_account_id' => $bankAccountId,
            'branch_id' => $branchId,
        ];
    }

    public function export(Request $request)
    {
        if (!auth()->user()->can('view cash book report')) {
            abort(403, 'Unauthorized access to this report.');
        }

        $user = Auth::user();
        $company = $user->company;

        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $bankAccountId = $request->get('bank_account_id', 'all');
        $branches = $user->branches()->where('branches.company_id', $company->id)->get();
        $branchId = GlReportQuery::normalizeBranchId($user, $request->get('branch_id', 'all'), $branches);
        $exportType = $request->get('export_type', 'pdf');

        $cashBookData = $this->getCashBookData($startDate, $endDate, $bankAccountId, $branchId);

        if ($exportType === 'pdf') {
            return $this->exportPdf($cashBookData, $company, $startDate, $endDate);
        }

        return $this->exportExcel($cashBookData, $company, $startDate, $endDate);
    }

    private function exportPdf($cashBookData, $company, $startDate, $endDate)
    {
        $user = Auth::user();
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        $branchId = $cashBookData['branch_id'] ?? null;
        $branchName = 'All Branches';
        if ($branchId && $branchId !== 'all') {
            $branch = $branches->firstWhere('id', $branchId);
            $branchName = $branch->name ?? 'Unknown Branch';
        } elseif (($branches->count() ?? 0) <= 1 && $branchId === 'all') {
            $branchName = optional($branches->first())->name ?? 'All Branches';
        }

        $pdf = Pdf::loadView('accounting.reports.cash-book.pdf', [
            'cashBookData' => $cashBookData,
            'company' => $company,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'branchName' => $branchName,
        ]);

        $filename = 'cash_book_' . $startDate . '_to_' . $endDate . '.pdf';

        return $pdf->download($filename);
    }

    private function exportExcel($cashBookData, $company, $startDate, $endDate)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', $company->name ?? 'SmartFinance');
        $sheet->setCellValue('A2', 'CASH BOOK');
        $sheet->setCellValue('A3', 'Period: ' . Carbon::parse($startDate)->format('M d, Y') . ' to ' . Carbon::parse($endDate)->format('M d, Y'));

        $sheet->setCellValue('A5', 'DATE');
        $sheet->setCellValue('B5', 'DESCRIPTION');
        $sheet->setCellValue('C5', 'BANK ACCOUNT');
        $sheet->setCellValue('D5', 'TRANSACTION NO');
        $sheet->setCellValue('E5', 'REFERENCE NO.');
        $sheet->setCellValue('F5', 'DEBIT');
        $sheet->setCellValue('G5', 'CREDIT');
        $sheet->setCellValue('H5', 'BALANCE');

        $row = 6;

        $sheet->setCellValue('A' . $row, 'Opening Balance');
        $sheet->setCellValue('H' . $row, number_format($cashBookData['opening_balance'], 2));
        $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
        $row++;

        foreach ($cashBookData['transactions'] as $transaction) {
            $sheet->setCellValue('A' . $row, Carbon::parse($transaction['date'])->format('d/m/Y'));
            $sheet->setCellValue('B' . $row, $transaction['description']);
            $sheet->setCellValue('C' . $row, $transaction['bank_account']);
            $sheet->setCellValue('D' . $row, $transaction['transaction_no']);
            $sheet->setCellValue('E' . $row, $transaction['reference_no']);
            $sheet->setCellValue('F' . $row, $transaction['debit'] > 0 ? number_format($transaction['debit'], 2) : '');
            $sheet->setCellValue('G' . $row, $transaction['credit'] > 0 ? number_format($transaction['credit'], 2) : '');
            $sheet->setCellValue('H' . $row, number_format($transaction['balance'], 2));
            $row++;
        }

        $sheet->setCellValue('A' . $row, 'Total Debit');
        $sheet->setCellValue('F' . $row, number_format($cashBookData['total_receipts'], 2));
        $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Total Credit');
        $sheet->setCellValue('G' . $row, number_format($cashBookData['total_payments'], 2));
        $sheet->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue('A' . $row, 'Final Balance');
        $sheet->setCellValue('H' . $row, number_format($cashBookData['final_balance'], 2));
        $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'cash_book_' . $startDate . '_to_' . $endDate . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'cash_book');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend();
    }
}
