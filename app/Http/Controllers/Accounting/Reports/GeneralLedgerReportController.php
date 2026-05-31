<?php

namespace App\Http\Controllers\Accounting\Reports;

use App\Http\Controllers\Controller;
use App\Support\Accounting\GlReportQuery;
use App\Support\Accounting\GlTransactionReportFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;

class GeneralLedgerReportController extends Controller
{
    public function index(Request $request)
    {
        if (!auth()->user()->can('view general ledger report')) {
            abort(403, 'Unauthorized access to this report.');
        }

        $user = Auth::user();
        $company = $user->company;

        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $reportType = $request->get('report_type', 'accrual');
        $accountId = $request->get('account_id') ?: null;
        $groupBy = $request->get('group_by', 'account');
        $showOpeningBalance = filter_var($request->get('show_opening_balance', true), FILTER_VALIDATE_BOOLEAN);

        $accounts = DB::table('chart_accounts')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->where('account_class_groups.company_id', $company->id)
            ->select('chart_accounts.id', 'chart_accounts.account_name', 'chart_accounts.account_code')
            ->orderBy('chart_accounts.account_code')
            ->get();

        $branches = $user->branches()->where('branches.company_id', $company->id)->get();
        $branchId = GlReportQuery::normalizeBranchId($user, $request->get('branch_id', 'all'), $branches);

        $generalLedgerData = $this->getGeneralLedgerData(
            $startDate,
            $endDate,
            $reportType,
            $accountId,
            $branchId,
            $showOpeningBalance,
            $groupBy
        );

        return view('accounting.reports.general-ledger.index', compact(
            'generalLedgerData',
            'startDate',
            'endDate',
            'reportType',
            'accountId',
            'branchId',
            'showOpeningBalance',
            'groupBy',
            'accounts',
            'branches',
            'user'
        ));
    }

    private function getGeneralLedgerData($startDate, $endDate, $reportType, $accountId, $branchId, $showOpeningBalance, $groupBy)
    {
        $user = Auth::user();
        $company = $user->company;

        $query = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
            ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->join('account_class', 'account_class_groups.class_id', '=', 'account_class.id')
            ->leftJoin('customers as c', 'gl_transactions.customer_id', '=', 'c.id')
            ->where('account_class_groups.company_id', $company->id);

        GlReportQuery::applyDateRange($query, $startDate, $endDate);
        GlReportQuery::applyBranchFilter($query, $user, $branchId);

        if ($accountId) {
            $query->where('gl_transactions.chart_account_id', $accountId);
        }

        if ($reportType === 'cash') {
            GlReportQuery::applyCashBasisFilter($query);
        }

        $query->select(
            'gl_transactions.*',
            'chart_accounts.account_name',
            'chart_accounts.account_code',
            'account_class_groups.name as group_name',
            'account_class.name as class_name',
            'c.name as customer_name'
        );

        match ($groupBy) {
            'date' => $query->orderBy('gl_transactions.date')
                ->orderBy('chart_accounts.account_code')
                ->orderBy('gl_transactions.id'),
            'voucher' => $query->orderBy('gl_transactions.transaction_type')
                ->orderBy('gl_transactions.transaction_id')
                ->orderBy('gl_transactions.id'),
            default => $query->orderBy('chart_accounts.account_code')
                ->orderBy('gl_transactions.date')
                ->orderBy('gl_transactions.id'),
        };

        $transactions = $query->get();

        $openingBalances = collect();
        if ($showOpeningBalance) {
            $openingBalances = $this->getOpeningBalances($startDate, $accountId, $branchId, $reportType, $user, $company->id);
        }

        $processedData = GlReportQuery::attachRunningBalances($transactions, $openingBalances, $groupBy === 'account');

        return [
            'transactions' => $processedData,
            'opening_balances' => $openingBalances,
            'summary' => $this->getSummary($processedData),
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'report_type' => $reportType,
                'account_id' => $accountId,
                'branch_id' => $branchId,
                'show_opening_balance' => $showOpeningBalance,
                'group_by' => $groupBy,
            ],
        ];
    }

    private function getOpeningBalances($startDate, $accountId, $branchId, $reportType, $user, int $companyId)
    {
        $query = GlTransactionReportFilter::apply(DB::table('gl_transactions'))
            ->join('chart_accounts', 'gl_transactions.chart_account_id', '=', 'chart_accounts.id')
            ->join('account_class_groups', 'chart_accounts.account_class_group_id', '=', 'account_class_groups.id')
            ->where('account_class_groups.company_id', $companyId);

        GlReportQuery::applyOpeningBefore($query, $startDate);
        GlReportQuery::applyBranchFilter($query, $user, $branchId);

        if ($accountId) {
            $query->where('gl_transactions.chart_account_id', $accountId);
        }

        if ($reportType === 'cash') {
            GlReportQuery::applyCashBasisFilter($query);
        }

        return $query->select(
            'gl_transactions.chart_account_id',
            'chart_accounts.account_name',
            'chart_accounts.account_code',
            DB::raw('COALESCE(SUM(CASE WHEN gl_transactions.nature = "debit" THEN gl_transactions.amount ELSE 0 END), 0) as total_debit'),
            DB::raw('COALESCE(SUM(CASE WHEN gl_transactions.nature = "credit" THEN gl_transactions.amount ELSE 0 END), 0) as total_credit')
        )
            ->groupBy('gl_transactions.chart_account_id', 'chart_accounts.account_name', 'chart_accounts.account_code')
            ->get()
            ->keyBy('chart_account_id');
    }

    private function getSummary($transactions)
    {
        $summary = [
            'total_debit' => 0,
            'total_credit' => 0,
            'net_movement' => 0,
            'transaction_count' => count($transactions),
            'account_count' => collect($transactions)->pluck('chart_account_id')->unique()->count(),
        ];

        foreach ($transactions as $transaction) {
            if ($transaction->nature === 'debit') {
                $summary['total_debit'] += (float) $transaction->amount;
            } else {
                $summary['total_credit'] += (float) $transaction->amount;
            }
        }

        $summary['total_debit'] = round($summary['total_debit'], 2);
        $summary['total_credit'] = round($summary['total_credit'], 2);
        $summary['net_movement'] = round($summary['total_debit'] - $summary['total_credit'], 2);

        return $summary;
    }

    public function export(Request $request)
    {
        if (!auth()->user()->can('view general ledger report')) {
            abort(403, 'Unauthorized access to this report.');
        }

        $user = Auth::user();
        $company = $user->company;

        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));
        $reportType = $request->get('report_type', 'accrual');
        $accountId = $request->get('account_id') ?: null;
        $branches = $user->branches()->where('branches.company_id', $company->id)->get();
        $branchId = GlReportQuery::normalizeBranchId($user, $request->get('branch_id', 'all'), $branches);
        $showOpeningBalance = filter_var($request->get('show_opening_balance', true), FILTER_VALIDATE_BOOLEAN);
        $groupBy = $request->get('group_by', 'account');
        $exportType = $request->get('export_type', 'pdf');

        $generalLedgerData = $this->getGeneralLedgerData(
            $startDate,
            $endDate,
            $reportType,
            $accountId,
            $branchId,
            $showOpeningBalance,
            $groupBy
        );

        if ($exportType === 'pdf') {
            return $this->exportPdf($generalLedgerData, $company, $startDate, $endDate, $reportType);
        }

        return $this->exportExcel($generalLedgerData, $company, $startDate, $endDate, $reportType);
    }

    private function exportPdf($generalLedgerData, $company, $startDate, $endDate, $reportType)
    {
        $user = Auth::user();
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        $branchId = $generalLedgerData['filters']['branch_id'] ?? null;
        $branchName = 'All Branches';
        if ($branchId === 'all' && ($branches->count() ?? 0) <= 1) {
            $branchName = optional($branches->first())->name ?? 'All Branches';
        } elseif ($branchId && $branchId !== 'all') {
            $branch = $branches->firstWhere('id', $branchId);
            $branchName = $branch->name ?? 'Unknown Branch';
        }

        $pdf = Pdf::loadView('accounting.reports.general-ledger.pdf', [
            'generalLedgerData' => $generalLedgerData,
            'company' => $company,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reportType' => $reportType,
            'groupBy' => $generalLedgerData['filters']['group_by'] ?? 'account',
            'branchName' => $branchName,
        ]);

        $filename = 'general_ledger_' . $startDate . '_to_' . $endDate . '_' . $reportType . '.pdf';

        return $pdf->download($filename);
    }

    private function exportExcel($generalLedgerData, $company, $startDate, $endDate, $reportType)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', $company->name ?? 'SmartFinance');
        $sheet->setCellValue('A2', 'GENERAL LEDGER REPORT');
        $sheet->setCellValue('A3', 'Period: ' . Carbon::parse($startDate)->format('M d, Y') . ' to ' . Carbon::parse($endDate)->format('M d, Y'));
        $sheet->setCellValue('A4', 'Basis: ' . ucfirst($reportType));

        $sheet->setCellValue('A6', 'Date');
        $sheet->setCellValue('B6', 'Account Code');
        $sheet->setCellValue('C6', 'Account Name');
        $sheet->setCellValue('D6', 'Voucher');
        $sheet->setCellValue('E6', 'Reference');
        $sheet->setCellValue('F6', 'Description');
        $sheet->setCellValue('G6', 'Debit');
        $sheet->setCellValue('H6', 'Credit');
        $sheet->setCellValue('I6', 'Balance');

        $row = 7;

        $openingBalances = $generalLedgerData['opening_balances'] ?? collect();
        $transactions = $generalLedgerData['transactions'] ?? [];

        $lastAccountId = null;

        foreach ($transactions as $transaction) {
            if ($transaction->chart_account_id !== $lastAccountId) {
                if ($generalLedgerData['filters']['show_opening_balance'] ?? true) {
                    $ob = $openingBalances->get($transaction->chart_account_id);
                    $opening = $ob ? GlReportQuery::netBalance((float) $ob->total_debit, (float) $ob->total_credit) : 0;

                    $sheet->setCellValue('A' . $row, 'Opening Balance');
                    $sheet->setCellValue('B' . $row, $transaction->account_code);
                    $sheet->setCellValue('C' . $row, $transaction->account_name);
                    $sheet->setCellValue('I' . $row, number_format($opening, 2));
                    $row++;
                }

                $lastAccountId = $transaction->chart_account_id;
            }

            $sheet->setCellValue('A' . $row, Carbon::parse($transaction->date)->format('M d, Y'));
            $sheet->setCellValue('B' . $row, $transaction->account_code);
            $sheet->setCellValue('C' . $row, $transaction->account_name);
            $sheet->setCellValue('D' . $row, $transaction->voucher_no ?? '');
            $sheet->setCellValue('E' . $row, $transaction->reference_no ?? '');
            $sheet->setCellValue('F' . $row, $transaction->description);
            $sheet->setCellValue('G' . $row, $transaction->nature === 'debit' ? number_format($transaction->amount, 2) : '');
            $sheet->setCellValue('H' . $row, $transaction->nature === 'credit' ? number_format($transaction->amount, 2) : '');
            $sheet->setCellValue('I' . $row, number_format($transaction->running_balance, 2));
            $row++;
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'general_ledger_' . $startDate . '_to_' . $endDate . '_' . $reportType . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'general_ledger');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename)->deleteFileAfterSend();
    }
}
