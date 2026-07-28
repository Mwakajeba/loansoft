<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Fee;
use App\Models\Group;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\Receipt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Exports\DisbursementsExport;
use App\Exports\RepaymentExport;
use App\Models\Repayment;
use App\Support\Loans\GroupRepaymentScheduleCardBuilder;
use App\Support\Loans\LoanReportMetrics;
use App\Support\Loans\LoanReportRowBuilder;
use App\Support\Loans\RepaymentReportBuilder;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PortfolioAtRiskExport;
use App\Exports\PortfolioExport;
use App\Exports\PerformanceExport;
use App\Exports\DelinquencyExport;
use App\Exports\InternalPortfolioAnalysisExport;
use App\Exports\LoanSizeTypeExport;
use App\Exports\GenericArrayExport;
use App\Exports\PortfolioClassificationExport;
use App\Models\ArrearsClassification;
use PDF;

class LoanReportController extends Controller
{
    public function loanDisbursementReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        // Pata data ya kuchuja kutoka kwenye request, ukiweka default values
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());
        $branchId = $request->input('branch_id');
        $companyId = $request->input('company_id');
        $groupId = $request->input('group_id');
        // Get the authenticated user if they are a loan officer
        $loanOfficerId = $request->input('loan_officer_id');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        // Unda query ya loans na uweke filters
        $loansQuery = Loan::with(['customer', 'product', 'branch', 'loanOfficer', 'group'])
            ->whereBetween('disbursed_on', [$startDate, $endDate])
            ->whereIn('branch_id', $assignedBranchIds);

        // Weka filter ya branch
        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        // Weka filter ya company
        if ($companyId) {
            $loansQuery->whereHas('product', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            });
        }
        if ($groupId) {
            $loansQuery->where('group_id', $groupId);
        }
        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $disbursements = $loansQuery->get();

        // Kokotoa muhtasari wa ripoti
        $summary = [
            'total_disbursed' => $disbursements->sum('amount'),
            'loan_count' => $disbursements->count(),
            'average_disbursed' => $disbursements->count() > 0 ? $disbursements->sum('amount') / $disbursements->count() : 0,
            'total_interest_expected' => $disbursements->sum('interest_amount'),
        ];

        // Pata list ya companies na groups
        $companies = Company::all();
        $groups = Group::all();
        // Only show loan officers assigned to the selected branch (if any)
        $loanOfficers = User::excludeSuperAdmin()
        ->when($branchId && $branchId !== 'all', function ($query) use ($branchId) {
            $query->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            });
        })
        ->get();

        // Rudi na view ya ripoti
        return view('loans.reports.disbursed', compact('disbursements', 'summary', 'branches', 'companies','groups','loanOfficers'));
    }



    public function exportLoanDisbursement(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        // Same defaults as loanDisbursementReport (1st of month → today)
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        $companyId = $request->input('company_id');
        $exportType = $request->input('export_type');
        $exportAction = $request->input('export_action', 'download'); // 'download' ni default

        // Get user's assigned branches (same as index report)
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        // 2. Unda query ya loans na uweke filters kama ilivyo kwenye method ya report
        $loansQuery = Loan::with(['customer', 'product', 'branch', 'loanOfficer','group'])
            ->whereBetween('disbursed_on', [$startDate, $endDate])
            ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($companyId) {
            $loansQuery->whereHas('product', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            });
        }
        if( $groupId) {
            $loansQuery->where('group_id', $groupId);
        }
        if( $loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $disbursements = $loansQuery->get();
        // Treat 'all' / empty like the index report — do not call findOrFail('all') (causes 404)
        $branch = ($branchId && $branchId !== 'all') ? Branch::findOrFail($branchId) : (object)['name' => 'All Branches'];


        // 3. Tekeleza mantiki ya export kulingana na aina ya faili
        if ($exportType === 'pdf') {
            $pdf = PDF::loadView('loans.reports.pdf', compact('disbursements', 'startDate', 'endDate', 'branch','company'))
                ->setPaper('a3', 'landscape');

            if ($exportAction === 'view') {
                return $pdf->stream('loan_disbursement_report.pdf');  // Hii itaonyesha PDF kwenye browser
            }

            return $pdf->download('loan_disbursement_report.pdf'); // Hii itapakua (default
        } elseif ($exportType === 'excel') {
            // Hapa tunatumia Maatwebsite/Excel
            return Excel::download(new DisbursementsExport($disbursements), 'loan_disbursement_report.xlsx');
        }

        // Rudi na ujumbe wa kosa ikiwa aina ya export haijatambuliwa
        return response()->json(['message' => 'Invalid export type.'], 400);
    }

    /**
     * Loan Size Type report (bucket loans by principal into ranges)
     */
    public function loanSizeTypeReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $branchId = $request->input('branch_id');

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $buckets = [
            ['label' => '0 - 500,000', 'min' => 0, 'max' => 500000],
            ['label' => '500,000 - 1,000,000', 'min' => 500000, 'max' => 1000000],
            ['label' => '1,000,000 - 2,000,000', 'min' => 1000000, 'max' => 2000000],
            ['label' => '2,000,000 - 5,000,000', 'min' => 2000000, 'max' => 5000000],
            ['label' => '5,000,000 - 10,000,000', 'min' => 5000000, 'max' => 10000000],
            ['label' => 'ABOVE 10,000,000', 'min' => 10000000, 'max' => null],
        ];

        $today = Carbon::today();

        $results = [];
        $grand = [
            'count' => 0,
            'loan_amount' => 0,
            'interest' => 0,
            'total_loan' => 0,
            'total_outstanding' => 0,
            'arrears_count' => 0,
            'arrears_amount' => 0,
            'delayed_count' => 0,
            'delayed_amount' => 0,
            'outstanding_in_delayed' => 0,
        ];

        foreach ($buckets as $bucket) {
            $loans = Loan::query()
                ->when($startDate && $endDate, function($q) use ($startDate, $endDate){
                    $q->whereBetween('disbursed_on', [$startDate, $endDate]);
                })
                ->whereIn('branch_id', $assignedBranchIds)
                ->when($branchId && $branchId !== 'all', function($q) use ($branchId){
                    $q->where('branch_id', $branchId);
                })
                ->when(!is_null($bucket['max']), function($q) use ($bucket){
                    $q->whereBetween('amount', [$bucket['min'], $bucket['max']]);
                }, function($q) use ($bucket){
                    $q->where('amount', '>', $bucket['min']);
                })
                ->with(['repayments', 'schedule'])
                ->get();

            $count = $loans->count();
            $loanAmount = (float) $loans->sum('amount');
            $interest = (float) $loans->sum('interest_amount');
            $totalLoan = $loanAmount + $interest;

            // Outstanding principal = principal - principal repaid
            $totalOutstanding = (float) $loans->sum(function($loan){
                $principalPaid = $loan->repayments->sum('principal');
                return max(0, ($loan->amount ?? 0) - $principalPaid);
            });

            // Arrears = schedules past due with remaining > 0
            $arrearsCount = 0; $arrearsAmount = 0; $delayedCount = 0; $delayedAmount = 0; $outstandingInDelayed = 0;
            foreach ($loans as $loan) {
                foreach ($loan->schedule as $sch) {
                    $remaining = max(0, ($sch->principal + $sch->interest + $sch->fee_amount + $sch->penalty_amount) - ($sch->repayments->sum('principal') + $sch->repayments->sum('interest') + $sch->repayments->sum('fee_amount') + $sch->repayments->sum('penalt_amount')));
                    if ($remaining <= 0) continue;

                    if (Carbon::parse($sch->due_date)->lt($today)) {
                        // in arrears
                        $arrearsCount++;
                        $arrearsAmount += $remaining;
                    }
                    // delayed: after due_date but within grace window
                    if ($sch->end_grace_date && Carbon::parse($sch->due_date)->lt($today) && Carbon::parse($sch->end_grace_date)->gte($today)) {
                        $delayedCount++;
                        $delayedAmount += $remaining;
                        $outstandingInDelayed += max(0, $sch->principal - $sch->repayments->sum('principal'));
                    }
                }
            }

            $row = [
                'label' => $bucket['label'],
                'count' => $count,
                'loan_amount' => $loanAmount,
                'interest' => $interest,
                'total_loan' => $totalLoan,
                'total_outstanding' => $totalOutstanding,
                'arrears_count' => $arrearsCount,
                'arrears_amount' => $arrearsAmount,
                'delayed_count' => $delayedCount,
                'delayed_amount' => $delayedAmount,
                'outstanding_in_delayed' => $outstandingInDelayed,
            ];

            // grand totals
            foreach ($grand as $k => $v) {
                $grand[$k] += $row[$k] ?? 0;
            }

            $results[] = $row;
        }

        return view('loans.reports.loan_size_type', [
            'rows' => $results,
            'grand' => $grand,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'branchId' => $branchId,
            'company' => $company,
        ]);
    }

    public function loanSizeTypeExport(Request $request)
    {
        $view = $this->loanSizeTypeReport($request);
        $data = $view->getData();
        return \Maatwebsite\Excel\Facades\Excel::download(new LoanSizeTypeExport($data['rows'], $data['grand'], $data['startDate'], $data['endDate']), 'loan_size_type_report.xlsx');
    }

    public function loanSizeTypeExportPdf(Request $request)
    {
        $view = $this->loanSizeTypeReport($request);
        $data = $view->getData();
        $data['company'] = auth()->user()->company;
        $pdf = \PDF::loadView('loans.reports.loan_size_type_pdf', $data)->setPaper('a3', 'landscape');
        return $pdf->download('loan_size_type_report.pdf');
    }

    /**
     * Monthly Loan Performance Report
     * Columns: Month, Loan Given, Interest, Total Loan+Interest, Total Amount Collected, Outstanding, Actual Interest Collected, Performance%
     */
    public function monthlyPerformanceReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $branchId = $request->input('branch_id');

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')->toArray();

        // Build months range
        $start = $startDate ? Carbon::parse($startDate)->startOfMonth() : Carbon::now()->startOfYear();
        $end = $endDate ? Carbon::parse($endDate)->endOfMonth() : Carbon::now()->endOfMonth();

        $months = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        // Preload loans and repayments within range
        $loans = Loan::query()
            ->select('id','amount','interest_amount','disbursed_on','branch_id')
            ->whereBetween('disbursed_on', [$start->toDateString(), $end->toDateString()])
            ->whereIn('branch_id', $assignedBranchIds)
            ->when($branchId && $branchId !== 'all', fn($q)=>$q->where('branch_id',$branchId))
            ->get();

        // Preload ALL repayments for cohort loans (lifetime), regardless of payment date
        $repayments = Repayment::query()
            ->select('loan_id','payment_date','principal','interest','fee_amount','penalt_amount')
            ->when($branchId && $branchId !== 'all', function($q) use ($assignedBranchIds, $branchId){
                // Join to loans to filter branch
                $q->whereIn('loan_id', Loan::where('branch_id',$branchId)->pluck('id'));
            }, function($q) use ($assignedBranchIds){
                $q->whereIn('loan_id', Loan::whereIn('branch_id',$assignedBranchIds)->pluck('id'));
            })
            ->get();

        $rows = [];
        $grand = [
            'loan_given' => 0,
            'interest' => 0,
            'total_loan' => 0,
            'collected' => 0,
            'outstanding' => 0,
            'actual_interest_collected' => 0,
        ];

        foreach ($months as $ym) {
            [$y,$m] = explode('-', $ym);
            $loanGiven = (float) $loans->filter(function($l) use($y,$m){
                return Carbon::parse($l->disbursed_on)->format('Y')==$y && Carbon::parse($l->disbursed_on)->format('m')==$m;
            })->sum('amount');
            $interest = (float) $loans->filter(function($l) use($y,$m){
                return Carbon::parse($l->disbursed_on)->format('Y')==$y && Carbon::parse($l->disbursed_on)->format('m')==$m;
            })->sum('interest_amount');
            $totalLoan = $loanGiven + $interest;
            // Cohort repayments: sum all repayments (up to end date) for loans disbursed in this month
            $cohortLoanIds = $loans->filter(function($l) use($y,$m){
                return Carbon::parse($l->disbursed_on)->format('Y')==$y && Carbon::parse($l->disbursed_on)->format('m')==$m;
            })->pluck('id')->all();

            $collected = (float) $repayments->whereIn('loan_id', $cohortLoanIds)
                ->sum(function($r){
                    return ($r->principal ?? 0) + ($r->interest ?? 0) + ($r->fee_amount ?? 0) + ($r->penalt_amount ?? 0);
                });
            $outstanding = max(0, $totalLoan - $collected);
            // ACTUAL INTEREST COLLECTED = sum of interest portion on repayments for this disbursement cohort
            $actualInterestCollected = (float) $repayments->whereIn('loan_id', $cohortLoanIds)->sum('interest');
            $performance = $totalLoan > 0 ? round(min(1, $collected / $totalLoan) * 100, 2) : 0;

            $rows[] = [
                'month' => Carbon::createFromDate((int)$y,(int)$m,1)->format('M Y'),
                'loan_given' => $loanGiven,
                'interest' => $interest,
                'total_loan' => $totalLoan,
                'collected' => $collected,
                'outstanding' => $outstanding,
                'actual_interest_collected' => $actualInterestCollected,
                'performance' => $performance,
            ];

            $grand['loan_given'] += $loanGiven;
            $grand['interest'] += $interest;
            $grand['total_loan'] += $totalLoan;
            $grand['collected'] += $collected;
            $grand['outstanding'] += $outstanding;
            $grand['actual_interest_collected'] += $actualInterestCollected;
        }

        return view('loans.reports.monthly_performance', [
            'rows' => $rows,
            'grand' => $grand,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'branchId' => $branchId,
            'company' => $company,
        ]);
    }

    public function monthlyPerformanceExport(Request $request)
    {
        $view = $this->monthlyPerformanceReport($request);
        $data = $view->getData();
        $headings = ['MONTH','LOAN GIVEN','INTEREST','TOTAL LOAN + INTEREST','TOTAL AMOUNT COLLECTED','OUTSTANDING','ACTUAL INTEREST COLLECTED','PERFORMANCE %'];
        $array = [];
        foreach ($data['rows'] as $r) {
            $array[] = [
                $r['month'],
                $r['loan_given'],
                $r['interest'],
                $r['total_loan'],
                $r['collected'],
                $r['outstanding'],
                $r['actual_interest_collected'],
                $r['performance']
            ];
        }
        return \Maatwebsite\Excel\Facades\Excel::download(new GenericArrayExport($array, $headings), 'monthly_loan_performance.xlsx');
    }

    public function monthlyPerformanceExportPdf(Request $request)
    {
        $view = $this->monthlyPerformanceReport($request);
        $data = $view->getData();
        $pdf = \PDF::loadView('loans.reports.monthly_performance_pdf', $data)->setPaper('a4', 'landscape');
        return $pdf->download('monthly_loan_performance.pdf');
    }


    //////////REPAYMENT FUNCTION REPORT////

    public function getRepaymentReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        // Get filters from request with proper null handling
        $startDate = ($request->input('start_date') ?? now()->startOfMonth()->format('Y-m-d'));
        $endDate = ($request->input('end_date') ?? now()->format('Y-m-d'));
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $reportData = $this->buildRepaymentReportData(
            $startDate,
            $endDate,
            $assignedBranchIds,
            $branchId,
            $groupId,
            $loanOfficerId
        );

        // 4. Pata data ya groups na loan officers
        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId && $branchId !== 'all', function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        $repayments = $reportData['rows'];
        $summary = $reportData['summary'];
        $monthlyGroups = $reportData['monthlyGroups'];

        return view(
            'loans.reports.repayments.repayment',
            compact('repayments', 'summary', 'monthlyGroups', 'startDate', 'endDate', 'branches', 'loanOfficers', 'groups')
        );
    }


    public function exportLoanRepayment(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        // Get filters from request with proper null handling
        $startDate = ($request->input('start_date') ?? now()->startOfMonth()->format('Y-m-d'));
        $endDate = ($request->input('end_date') ?? now()->format('Y-m-d'));
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');
        $exportType = $request->input('export_type');
        $exportAction = $request->input('export_action', 'download');

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $reportData = $this->buildRepaymentReportData(
            $startDate,
            $endDate,
            $assignedBranchIds,
            $branchId,
            $groupId,
            $loanOfficerId
        );
        $repayments = $reportData['rows'];
        $summary = $reportData['summary'];
        $monthlyGroups = $reportData['monthlyGroups'];

        // Get branch name for display - handle 'all' or null properly
        $branch = ($branchId && $branchId !== 'all') ? Branch::find($branchId) : null;
        if (!$branch) {
            $branch = (object)['name' => 'All Branches'];
        }

        if ($exportType === 'pdf') {
            $pdf = PDF::loadView(
                'loans.reports.repayments.pdf',
                compact('repayments', 'summary', 'monthlyGroups', 'startDate', 'endDate', 'branch', 'company')
            )
                ->setPaper('a3', 'landscape');

            if ($exportAction === 'view') {
                return $pdf->stream('loan_repayment_report.pdf');
            }

            return $pdf->download('loan_repayment_report.pdf');
        } elseif ($exportType === 'excel') {
            return Excel::download(new RepaymentExport($monthlyGroups, $summary), 'loan_repayment_report.xlsx');
        }

        return response()->json(['message' => 'Invalid export type.'], 400);
    }

    private function buildRepaymentReportData(
        string $startDate,
        string $endDate,
        array $assignedBranchIds,
        $branchId,
        $groupId,
        $loanOfficerId
    ): array {
        $loans = $this->repaymentReportLoansQuery($assignedBranchIds, $branchId, $groupId, $loanOfficerId)
            ->get()
            ->keyBy('id');
        $this->attachRepaymentReceiptFeeMeta($loans);

        if ($loans->isEmpty()) {
            $rows = collect();

            return [
                'rows' => $rows,
                'summary' => RepaymentReportBuilder::summarize($rows),
                'monthlyGroups' => RepaymentReportBuilder::monthlyGroups($rows, $startDate, $endDate),
            ];
        }

        $loanIds = $loans->keys()->map(fn ($id) => (int) $id)->values();
        $loanReferences = RepaymentReportBuilder::loanReferenceValues($loanIds);

        $receipts = Receipt::with(['receiptItems', 'bankAccount.chartAccount', 'repayments'])
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->where(function ($query) use ($loanReferences) {
                $query->where(function ($inner) use ($loanReferences) {
                    $inner->where('reference_type', 'loan')
                        ->whereIn('reference', $loanReferences);
                })->orWhere(function ($inner) use ($loanReferences) {
                    $inner->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                        ->whereIn('reference', $loanReferences);
                });
            })
            ->get();

        $receiptRows = RepaymentReportBuilder::makeFeeReceiptRows($receipts, $loans);

        $receiptIdsInReport = $receipts->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

        $standaloneRepayments = Repayment::with(['chartAccount'])
            ->whereIn('loan_id', $loanIds)
            ->whereDate('payment_date', '>=', $startDate)
            ->whereDate('payment_date', '<=', $endDate)
            ->when($receiptIdsInReport->isNotEmpty(), function ($query) use ($receiptIdsInReport) {
                $query->where(function ($inner) use ($receiptIdsInReport) {
                    $inner->whereNull('receipt_id')
                        ->orWhereNotIn('receipt_id', $receiptIdsInReport);
                });
            })
            ->get()
            ->map(function ($repayment) use ($loans) {
                $loan = $loans->get($repayment->loan_id);
                if ($loan) {
                    $repayment->setRelation('loan', $loan);
                }

                return $repayment;
            });

        $repaymentRows = RepaymentReportBuilder::makeRepaymentRows($standaloneRepayments);

        $rows = RepaymentReportBuilder::sortRows($receiptRows->merge($repaymentRows));

        return [
            'rows' => $rows,
            'summary' => RepaymentReportBuilder::summarize($rows),
            'monthlyGroups' => RepaymentReportBuilder::monthlyGroups($rows, $startDate, $endDate),
        ];
    }

    private function repaymentReportLoansQuery(array $assignedBranchIds, $branchId, $groupId, $loanOfficerId)
    {
        return Loan::with(['customer', 'branch', 'product', 'loanOfficer', 'group'])
            ->whereIn('branch_id', $assignedBranchIds)
            ->when($branchId && $branchId !== 'all', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->when($groupId && $groupId !== 'all', function ($query) use ($groupId) {
                $query->where('group_id', $groupId);
            })
            ->when($loanOfficerId && $loanOfficerId !== 'all', function ($query) use ($loanOfficerId) {
                $query->where('loan_officer_id', $loanOfficerId);
            });
    }

    private function attachRepaymentReceiptFeeMeta($loans): void
    {
        if ($loans->isEmpty()) {
            return;
        }

        $allFeeIds = $loans
            ->map(function ($loan) {
                $feeIds = data_get($loan, 'product.fees_ids', []);

                if (is_string($feeIds)) {
                    $decoded = json_decode($feeIds, true);
                    $feeIds = is_array($decoded) ? $decoded : [];
                }

                return collect($feeIds)->filter()->map(fn ($id) => (int) $id)->values();
            })
            ->flatten()
            ->unique()
            ->values();

        $allPenaltyIds = $loans
            ->map(function ($loan) {
                $penaltyIds = data_get($loan, 'product.penalty_ids', []);

                if (is_string($penaltyIds)) {
                    $decoded = json_decode($penaltyIds, true);
                    $penaltyIds = is_array($decoded) ? $decoded : [];
                }

                return collect($penaltyIds)->filter()->map(fn ($id) => (int) $id)->values();
            })
            ->flatten()
            ->unique()
            ->values();

        $feesById = Fee::whereIn('id', $allFeeIds)
            ->get(['id', 'chart_account_id'])
            ->keyBy('id');
        $penaltiesById = Penalty::whereIn('id', $allPenaltyIds)
            ->get(['id', 'penalty_receivables_account_id'])
            ->keyBy('id');

        foreach ($loans as $loan) {
            $feeIds = data_get($loan, 'product.fees_ids', []);
            $penaltyIds = data_get($loan, 'product.penalty_ids', []);

            if (is_string($feeIds)) {
                $decoded = json_decode($feeIds, true);
                $feeIds = is_array($decoded) ? $decoded : [];
            }
            if (is_string($penaltyIds)) {
                $decoded = json_decode($penaltyIds, true);
                $penaltyIds = is_array($decoded) ? $decoded : [];
            }

            $loanFeeIds = collect($feeIds)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();
            $loanPenaltyIds = collect($penaltyIds)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

            $loan->report_receipt_fee_ids = $loanFeeIds->all();
            $loan->report_receipt_chart_account_ids = $loanFeeIds
                ->map(fn ($id) => data_get($feesById->get($id), 'chart_account_id'))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $loan->report_penalty_chart_account_ids = $loanPenaltyIds
                ->map(fn ($id) => data_get($penaltiesById->get($id), 'penalty_receivables_account_id'))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $loan->report_principal_account_id = data_get($loan, 'product.principal_receivable_account_id');
            $loan->report_interest_account_ids = collect([
                data_get($loan, 'product.interest_receivable_account_id'),
                data_get($loan, 'product.interest_revenue_account_id'),
            ])
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }
    }
    /**
     * Display the Loan Aging Report view and data.
     */
    public function loanAgingReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->input('as_of_date') ?? date('Y-m-d'));
        $branchId = $request->input('branch_id');
        $loanOfficerId = $request->input('loan_officer_id');
        $exportType = $request->input('export_type');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        Loan::syncActiveLoansEligibleForCompletion();

        $agingData = [];
        $loansQuery = Loan::with(\App\Support\Loans\LoanReportMetrics::eagerLoads())
            ->where('status', Loan::STATUS_ACTIVE)
            ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::agingRow($loan, $asOfDate);
            if ($row) {
                $agingData[] = $row;
            }
        }

        // Handle export requests
        if ($exportType && !empty($agingData)) {
            if ($exportType === 'excel') {
                return $this->exportLoanAgingToExcel($agingData, $asOfDate, $branchId, $loanOfficerId);
            } elseif ($exportType === 'pdf') {
                return $this->exportLoanAgingToPdf($agingData, $asOfDate, $branchId, $loanOfficerId);
            }
        }

        // Only show data if filter applied
        $showData = $request->has('as_of_date') || $request->has('branch_id') || $request->has('loan_officer_id');
        return view('loans.reports.loan_aging', [
            'branches' => $branches,
            'loanOfficers' => $loanOfficers,
            'agingData' => $showData ? $agingData : null,
        ]);
    }

        /**
     * Display the Loan Outstanding Balance Report view and data.
     */
    public function loanOutstandingReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->input('as_of_date') ?? date('Y-m-d'));
        $branchId = $request->input('branch_id');
        $loanOfficerId = $request->input('loan_officer_id');
        $exportType = $request->input('export_type');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $loansQuery = \App\Models\Loan::with(\App\Support\Loans\LoanReportMetrics::eagerLoads())
            ->whereIn('status', ['active', 'written_off', 'defaulted'])
            ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }
        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }
        Loan::syncActiveLoansEligibleForCompletion();
        $loans = $loansQuery->get();

        $outstandingData = [];
        $summary = [
            'total_disbursed' => 0.0,
            'total_interest' => 0.0,
            'total_principal_interest' => 0.0,
            'total_expected_fees' => 0.0,
            'total_penalties' => 0.0,
            'total_principal_paid' => 0.0,
            'total_interest_paid' => 0.0,
            'total_fees_paid' => 0.0,
            'total_penalty_paid' => 0.0,
            'total_outstanding_principal' => 0.0,
            'total_outstanding_interest' => 0.0,
            'total_outstanding_fees' => 0.0,
            'total_outstanding_penalty' => 0.0,
            'total_outstanding_balance' => 0.0,
        ];

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::outstandingRow($loan, $asOfDate);
            if (!$row) {
                continue;
            }
            $outstandingData[] = $row;
            $summary['total_disbursed'] += $row['disbursed_amount'];
            $summary['total_interest'] += $row['total_interest'];
            $summary['total_principal_interest'] += $row['total_principal_interest'];
            $summary['total_expected_fees'] += $row['expected_fees'];
            $summary['total_penalties'] += $row['total_penalties'];
            $summary['total_principal_paid'] += $row['principal_paid'];
            $summary['total_interest_paid'] += $row['interest_paid'];
            $summary['total_fees_paid'] += $row['fees_paid'];
            $summary['total_penalty_paid'] += $row['penalty_paid'];
            $summary['total_outstanding_principal'] += $row['outstanding_principal'];
            $summary['total_outstanding_interest'] += $row['outstanding_interest'];
            $summary['total_outstanding_fees'] += $row['outstanding_fees'];
            $summary['total_outstanding_penalty'] += $row['outstanding_penalty'];
            $summary['total_outstanding_balance'] += $row['outstanding_balance'];
        }

        // Handle export requests
        if ($exportType && !empty($outstandingData)) {
            if ($exportType === 'excel') {
                return $this->exportLoanOutstandingToExcel($outstandingData, $summary, $asOfDate, $branchId, $loanOfficerId);
            } elseif ($exportType === 'pdf') {
                return $this->exportLoanOutstandingToPdf($outstandingData, $summary, $asOfDate, $branchId, $loanOfficerId);
            }
        }

        // Only show data if filter applied
        $showData = $request->has('as_of_date') || $request->has('branch_id') || $request->has('loan_officer_id');
        return view('loans.reports.loan_outstanding', [
            'branches' => $branches,
            'loanOfficers' => $loanOfficers,
            'outstandingData' => $showData ? $outstandingData : null,
            'summary' => $summary,
        ]);
    }

    /**
     * Export Loan Aging Report to Excel
     */
    private function exportLoanAgingToExcel($agingData, $asOfDate, $branchId = null, $loanOfficerId = null)
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;

        return \Maatwebsite\Excel\Facades\Excel::download(new class($agingData, $asOfDate, $branch, $loanOfficer) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithTitle, \Maatwebsite\Excel\Concerns\WithStyles, \Maatwebsite\Excel\Concerns\ShouldAutoSize {
            private $agingData;
            private $asOfDate;
            private $branch;
            private $loanOfficer;

            public function __construct($agingData, $asOfDate, $branch, $loanOfficer)
            {
                $this->agingData = collect($agingData);
                $this->asOfDate = $asOfDate;
                $this->branch = $branch;
                $this->loanOfficer = $loanOfficer;
            }

            public function collection()
            {
                $rows = $this->agingData->values()->map(function ($row, $idx) {
                    return [
                        $idx + 1,
                        $row['customer'],
                        $row['customer_no'],
                        $row['phone'],
                        $row['loan_no'],
                        $row['disbursed_date'],
                        $row['loan_amount'],
                        $row['gender'] ?? '',
                        $row['age_category'] ?? '',
                        $row['subsector'] ?? '',
                        $row['outstanding_principal'],
                        $row['days_in_arrears'],
                        $row['bucket_current'],
                        $row['bucket_esm'],
                        $row['bucket_substandard'],
                        $row['bucket_doubtful'],
                        $row['bucket_loss'],
                        ($row['provision_rate'] ?? 0) . '%',
                        $row['provision_amount'],
                    ];
                });
                $c = $this->agingData;
                $n = $c->count();
                if ($n === 0) {
                    return $rows;
                }
                $rows->push([
                    'Total (' . $n . ' records)',
                    '', '', '', '', '',
                    $c->sum(fn ($r) => (float) ($r['loan_amount'] ?? 0)),
                    '', '', '',
                    $c->sum(fn ($r) => (float) ($r['outstanding_principal'] ?? 0)),
                    '',
                    $c->sum(fn ($r) => (float) ($r['bucket_current'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_esm'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_substandard'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_doubtful'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_loss'] ?? 0)),
                    '',
                    $c->sum(fn ($r) => (float) ($r['provision_amount'] ?? 0)),
                ]);

                return $rows;
            }

            public function headings(): array
            {
                return [
                    '#',
                    'Customer',
                    'Customer No',
                    'Phone',
                    'Loan No',
                    'Disbursed Date',
                    'Loan Amount',
                    'Gender',
                    'Age (Up to 35Yrs & Above 35Yrs)',
                    'Subsector',
                    'Outstanding principal',
                    'Days In Arrears',
                    '0-5 CURRENT (1%)',
                    '6-30 ESPECIALLY MENTIONED (5%)',
                    '31-60 SUBSTANDARD (25%)',
                    '61-90 DOUBTFUL (50%)',
                    'MORE 91 LOSS (100%)',
                    '(Kiwango cha mkopo) PROVISION RATE %',
                    '(Kiwango cha mkopo) PROVISION AMOUNT',
                ];
            }

            public function title(): string
            {
                return 'Loan Aging Report';
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastRow = (int) $sheet->getHighestRow();
                $grayHeader = [
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '999999'],
                    ],
                ];
                $styles = [1 => $grayHeader];
                if ($lastRow > 1) {
                    $styles[$lastRow] = $grayHeader;
                }

                return $styles;
            }
        }, 'loan_aging_report_' . $asOfDate . '.xlsx');
    }

    /**
     * Export Loan Aging Report to PDF
     */
    private function exportLoanAgingToPdf($agingData, $asOfDate, $branchId = null, $loanOfficerId = null)
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;
        $company = Company::first(); // Get the first company record

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('loans.reports.loan_aging_pdf', [
            'agingData' => $agingData,
            'asOfDate' => $asOfDate,
            'branch' => $branch,
            'loanOfficer' => $loanOfficer,
            'company' => $company,
        ]);

        $pdf->setPaper('A3', 'landscape');

        return $pdf->download('loan_aging_report_' . $asOfDate . '.pdf');
    }

    /**
     * Days since first overdue instalment as at $asOfDate (mirrors Loan::days_in_arrears, anchored to the report date).
     */
    private function loanDaysInArrearsAsOf(Loan $loan, string $asOfDate): int
    {
        if ($loan->status === 'restructured') {
            return 0;
        }

        $asOf = Carbon::parse($asOfDate)->startOfDay();
        $firstOverdueDate = null;

        $items = $loan->schedule()->orderBy('due_date')->get();

        foreach ($items as $scheduleItem) {
            if (($scheduleItem->status ?? null) === 'restructured') {
                continue;
            }

            $dueDate = Carbon::parse($scheduleItem->due_date)->startOfDay();
            $remaining = (float) ($scheduleItem->remaining_amount ?? 0);

            if ($dueDate->lt($asOf) && $remaining > 0) {
                $firstOverdueDate = $dueDate;
                break;
            }
        }

        if ($firstOverdueDate) {
            return (int) round($firstOverdueDate->diffInDays($asOf));
        }

        return 0;
    }

    /**
     * Place the loan's remaining principal outstanding in exactly one aging bucket
     * using loan-level days in arrears (as-of date), not per-instalment past-due days.
     *
     * Buckets: DIA 0 → Current (0–5); 1–5 → Current; 6–30 ESM; 31–60 Substandard; 61–90 Doubtful; 90+ Loss.
     * total_overdue is the principal amount counted as overdue (DIA > 0); performing loans stay 0.
     */
    private function allocatePrincipalOutstandingByDaysInArrears(float $principalOutstanding, int $daysInArrears): array
    {
        $bucket_0_5 = 0.0;
        $bucket_6_30 = 0.0;
        $bucket_31_60 = 0.0;
        $bucket_61_90 = 0.0;
        $bucket_90_plus = 0.0;
        $total_overdue = 0.0;

        if ($principalOutstanding <= 0) {
            return [
                'bucket_0_5' => $bucket_0_5,
                'bucket_6_30' => $bucket_6_30,
                'bucket_31_60' => $bucket_31_60,
                'bucket_61_90' => $bucket_61_90,
                'bucket_90_plus' => $bucket_90_plus,
                'total_overdue' => $total_overdue,
            ];
        }

        if ($daysInArrears <= 0) {
            $bucket_0_5 = $principalOutstanding;
        } elseif ($daysInArrears <= 5) {
            $bucket_0_5 = $principalOutstanding;
            $total_overdue = $principalOutstanding;
        } elseif ($daysInArrears <= 30) {
            $bucket_6_30 = $principalOutstanding;
            $total_overdue = $principalOutstanding;
        } elseif ($daysInArrears <= 60) {
            $bucket_31_60 = $principalOutstanding;
            $total_overdue = $principalOutstanding;
        } elseif ($daysInArrears <= 90) {
            $bucket_61_90 = $principalOutstanding;
            $total_overdue = $principalOutstanding;
        } else {
            $bucket_90_plus = $principalOutstanding;
            $total_overdue = $principalOutstanding;
        }

        return [
            'bucket_0_5' => $bucket_0_5,
            'bucket_6_30' => $bucket_6_30,
            'bucket_31_60' => $bucket_31_60,
            'bucket_61_90' => $bucket_61_90,
            'bucket_90_plus' => $bucket_90_plus,
            'total_overdue' => $total_overdue,
        ];
    }

    /**
     * Add overdue principal from one schedule line into installment-aging buckets by that line's days past due.
     */
    private function accumulateOverdueInstallmentPrincipalIntoBuckets(
        float $principalOverdue,
        int $daysPastDueFromSchedule,
        float &$bucket_0_5,
        float &$bucket_6_30,
        float &$bucket_31_60,
        float &$bucket_61_90,
        float &$bucket_90_plus
    ): void {
        if ($principalOverdue <= 0 || $daysPastDueFromSchedule <= 0) {
            return;
        }
        if ($daysPastDueFromSchedule <= 5) {
            $bucket_0_5 += $principalOverdue;
        } elseif ($daysPastDueFromSchedule <= 30) {
            $bucket_6_30 += $principalOverdue;
        } elseif ($daysPastDueFromSchedule <= 60) {
            $bucket_31_60 += $principalOverdue;
        } elseif ($daysPastDueFromSchedule <= 90) {
            $bucket_61_90 += $principalOverdue;
        } else {
            $bucket_90_plus += $principalOverdue;
        }
    }

    public function loanAgingInstallmentReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $loanOfficerId = $request->get('loan_officer_id');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;

        // Get aging data for installments
        $agingData = $this->getInstallmentAgingData($asOfDate, $branchId, $loanOfficerId);

        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        return view('loans.reports.loan_aging_installment', compact(
            'agingData', 'asOfDate', 'branch', 'loanOfficer', 'branches', 'loanOfficers'
        ));
    }

    public function exportLoanAgingInstallmentToExcel(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $loanOfficerId = $request->get('loan_officer_id');

        $agingData = $this->getInstallmentAgingData($asOfDate, $branchId, $loanOfficerId);

        return Excel::download(new class($agingData) implements FromArray, WithHeadings, \Maatwebsite\Excel\Concerns\WithStyles {
            private $agingData;

            public function __construct($agingData)
            {
                $this->agingData = collect($agingData);
            }

            public function array(): array
            {
                $rows = $this->agingData->values()->map(function ($row, $idx) {
                    return [
                        $idx + 1,
                        $row['customer'],
                        $row['customer_no'],
                        $row['phone'],
                        $row['loan_no'],
                        $row['amount'],
                        $row['outstanding_principal'],
                        $row['disbursed_no'],
                        $row['expiry'],
                        $row['branch'],
                        $row['loan_officer'],
                        $row['days_in_arrears'],
                        $row['bucket_0_5'],
                        $row['bucket_6_30'],
                        $row['bucket_31_60'],
                        $row['bucket_61_90'],
                        $row['bucket_90_plus'],
                        $row['total_overdue'],
                    ];
                })->toArray();
                $c = $this->agingData;
                $n = $c->count();
                if ($n === 0) {
                    return $rows;
                }
                $rows[] = [
                    'Total (' . $n . ' records)',
                    '',
                    '',
                    '',
                    '',
                    $c->sum(fn ($r) => (float) ($r['amount'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['outstanding_principal'] ?? 0)),
                    '',
                    '',
                    '',
                    '',
                    '',
                    $c->sum(fn ($r) => (float) ($r['bucket_0_5'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_6_30'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_31_60'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_61_90'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['bucket_90_plus'] ?? 0)),
                    $c->sum(fn ($r) => (float) ($r['total_overdue'] ?? 0)),
                ];

                return $rows;
            }

            public function headings(): array
            {
                return [
                    '#',
                    'Customer',
                    'Customer No',
                    'Phone',
                    'Loan No',
                    'Loan Amount',
                    'Outstanding principal',
                    'Disbursed Date',
                    'Expiry',
                    'Branch',
                    'Loan Officer',
                    'Days in Arrears',
                    'Current (0-5 days)',
                    'ESM (6-30 days)',
                    'Substandard (31-60 days)',
                    'Doubtful (61-90 days)',
                    'Loss (90+ days)',
                    'Total overdue principal',
                ];
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastRow = (int) $sheet->getHighestRow();
                $grayHeader = [
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '999999'],
                    ],
                ];
                $styles = [1 => $grayHeader];
                if ($lastRow > 1) {
                    $styles[$lastRow] = $grayHeader;
                }

                return $styles;
            }
        }, 'loan_aging_installment_report_' . $asOfDate . '.xlsx');
    }

    public function exportLoanAgingInstallmentToPdf(Request $request)
    {
        $asOfDate = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $branchId = $request->get('branch_id');
        $loanOfficerId = $request->get('loan_officer_id');

        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;
        $company = Company::first();

        $agingData = $this->getInstallmentAgingData($asOfDate, $branchId, $loanOfficerId);

        $pdf = PDF::loadView('loans.reports.loan_aging_installment_pdf', compact(
            'agingData', 'asOfDate', 'branch', 'loanOfficer', 'company'
        ));

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download('loan_aging_installment_report_' . $asOfDate . '.pdf');
    }

    private function getInstallmentAgingData($asOfDate, $branchId = null, $loanOfficerId = null)
    {
        $user = auth()->user();
        $company = $user->company;

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $query = Loan::with(['customer', 'branch', 'loanOfficer', 'schedule.repayments', 'repayments'])
            ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $query->where('branch_id', $branchId);
        }

        if ($loanOfficerId) {
            $query->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $query->get();

        $agingData = [];
        $asOfCarbon = Carbon::parse($asOfDate)->startOfDay();

        foreach ($loans as $loan) {
            $totalPrincipalPaid = 0.0;
            if (method_exists($loan, 'repayments')) {
                $totalPrincipalPaid = (float) ($loan->relationLoaded('repayments')
                    ? $loan->repayments->sum('principal')
                    : $loan->repayments()->sum('principal'));
            }
            $principalOutstanding = max(0.0, (float) (($loan->amount ?? 0) - $totalPrincipalPaid));

            $bucket_0_5 = $bucket_6_30 = $bucket_31_60 = $bucket_61_90 = $bucket_90_plus = 0.0;

            foreach ($loan->schedule as $schedule) {
                if (($schedule->status ?? null) === 'restructured') {
                    continue;
                }
                $dueDate = Carbon::parse($schedule->due_date)->startOfDay();
                if (!$dueDate->lt($asOfCarbon)) {
                    continue;
                }

                $principalPaidOnSchedule = (float) ($schedule->relationLoaded('repayments')
                    ? $schedule->repayments->sum('principal')
                    : $schedule->repayments()->sum('principal'));
                $schedPrincipal = (float) ($schedule->principal ?? 0);
                $principalOverdueOnLine = max(0.0, $schedPrincipal - $principalPaidOnSchedule);
                if ($principalOverdueOnLine <= 0) {
                    continue;
                }

                $daysPastDue = (int) Carbon::parse($schedule->due_date)->diffInDays($asOfCarbon, false);
                $this->accumulateOverdueInstallmentPrincipalIntoBuckets(
                    $principalOverdueOnLine,
                    $daysPastDue,
                    $bucket_0_5,
                    $bucket_6_30,
                    $bucket_31_60,
                    $bucket_61_90,
                    $bucket_90_plus
                );
            }

            $overduePrincipalTotal = $bucket_0_5 + $bucket_6_30 + $bucket_31_60 + $bucket_61_90 + $bucket_90_plus;
            if ($overduePrincipalTotal <= 0) {
                continue;
            }

            $agingData[] = [
                'customer' => $loan->customer->name ?? 'N/A',
                'customer_no' => $loan->customer->customerNo ?? 'N/A',
                'phone' => $loan->customer->phone1 ?? 'N/A',
                'loan_no' => $loan->loanNo ?? 'N/A',
                'amount' => $loan->amount,
                'outstanding_principal' => $principalOutstanding,
                'disbursed_no' => $loan->disbursed_on ? Carbon::parse($loan->disbursed_on)->format('d-m-Y') : 'N/A',
                'expiry' => $loan->last_repayment_date ? Carbon::parse($loan->last_repayment_date)->format('d-m-Y') : 'N/A',
                'branch' => $loan->branch->name ?? 'N/A',
                'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
                'days_in_arrears' => $this->loanDaysInArrearsAsOf($loan, $asOfDate),
                'bucket_0_5' => $bucket_0_5,
                'bucket_6_30' => $bucket_6_30,
                'bucket_31_60' => $bucket_31_60,
                'bucket_61_90' => $bucket_61_90,
                'bucket_90_plus' => $bucket_90_plus,
                'total_overdue' => $overduePrincipalTotal,
            ];
        }

        return $agingData;
    }

    /**
     * Loan Arrears Report - Shows loans with overdue payments
     */
    public function loanArrearsReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        // If this is an AJAX request for DataTables
        if ($request->ajax()) {
            return $this->getArrearsDataForDataTables($request);
        }

        // Load initial arrears data
        $arrearsData = $this->getArrearsData($branchId, $groupId, $loanOfficerId);

        return view('loans.reports.loan_arrears', compact('branches', 'groups', 'loanOfficers', 'branchId', 'groupId', 'loanOfficerId', 'arrearsData'));
    }

    /**
     * Get arrears data for AJAX DataTables
     */
    public function getArrearsDataForDataTables(Request $request)
    {
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        $arrearsData = $this->getArrearsData($branchId, $groupId, $loanOfficerId);

        return response()->json([
            'data' => $arrearsData,
            'recordsTotal' => count($arrearsData),
            'recordsFiltered' => count($arrearsData),
        ]);
    }

    /**
     * Export Loan Arrears Report to Excel
     */
    public function exportLoanArrearsToExcel(Request $request)
    {
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        $arrearsData = $this->getArrearsData($branchId, $groupId, $loanOfficerId);

        $data = [
            'arrears_data' => $arrearsData,
            'branch_name' => $branchId ? Branch::find($branchId)->name : 'All Branches',
            'group_name' => $groupId ? Group::find($groupId)->name : 'All Groups',
            'loan_officer_name' => $loanOfficerId ? User::find($loanOfficerId)->name : 'All Officers',
            'generated_date' => Carbon::now()->format('d-m-Y H:i:s'),
        ];

        return Excel::download(new \App\Exports\LoanArrearsExport($data), 'loan_arrears_report_' . date('Y_m_d') . '.xlsx');
    }

    /**
     * Export Loan Arrears Report to PDF
     */
    public function exportLoanArrearsToPdf(Request $request)
    {
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        $arrearsData = $this->getArrearsData($branchId, $groupId, $loanOfficerId);

        // Get company details
        $company = Company::first();
        $branch = $branchId ? Branch::find($branchId) : null;
        $group = $groupId ? Group::find($groupId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;

        $data = [
            'arrears_data' => $arrearsData,
            'company' => $company,
            'branch' => $branch,
            'group' => $group,
            'loan_officer' => $loanOfficer,
            'generated_date' => Carbon::now()->format('d-m-Y H:i:s'),
            'branch_name' => $branch ? $branch->name : 'All Branches',
            'group_name' => $group ? $group->name : 'All Groups',
            'loan_officer_name' => $loanOfficer ? $loanOfficer->name : 'All Officers',
        ];

        $pdf = PDF::loadView('loans.reports.loan_arrears_pdf', $data)
                  ->setPaper('A3', 'landscape');

        return $pdf->download('loan_arrears_report_' . date('Y_m_d') . '.pdf');
    }

    /**
     * Get arrears data for loans that are overdue
     */
    private function getArrearsData($branchId = null, $groupId = null, $loanOfficerId = null)
    {
        Loan::syncActiveLoansEligibleForCompletion();

        $user = auth()->user();
        $company = $user->company;

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $loansQuery = Loan::with(\App\Support\Loans\LoanReportMetrics::eagerLoads())
                          ->where('status', 'active')
                          ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($groupId) {
            $loansQuery->where('group_id', $groupId);
        }

        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();
        $arrearsData = [];

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::arrearsRow($loan);
            if ($row) {
                $arrearsData[] = $row;
            }
        }

        usort($arrearsData, fn ($a, $b) => $b['days_in_arrears'] <=> $a['days_in_arrears']);

        return $arrearsData;
    }

    /**
     * Determine arrears severity based on days overdue
     */
    private function getArrearsSeverity($daysInArrears)
    {
        if ($daysInArrears <= 30) {
            return 'Low';
        } elseif ($daysInArrears <= 60) {
            return 'Medium';
        } elseif ($daysInArrears <= 90) {
            return 'High';
        } else {
            return 'Critical';
        }
    }

    /**
     * Expected vs Collected Report - Shows expected amounts vs actual collections for a period
     */
    public function expectedVsCollectedReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        // Get the expected vs collected data
        $reportData = $this->getExpectedVsCollectedData($startDate, $endDate, $branchId, $groupId, $loanOfficerId);

        return view('loans.reports.expected_vs_collected', compact(
            'branches', 'groups', 'loanOfficers', 'startDate', 'endDate',
            'branchId', 'groupId', 'loanOfficerId', 'reportData'
        ));
    }

    /**
     * Export Expected vs Collected Report to Excel
     */
    public function exportExpectedVsCollectedToExcel(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        $reportData = $this->getExpectedVsCollectedData($startDate, $endDate, $branchId, $groupId, $loanOfficerId);

        $data = [
            'report_data' => $reportData,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'branch_name' => $branchId ? Branch::find($branchId)->name : 'All Branches',
            'group_name' => $groupId ? Group::find($groupId)->name : 'All Groups',
            'loan_officer_name' => $loanOfficerId ? User::find($loanOfficerId)->name : 'All Officers',
            'generated_date' => Carbon::now()->format('d-m-Y H:i:s'),
        ];

        return Excel::download(new \App\Exports\ExpectedVsCollectedExport($data), 'expected_vs_collected_report_' . $startDate . '_to_' . $endDate . '.xlsx');
    }

    /**
     * Export Expected vs Collected Report to PDF
     */
    public function exportExpectedVsCollectedToPdf(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');

        $reportData = $this->getExpectedVsCollectedData($startDate, $endDate, $branchId, $groupId, $loanOfficerId);

        // Get company and filter details
        $company = Company::first();
        $branch = $branchId ? Branch::find($branchId) : null;
        $group = $groupId ? Group::find($groupId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;

        $data = [
            'report_data' => $reportData,
            'company' => $company,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'branch' => $branch,
            'group' => $group,
            'loan_officer' => $loanOfficer,
            'branch_name' => $branch ? $branch->name : 'All Branches',
            'group_name' => $group ? $group->name : 'All Groups',
            'loan_officer_name' => $loanOfficer ? $loanOfficer->name : 'All Officers',
            'generated_date' => Carbon::now()->format('d-m-Y H:i:s'),
        ];

        $pdf = PDF::loadView('loans.reports.expected_vs_collected_pdf', $data)
                  ->setPaper('A3', 'landscape');

        return $pdf->download('expected_vs_collected_report_' . $startDate . '_to_' . $endDate . '.pdf');
    }

    /**
     * Get expected vs collected data for a specific period
     */
    private function getExpectedVsCollectedData($startDate, $endDate, $branchId = null, $groupId = null, $loanOfficerId = null)
    {
        Loan::syncActiveLoansEligibleForCompletion();

        $user = auth()->user();
        $company = $user->company;

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $loansQuery = Loan::with(\App\Support\Loans\LoanReportMetrics::eagerLoads())
                          ->where('status', 'active')
                          ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($groupId) {
            $loansQuery->where('group_id', $groupId);
        }

        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();
        $reportData = [];

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::expectedVsCollectedRow($loan, $startDate, $endDate);
            if ($row) {
                $reportData[] = $row;
            }
        }

        usort($reportData, fn ($a, $b) => $a['balance_due'] <=> $b['balance_due']);

        return $reportData;
    }
    /**
     * Determine collection status based on collection rate
     */
    private function getCollectionStatus($collectionRate)
    {
        if ($collectionRate >= 100) {
            return 'Excellent';
        } elseif ($collectionRate >= 80) {
            return 'Good';
        } elseif ($collectionRate >= 60) {
            return 'Fair';
        } elseif ($collectionRate >= 40) {
            return 'Poor';
        } else {
            return 'Critical';
        }
    }

    /**
     * Portfolio at Risk (PAR) Report - Shows loan portfolio risk analysis
     */
    public function portfolioAtRiskReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = $request->input('as_of_date', Carbon::now()->toDateString());
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');
        $parDays = $request->input('par_days', 30); // Default to PAR 30

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                if ($branchId !== 'all') {
                    $query->whereHas('branches', function ($q) use ($branchId) {
                        $q->where('branches.id', $branchId);
                    });
                }
            })
            ->get();

        // Get the PAR data
        $parData = $this->getPortfolioAtRiskData($asOfDate, $branchId, $groupId, $loanOfficerId, $parDays);

        return view('loans.reports.portfolio_at_risk', compact(
            'branches', 'groups', 'loanOfficers', 'asOfDate',
            'branchId', 'groupId', 'loanOfficerId', 'parDays', 'parData'
        ));
    }

    /**
     * Export Portfolio at Risk Report to Excel
     */
    public function exportPortfolioAtRiskToExcel(Request $request)
    {
        $asOfDate = $request->input('as_of_date');
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');
        $parDays = $request->input('par_days', 30);

        $parData = $this->getPortfolioAtRiskData($asOfDate, $branchId, $groupId, $loanOfficerId, $parDays);

        $branchName = 'All Branches';
        if ($branchId && $branchId !== 'all') {
            $branchName = optional(Branch::find($branchId))->name ?? 'All Branches';
        }

        $data = [
            'par_data' => $parData,
            'as_of_date' => $asOfDate,
            'par_days' => $parDays,
            'branch_name' => $branchName,
            'group_name' => $groupId ? optional(Group::find($groupId))->name : 'All Groups',
            'loan_officer_name' => $loanOfficerId ? optional(User::find($loanOfficerId))->name : 'All Officers',
            'generated_date' => Carbon::now()->format('d-m-Y H:i:s'),
            'company' => Company::first(),
        ];

        return Excel::download(new \App\Exports\PortfolioAtRiskExport($data), 'portfolio_at_risk_report_' . $asOfDate . '.xlsx');
    }

    /**
     * Export Portfolio at Risk Report to PDF
     */
    public function exportPortfolioAtRiskToPdf(Request $request)
    {
        $asOfDate = $request->input('as_of_date');
        $branchId = $request->input('branch_id');
        $groupId = $request->input('group_id');
        $loanOfficerId = $request->input('loan_officer_id');
        $parDays = $request->input('par_days', 30);

        $parData = $this->getPortfolioAtRiskData($asOfDate, $branchId, $groupId, $loanOfficerId, $parDays);

        // Get company and filter details
        $company = Company::first();
        $branch = ($branchId && $branchId !== 'all') ? Branch::find($branchId) : null;
        $group = $groupId ? Group::find($groupId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;

        $branchName = 'All Branches';
        if ($branchId && $branchId !== 'all') {
            $branchName = optional(Branch::find($branchId))->name ?? 'All Branches';
        }

        $data = [
            'par_data' => $parData,
            'company' => $company,
            'as_of_date' => $asOfDate,
            'par_days' => $parDays,
            'branch' => $branch,
            'group' => $group,
            'loan_officer' => $loanOfficer,
            'branch_name' => $branchName,
            'group_name' => $groupId ? optional(Group::find($groupId))->name : 'All Groups',
            'loan_officer_name' => $loanOfficerId ? optional(User::find($loanOfficerId))->name : 'All Officers',
            'generated_date' => Carbon::now()->format('d-m-Y H:i:s'),
        ];

        $pdf = PDF::loadView('loans.reports.portfolio_at_risk_pdf', $data)
                  ->setPaper('A3', 'landscape');

        return $pdf->download('portfolio_at_risk_report_' . $asOfDate . '.pdf');
    }

    /**
     * Loan Portfolio Tracking Report - Filters and view
     */
    public function portfolioTrackingReport(Request $request)
    {
        $fromDate = ($request->get('from_date') ?? now()->startOfMonth()->format('Y-m-d'));
        $toDate = ($request->get('to_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $groupBy = $request->get('group_by', 'day'); // day, week, month

        // Get user's assigned branches
        $user = auth()->user();
        $userBranches = $user->branches()->active()->get();

        // If user has access to multiple branches, add "All Branches" option
        $branches = $userBranches;
        if ($userBranches->count() > 1) {
            $branches = $userBranches->prepend((object)[
                'id' => 'all',
                'name' => 'All Branches',
                'branch_name' => 'All Branches'
            ]);
        }

        $groups = \App\Models\Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();

        $showData = $request->has('from_date') || $request->has('to_date') || $request->has('branch_id') || $request->has('group_id') || $request->has('loan_officer_id');
        $trackingData = [];
        if ($showData) {
            $trackingData = $this->buildPortfolioTrackingData($fromDate, $toDate, $branchId, $groupId, $loanOfficerId, $groupBy);
        }

        return view('loans.reports.portfolio_tracking', compact(
            'fromDate','toDate','branchId','groupId','loanOfficerId','groupBy','branches','groups','loanOfficers','showData','trackingData'
        ));
    }

    /**
     * Export Portfolio Tracking to Excel
     */
    public function exportPortfolioTrackingToExcel(Request $request)
    {
        $fromDate = ($request->get('from_date') ?? now()->startOfMonth()->format('Y-m-d'));
        $toDate = ($request->get('to_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $groupBy = $request->get('group_by', 'day');

        $rows = $this->buildPortfolioTrackingData($fromDate, $toDate, $branchId, $groupId, $loanOfficerId, $groupBy);

        $heading = [
            'Group',
            $groupBy !== 'day' ? 'Date Range' : null,
            'Customer Name', 'Loan Officer', 'Loan Product', 'Loan Account No.', 'Disbursement Date', 'Maturity Date',
            'Amount Disbursed', 'Interest', 'Total Amount (Principal + Interest)', 'Principal Paid', 'Interest Paid', 'Penalties Paid',
            'Outstanding Principal', 'Outstanding Interest', 'Amount Overdue', 'Days in Arrears', 'Loan Status'
        ];
        $heading = array_filter($heading); // Remove null values

        $data = [
            'headings' => $heading,
            'rows' => array_map(function($r) use ($groupBy) {
                $values = [
                    $r['group'],
                    $r['customer_name'],
                    $r['loan_officer'],
                    $r['loan_product'],
                    $r['loan_account_no'],
                    $r['disbursement_date'],
                    $r['maturity_date'],
                    $r['amount_disbursed'],
                    $r['interest'],
                    $r['total_amount'],
                    $r['principal_paid'],
                    $r['interest_paid'],
                    $r['penalties_paid'],
                    $r['outstanding_principal'],
                    $r['outstanding_interest'],
                    $r['amount_overdue'],
                    $r['days_in_arrears'],
                    $r['loan_status']
                ];

                // Insert date range if not day grouping
                if ($groupBy !== 'day') {
                    array_splice($values, 1, 0, [$r['date_range'] ?? '']);
                }

                return $values;
            }, $rows)
        ];

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\GenericArrayExport($data), 'loan_portfolio_tracking_'.$fromDate.'_'.$toDate.'.xlsx');
    }

    /**
     * Export Portfolio Tracking to PDF
     */
    public function exportPortfolioTrackingToPdf(Request $request)
    {
        $fromDate = ($request->get('from_date') ?? now()->startOfMonth()->format('Y-m-d'));
        $toDate = ($request->get('to_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $groupBy = $request->get('group_by', 'day');

        $rows = $this->buildPortfolioTrackingData($fromDate, $toDate, $branchId, $groupId, $loanOfficerId, $groupBy);

        $company = \App\Models\Company::first();
        $pdf = \PDF::loadView('loans.reports.portfolio_tracking_pdf', [
            'rows' => $rows,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'groupBy' => $groupBy,
            'company' => $company,
        ])->setPaper('A3', 'landscape');

        return $pdf->download('loan_portfolio_tracking_'.$fromDate.'_'.$toDate.'.pdf');
    }

    /**
     * Build tracking data rows according to filters
     */
    private function buildPortfolioTrackingData($fromDate, $toDate, $branchId = null, $groupId = null, $loanOfficerId = null, $groupBy = 'day')
    {
        Loan::syncActiveLoansEligibleForCompletion();

        $from = \Carbon\Carbon::parse($fromDate)->startOfDay();
        $to = \Carbon\Carbon::parse($toDate)->endOfDay();
        $metricsAsOf = LoanReportMetrics::metricsAsOfDate($toDate);

        // Get user's assigned branches
        $user = auth()->user();
        $userBranchIds = $user->branches()->pluck('branches.id')->toArray();

        $loans = \App\Models\Loan::with(['customer','branch','group','loanOfficer','product','schedule.repayments','repayments'])
            ->whereIn('branch_id', $userBranchIds)
            ->when($branchId && $branchId !== 'all', fn($q) => $q->where('branch_id', $branchId))
            ->when($groupId, fn($q) => $q->where('group_id', $groupId))
            ->when($loanOfficerId, fn($q) => $q->where('loan_officer_id', $loanOfficerId))
            ->whereNotNull('disbursed_on')
            ->whereDate('disbursed_on', '<=', $to->toDateString())
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('disbursed_on', [$from->toDateString(), $to->toDateString()])
                    ->orWhere(function ($existing) use ($from) {
                        $existing->where('disbursed_on', '<', $from->toDateString())
                            ->whereIn('status', ['active', 'defaulted']);
                    });
            })
            ->get();

        $rows = [];
        $groupedData = [];

        foreach ($loans as $loan) {
            $disbursedAmount = $loan->amount ?? 0;
            $interestAmount = $loan->interest_amount ?? 0;
            $totals = LoanReportMetrics::contractTotalsAsOf($loan, $metricsAsOf);
            $paid = $totals['paid'];
            $outstanding = $totals['outstanding'];
            $totalDue = $totals['total_due'];
            $outstandingPrincipal = $outstanding['outstanding_principal'];
            $outstandingInterest = $outstanding['outstanding_interest'];
            $amountOverdue = LoanReportMetrics::arrearsAmountAsOf($loan, $metricsAsOf);
            $daysInArrears = LoanReportMetrics::daysInArrearsAsOf($loan, $metricsAsOf);
            $reportStatus = LoanReportMetrics::effectiveReportStatus($loan, $totals['total_outstanding']);

            if ($reportStatus === Loan::STATUS_COMPLETE && $loan->status === Loan::STATUS_ACTIVE) {
                $loan->syncCompletionStatusIfEligible();
            }

            // Group key and date range
            $disbursedDate = \Carbon\Carbon::parse($loan->disbursed_on);
            $groupKey = match($groupBy) {
                'week' => $disbursedDate->startOfWeek()->format('Y-m-d'),
                'month' => $disbursedDate->format('Y-m'),
                default => $disbursedDate->format('Y-m-d')
            };

            // Calculate date range for group
            $dateRange = match($groupBy) {
                'week' => $disbursedDate->startOfWeek()->format('M d') . ' - ' . $disbursedDate->endOfWeek()->format('M d, Y'),
                'month' => $disbursedDate->format('F Y'),
                default => $disbursedDate->format('M d, Y')
            };

            $loanData = [
                'group' => $groupKey,
                'date_range' => $dateRange,
                'customer_name' => $loan->customer->name ?? 'N/A',
                'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
                'loan_product' => $loan->product->name ?? 'N/A',
                'loan_account_no' => $loan->loanNo ?? '-',
                'disbursement_date' => $loan->disbursed_on ? \Carbon\Carbon::parse($loan->disbursed_on)->format('Y-m-d') : '-',
                'maturity_date' => $loan->last_repayment_date ? \Carbon\Carbon::parse($loan->last_repayment_date)->format('Y-m-d') : '-',
                'amount_disbursed' => round($disbursedAmount, 2),
                'interest' => round($interestAmount, 2),
                'total_amount' => round($totalDue, 2),
                'principal_paid' => round($paid['principal'], 2),
                'interest_paid' => round($paid['interest'], 2),
                'penalties_paid' => round($paid['penalties'], 2),
                'outstanding_principal' => round($outstandingPrincipal, 2),
                'outstanding_interest' => round($outstandingInterest, 2),
                'amount_overdue' => round($amountOverdue, 2),
                'days_in_arrears' => $daysInArrears,
                'loan_status' => $reportStatus,
            ];

            // Group data for summary rows
            if (!isset($groupedData[$groupKey])) {
                $groupedData[$groupKey] = [
                    'date_range' => $dateRange,
                    'loans' => [],
                    'summary' => [
                        'total_loans' => 0,
                        'total_disbursed' => 0,
                        'total_interest' => 0,
                        'total_amount' => 0,
                        'total_principal_paid' => 0,
                        'total_interest_paid' => 0,
                        'total_penalties_paid' => 0,
                        'total_outstanding_principal' => 0,
                        'total_outstanding_interest' => 0,
                        'total_overdue' => 0,
                        'max_days_arrears' => 0,
                    ]
                ];
            }

            $groupedData[$groupKey]['loans'][] = $loanData;
            $groupedData[$groupKey]['summary']['total_loans']++;
            $groupedData[$groupKey]['summary']['total_disbursed'] += $disbursedAmount;
            $groupedData[$groupKey]['summary']['total_interest'] += $interestAmount;
            $groupedData[$groupKey]['summary']['total_amount'] += $totalDue;
            $groupedData[$groupKey]['summary']['total_principal_paid'] += $paid['principal'];
            $groupedData[$groupKey]['summary']['total_interest_paid'] += $paid['interest'];
            $groupedData[$groupKey]['summary']['total_penalties_paid'] += $paid['penalties'];
            $groupedData[$groupKey]['summary']['total_outstanding_principal'] += $outstandingPrincipal;
            $groupedData[$groupKey]['summary']['total_outstanding_interest'] += $outstandingInterest;
            $groupedData[$groupKey]['summary']['total_overdue'] += $amountOverdue;
            $groupedData[$groupKey]['summary']['max_days_arrears'] = max($groupedData[$groupKey]['summary']['max_days_arrears'], $daysInArrears);
        }

        // Build final rows with grouping
        foreach ($groupedData as $groupKey => $groupData) {
            // Add summary row first if not day grouping
            if ($groupBy !== 'day') {
                $rows[] = [
                    'group' => $groupKey,
                    'date_range' => $groupData['date_range'],
                    'customer_name' => "SUMMARY ({$groupData['summary']['total_loans']} loans)",
                    'loan_officer' => '',
                    'loan_product' => '',
                    'loan_account_no' => '',
                    'disbursement_date' => '',
                    'maturity_date' => '',
                    'amount_disbursed' => round($groupData['summary']['total_disbursed'], 2),
                    'interest' => round($groupData['summary']['total_interest'], 2),
                    'total_amount' => round($groupData['summary']['total_amount'], 2),
                    'principal_paid' => round($groupData['summary']['total_principal_paid'], 2),
                    'interest_paid' => round($groupData['summary']['total_interest_paid'], 2),
                    'penalties_paid' => round($groupData['summary']['total_penalties_paid'], 2),
                    'outstanding_principal' => round($groupData['summary']['total_outstanding_principal'], 2),
                    'outstanding_interest' => round($groupData['summary']['total_outstanding_interest'], 2),
                    'amount_overdue' => round($groupData['summary']['total_overdue'], 2),
                    'days_in_arrears' => $groupData['summary']['max_days_arrears'],
                    'loan_status' => '',
                    'is_summary' => true,
                ];
            }

            // Add individual loan rows
            foreach ($groupData['loans'] as $loanData) {
                $rows[] = $loanData;
            }
        }

        // Sort by group then date
        usort($rows, function($a,$b){
            return [$a['group'],$a['disbursement_date']] <=> [$b['group'],$b['disbursement_date']];
        });

        return $rows;
    }
    /**
     * PAR bucket label from days in arrears (as-of vs oldest not-fully-paid instalment due date).
     */
    private function parCategoryFromDaysInArrears(int $daysInArrears): string
    {
        if ($daysInArrears <= 0) {
            return 'Current';
        }
        if ($daysInArrears < 30) {
            return 'PAR1';
        }
        if ($daysInArrears < 90) {
            return 'PAR30';
        }

        return 'PAR90';
    }

    /**
     * Get Portfolio at Risk data (PAR report columns + DIA from oldest not-fully-paid instalment).
     */
    private function getPortfolioAtRiskData($asOfDate, $branchId = null, $groupId = null, $loanOfficerId = null, $parDays = 30)
    {
        $user = auth()->user();
        $company = $user->company;
        $asOfDateCarbon = Carbon::parse($asOfDate)->startOfDay();

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $loansQuery = Loan::with(['customer', 'branch', 'group', 'loanOfficer', 'product', 'schedule.repayments', 'repayments'])
            ->where('status', 'active')
            ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($groupId) {
            $loansQuery->where('group_id', $groupId);
        }

        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();
        $parData = [];

        foreach ($loans as $loan) {
            $principalOutstanding = 0.0;
            $interestOutstanding = 0.0;
            $amountDue = 0.0;
            $arrearsAmount = 0.0;
            $daysInArrears = 0;
            $oldestUnpaidInstallmentDue = null;

            $schedules = $loan->schedule->sortBy('due_date')->values();

            foreach ($schedules as $schedule) {
                if (($schedule->status ?? null) === 'restructured') {
                    continue;
                }
                $schedule->setRelation('loan', $loan);

                $principalLine = (float) ($schedule->principal ?? 0);
                $paidPrincipal = (float) $schedule->repayments->sum('principal');
                $remPrincipal = max(0.0, $principalLine - $paidPrincipal);

                $interestScheduled = (float) $schedule->balance_interest_component;
                $paidInterest = (float) $schedule->repayments->sum('interest');
                $remInterest = max(0.0, $interestScheduled - $paidInterest);

                $principalOutstanding += $remPrincipal;
                $interestOutstanding += $remInterest;

                $dueStart = Carbon::parse($schedule->due_date)->startOfDay();
                $remainingOnLine = (float) $schedule->remaining_amount;

                if ($dueStart->lte($asOfDateCarbon) && $remainingOnLine > 0) {
                    $amountDue += $remainingOnLine;
                }
                if ($dueStart->lt($asOfDateCarbon) && $remainingOnLine > 0) {
                    $arrearsAmount += $remainingOnLine;
                }
            }

            $totalOutstanding = round($principalOutstanding + $interestOutstanding, 2);

            if ($totalOutstanding <= 0) {
                continue;
            }

            foreach ($schedules as $schedule) {
                if (($schedule->status ?? null) === 'restructured') {
                    continue;
                }
                $schedule->setRelation('loan', $loan);
                if ((float) $schedule->remaining_amount > 0) {
                    $oldestUnpaidInstallmentDue = Carbon::parse($schedule->due_date)->startOfDay();
                    break;
                }
            }

            if ($oldestUnpaidInstallmentDue && $asOfDateCarbon->gte($oldestUnpaidInstallmentDue)) {
                $daysInArrears = (int) $oldestUnpaidInstallmentDue->diffInDays($asOfDateCarbon);
            } else {
                $daysInArrears = 0;
            }

            $parCategory = $this->parCategoryFromDaysInArrears($daysInArrears);
            $isAtRisk = $daysInArrears >= (int) $parDays;
            $atRiskAmount = $isAtRisk ? $totalOutstanding : 0.0;
            $riskPercentage = $totalOutstanding > 0 ? round(($atRiskAmount / $totalOutstanding) * 100, 2) : 0.0;
            $riskLevel = $this->getRiskLevel($daysInArrears);

            $installmentAmount = round((float) $loan->getInstallmentAmount(), 2);

            $amountPaid = 0.0;
            $repayments = $loan->relationLoaded('repayments') ? $loan->repayments : $loan->repayments()->get();
            foreach ($repayments as $rep) {
                if (!$rep->payment_date) {
                    continue;
                }
                if (Carbon::parse($rep->payment_date)->startOfDay()->lte($asOfDateCarbon)) {
                    $amountPaid += (float) ($rep->principal + $rep->interest + $rep->fee_amount + $rep->penalt_amount);
                }
            }

            $lastPaymentDate = 'N/A';
            $lastRep = $repayments
                ->filter(fn ($r) => $r->payment_date && Carbon::parse($r->payment_date)->startOfDay()->lte($asOfDateCarbon))
                ->sortByDesc('payment_date')
                ->first();
            if ($lastRep && $lastRep->payment_date) {
                $lastPaymentDate = Carbon::parse($lastRep->payment_date)->format('d-m-Y');
            }

            $disbursementDate = $loan->disbursed_on ? Carbon::parse($loan->disbursed_on)->format('d-m-Y') : 'N/A';
            $maturityDate = $loan->last_repayment_date ? Carbon::parse($loan->last_repayment_date)->format('d-m-Y') : 'N/A';

            $parData[] = [
                'loan_no' => $loan->loanNo ?? 'N/A',
                'borrower_name' => $loan->customer->name ?? 'N/A',
                'branch' => $loan->branch->name ?? 'N/A',
                'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
                'loan_product' => $loan->product->name ?? 'N/A',
                'disbursement_date' => $disbursementDate,
                'maturity_date' => $maturityDate,
                'principal_outstanding' => round($principalOutstanding, 2),
                'interest_outstanding' => round($interestOutstanding, 2),
                'total_outstanding' => $totalOutstanding,
                'installment_amount' => $installmentAmount,
                'amount_due' => round($amountDue, 2),
                'amount_paid' => round($amountPaid, 2),
                'arrears_amount' => round($arrearsAmount, 2),
                'days_in_arrears' => $daysInArrears,
                'par_category' => $parCategory,
                'last_payment_date' => $lastPaymentDate,
                'at_risk_amount' => round($atRiskAmount, 2),
                'is_at_risk' => $isAtRisk,
                'risk_percentage' => $riskPercentage,
                'risk_level' => $riskLevel,
                'par_days' => $parDays,
                'group' => $loan->group->name ?? 'N/A',
                'loan_amount' => (float) ($loan->amount ?? 0),
                'outstanding_balance' => $totalOutstanding,
                'customer' => $loan->customer->name ?? 'N/A',
                'customer_no' => $loan->customer->customerNo ?? 'N/A',
                'phone' => $loan->customer->phone1 ?? 'N/A',
            ];
        }

        usort($parData, function ($a, $b) {
            if ($a['days_in_arrears'] === $b['days_in_arrears']) {
                return $b['total_outstanding'] <=> $a['total_outstanding'];
            }

            return $b['days_in_arrears'] <=> $a['days_in_arrears'];
        });

        return $parData;
    }

    /**
     * Internal Portfolio Analysis Report (Conservative Approach)
     */
    public function internalPortfolioAnalysisReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $groupId = $request->get('group_id');
        $loanOfficerId = $request->get('loan_officer_id');
        $parDays = $request->get('par_days', 30);

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                if ($branchId !== 'all') {
                    $query->whereHas('branches', function ($q) use ($branchId) {
                        $q->where('branches.id', $branchId);
                    });
                }
            })
            ->get();
        $company = Company::first();

        $analysisData = $this->getInternalPortfolioAnalysisData($asOfDate, $branchId, $groupId, $loanOfficerId, $parDays);

        return view('loans.reports.internal_portfolio_analysis', compact(
            'analysisData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'parDays'
        ));
    }

    /**
     * Export Internal Portfolio Analysis to Excel
     */
    public function exportInternalPortfolioAnalysisToExcel(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $groupId = $request->get('group_id');
        $loanOfficerId = $request->get('loan_officer_id');
        $parDays = $request->get('par_days', 30);

        $analysisData = $this->getInternalPortfolioAnalysisData($asOfDate, $branchId, $groupId, $loanOfficerId, $parDays);
        $company = Company::first();

        $filters = [
            'as_of_date' => $asOfDate,
            'par_days' => $parDays,
            'branch_name' => $branchId ? Branch::find($branchId)->name : 'All Branches',
            'group_name' => $groupId ? Group::find($groupId)->name : 'All Groups',
            'loan_officer_name' => $loanOfficerId ? User::find($loanOfficerId)->name : 'All Officers',
        ];

        $filename = 'internal_portfolio_analysis_' . date('Y_m_d_His') . '.xlsx';

        return Excel::download(new InternalPortfolioAnalysisExport($analysisData, $filters, $company), $filename);
    }

    /**
     * Export Internal Portfolio Analysis to PDF
     */
    public function exportInternalPortfolioAnalysisToPdf(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $groupId = $request->get('group_id');
        $loanOfficerId = $request->get('loan_officer_id');
        $parDays = $request->get('par_days', 30);

        $analysisData = $this->getInternalPortfolioAnalysisData($asOfDate, $branchId, $groupId, $loanOfficerId, $parDays);
        $company = Company::first();

        $data = [
            'analysis_data' => $analysisData,
            'company' => $company,
            'generated_date' => now()->format('d-m-Y H:i:s'),
            'as_of_date' => $asOfDate,
            'par_days' => $parDays,
            'branch_name' => $branchId ? Branch::find($branchId)->name : 'All Branches',
            'group_name' => $groupId ? Group::find($groupId)->name : 'All Groups',
            'loan_officer_name' => $loanOfficerId ? User::find($loanOfficerId)->name : 'All Officers',
        ];

        $filename = 'internal_portfolio_analysis_' . date('Y_m_d_His') . '.pdf';

        $pdf = PDF::loadView('loans.reports.internal_portfolio_analysis_pdf', $data);
        $pdf->setPaper('A3', 'landscape');
        $pdf->setOptions([
            'margin-top' => 10,
            'margin-right' => 15,
            'margin-bottom' => 10,
            'margin-left' => 15,
        ]);

        return $pdf->download($filename);
    }

    /**
     * Get Internal Portfolio Analysis Data (Conservative Approach - Only Overdue Amounts)
     */
    private function getInternalPortfolioAnalysisData($asOfDate, $branchId = null, $groupId = null, $loanOfficerId = null, $parDays = 30)
    {
        $user = auth()->user();
        $company = $user->company;
        $asOfDateCarbon = Carbon::parse($asOfDate);

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $loansQuery = Loan::with(['customer', 'branch', 'group', 'loanOfficer', 'schedule.repayments'])
                          ->where('status', 'active')
                          ->whereIn('branch_id', $assignedBranchIds);

        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        if ($groupId) {
            $loansQuery->where('group_id', $groupId);
        }

        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();
        $analysisData = [];

        foreach ($loans as $loan) {
            $outstandingBalance = 0;
            $overdueAmount = 0;
            $currentAmount = 0;
            $daysInArrears = 0;
            $isAtRisk = false;
            $oldestOverdueDate = null;

            // Calculate outstanding balance and overdue amounts from schedule
            $totalDue = 0;
            $totalPaid = 0;

            foreach ($loan->schedule as $schedule) {
                $scheduleDue = ($schedule->principal ?? 0) + ($schedule->interest ?? 0) + ($schedule->fee_amount ?? 0);
                $schedulePaid = $schedule->repayments->sum('amount');
                $scheduleRemaining = $scheduleDue - $schedulePaid;

                $totalDue += $scheduleDue;
                $totalPaid += $schedulePaid;

                $dueDate = Carbon::parse($schedule->due_date);

                if ($scheduleRemaining > 0) {
                    if ($dueDate->lte($asOfDateCarbon)) {
                        // Overdue amounts
                        $daysPastDue = $asOfDateCarbon->diffInDays($dueDate);
                        $overdueAmount += $scheduleRemaining;

                        if ($daysPastDue >= $parDays) {
                            $isAtRisk = true;

                            if (!$oldestOverdueDate || $dueDate->lt($oldestOverdueDate)) {
                                $oldestOverdueDate = $dueDate;
                                $daysInArrears = $daysPastDue;
                            }
                        }
                    } else {
                        // Current/future amounts
                        $currentAmount += $scheduleRemaining;
                    }
                }
            }

            $outstandingBalance = $totalDue - $totalPaid;

            // Skip loans with no outstanding balance
            if ($outstandingBalance <= 0) {
                continue;
            }

            // Use loan model's days_in_arrears if available
            if (isset($loan->days_in_arrears) && $loan->days_in_arrears > 0) {
                $daysInArrears = $loan->days_in_arrears;
                $isAtRisk = $daysInArrears >= $parDays;
            }

            // Conservative approach: Only overdue amounts are at risk
            $atRiskAmount = $isAtRisk ? $overdueAmount : 0;

            // Calculate exposure ratios
            $overdueRatio = $outstandingBalance > 0 ? ($overdueAmount / $outstandingBalance) * 100 : 0;
            $riskRatio = $outstandingBalance > 0 ? ($atRiskAmount / $outstandingBalance) * 100 : 0;
            $riskLevel = $this->getRiskLevel($daysInArrears);

            $analysisData[] = [
                'customer' => $loan->customer->name ?? 'N/A',
                'customer_no' => $loan->customer->customerNo ?? 'N/A',
                'phone' => $loan->customer->phone1 ?? 'N/A',
                'loan_no' => $loan->loanNo ?? 'N/A',
                'loan_amount' => $loan->amount,
                'disbursed_date' => $loan->disbursed_on ? Carbon::parse($loan->disbursed_on)->format('d-m-Y') : 'N/A',
                'branch' => $loan->branch->name ?? 'N/A',
                'group' => $loan->group->name ?? 'N/A',
                'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
                'outstanding_balance' => $outstandingBalance,
                'overdue_amount' => $overdueAmount,
                'current_amount' => $currentAmount,
                'at_risk_amount' => $atRiskAmount,
                'overdue_ratio' => round($overdueRatio, 2),
                'risk_ratio' => round($riskRatio, 2),
                'days_in_arrears' => $daysInArrears,
                'oldest_overdue_date' => $oldestOverdueDate ? $oldestOverdueDate->format('d-m-Y') : 'N/A',
                'risk_level' => $riskLevel,
                'is_at_risk' => $isAtRisk,
                'par_days' => $parDays,
                'exposure_category' => $this->getExposureCategory($overdueRatio),
            ];
        }

        // Sort by overdue ratio (highest first, then by outstanding balance)
        usort($analysisData, function($a, $b) {
            if ($a['overdue_ratio'] == $b['overdue_ratio']) {
                return $b['outstanding_balance'] <=> $a['outstanding_balance'];
            }
            return $b['overdue_ratio'] <=> $a['overdue_ratio'];
        });

        return $analysisData;
    }

    /**
     * Get exposure category based on overdue ratio
     */
    private function getExposureCategory($overdueRatio)
    {
        if ($overdueRatio == 0) {
            return 'Current';
        } elseif ($overdueRatio <= 25) {
            return 'Low Exposure';
        } elseif ($overdueRatio <= 50) {
            return 'Medium Exposure';
        } elseif ($overdueRatio <= 75) {
            return 'High Exposure';
        } else {
            return 'Critical Exposure';
        }
    }

    /**
     * Determine risk level based on days in arrears
     */
    private function getRiskLevel($daysInArrears)
    {
        if ($daysInArrears == 0) {
            return 'Low';
        } elseif ($daysInArrears <= 30) {
            return 'Low';
        } elseif ($daysInArrears <= 60) {
            return 'Medium';
        } elseif ($daysInArrears <= 90) {
            return 'High';
        } else {
            return 'Critical';
        }
    }

    /**
     * Loan Portfolio Report - Comprehensive overview of all active loans
     */
    public function portfolioReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $status = $request->get('status') ?: 'active_completed';
        $exportType = $request->get('export_type');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();
        $company = Company::first();

        // Determine if we should show data (when form is submitted)
        $showData = $request->has('as_of_date') || $request->has('branch_id') || $request->has('group_id') ||
                   $request->has('loan_officer_id') || $request->has('status') || $request->isMethod('get');

        $portfolioData = null;
        if ($showData) {
            $portfolioData = $this->getPortfolioData($asOfDate, $branchId, $groupId, $loanOfficerId, $status);

            // Handle exports
            if ($exportType) {
                if ($exportType === 'excel') {
                    return $this->exportPortfolioToExcel($request);
                } elseif ($exportType === 'pdf') {
                    return $this->exportPortfolioToPdf($request);
                }
            }
        }

        return view('loans.reports.portfolio', compact(
            'portfolioData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'status', 'showData'
        ));
    }

    /**
     * Export Portfolio Report to Excel
     */
    public function exportPortfolioToExcel(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $status = $request->get('status') ?: 'active_completed';

        $portfolioData = $this->getPortfolioData($asOfDate, $branchId, $groupId, $loanOfficerId, $status);

        $filename = 'loan_portfolio_report_' . $asOfDate . '.xlsx';

        return Excel::download(new PortfolioExport($portfolioData, $status), $filename);
    }

    /**
     * Export Portfolio Report to PDF
     */
    public function exportPortfolioToPdf(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $status = $request->get('status') ?: 'active_completed';

        $branches = Branch::all();
        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();
        $company = Company::first();

        $portfolioData = $this->getPortfolioData($asOfDate, $branchId, $groupId, $loanOfficerId, $status);

        $pdf = PDF::loadView('loans.reports.portfolio_pdf', compact(
            'portfolioData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'status'
        ));

        $pdf->setPaper('A3', 'landscape');
        $pdf->setOptions(['margin-left' => 10, 'margin-right' => 10, 'margin-top' => 10, 'margin-bottom' => 10]);

        $filename = 'loan_portfolio_report_' . $asOfDate . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get Portfolio Data
     */
    private function getPortfolioData($asOfDate, $branchId = null, $groupId = null, $loanOfficerId = null, $status = 'all')
    {
        Loan::syncActiveLoansEligibleForCompletion();

        $user = auth()->user();
        $company = $user->company;

        // Get user's assigned branches
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        if (empty($assignedBranchIds)) {
            return [
                'loans' => collect([]),
                'summary' => [
                    'total_loans' => 0,
                    'total_disbursed' => 0,
                    'total_outstanding' => 0,
                    'total_paid' => 0,
                    'active_loans' => 0,
                    'completed_loans' => 0,
                    'defaulted_loans' => 0
                ]
            ];
        }

        $query = Loan::with(\App\Support\Loans\LoanReportMetrics::eagerLoads())
            ->whereIn('branch_id', $assignedBranchIds)
            ->when($branchId && $branchId !== 'all', function($q) use ($branchId) {
                return $q->where('branch_id', $branchId);
            })
            ->when($groupId, function($q) use ($groupId) {
                return $q->where('group_id', $groupId);
            })
            ->when($loanOfficerId, function($q) use ($loanOfficerId) {
                return $q->where('loan_officer_id', $loanOfficerId);
            });

        if ($status !== 'all') {
            if (in_array($status, ['active', 'completed', 'active_completed'], true)) {
                $query->whereIn('status', ['active', 'completed']);
            } else {
                $query->where('status', $status);
            }
        }

        $loans = $query->get();
        $portfolioData = [];

        $totalDisbursed = 0;
        $totalOutstanding = 0;
        $totalPaid = 0;
        $totalLoans = $loans->count();
        $activeLoans = 0;
        $completedLoans = 0;
        $defaultedLoans = 0;

        foreach ($loans as $loan) {
            $row = LoanReportRowBuilder::portfolioRow($loan, $asOfDate);
            $effectiveStatus = $row['status'];

            if ($status === 'active' && $effectiveStatus !== Loan::STATUS_ACTIVE) {
                continue;
            }

            if ($status === 'completed' && $effectiveStatus !== Loan::STATUS_COMPLETE) {
                continue;
            }

            if ($status === 'defaulted' && $effectiveStatus !== Loan::STATUS_DEFAULTED) {
                continue;
            }

            if ($effectiveStatus === 'active') {
                $activeLoans++;
            } elseif ($effectiveStatus === 'completed') {
                $completedLoans++;
            } elseif ($effectiveStatus === 'defaulted') {
                $defaultedLoans++;
            }

            $portfolioData[] = array_merge($row, ['loan_id' => $loan->id]);

            $totalDisbursed += $row['disbursed_amount'];
            $totalOutstanding += $row['outstanding_balance'];
            $totalPaid += $row['total_paid'];
        }

        $totalLoans = count($portfolioData);

        $overallRepaymentRate = $totalDisbursed > 0 ? ($totalPaid / ($totalPaid + $totalOutstanding)) * 100 : 0;
        $portfolioAtRisk = collect($portfolioData)->where('is_in_arrears', true)->sum('outstanding_balance');
        $parRatio = $totalOutstanding > 0 ? ($portfolioAtRisk / $totalOutstanding) * 100 : 0;

        return [
            'summary' => [
                'as_of_date' => $asOfDate,
                'total_loans' => $totalLoans,
                'active_loans' => $activeLoans,
                'completed_loans' => $completedLoans,
                'defaulted_loans' => $defaultedLoans,
                'total_disbursed' => $totalDisbursed,
                'total_outstanding' => $totalOutstanding,
                'total_paid' => $totalPaid,
                'overall_repayment_rate' => $overallRepaymentRate,
                'portfolio_at_risk' => $portfolioAtRisk,
                'par_ratio' => $parRatio,
            ],
            'loans' => $portfolioData,
        ];
    }

    // =========================================================================
    // Loan Portfolio Classification Report
    // =========================================================================

    /**
     * Main handler for the Portfolio Classification report.
     */
    public function portfolioClassificationReport(Request $request)
    {
        $user    = auth()->user();
        $company = $user->company;

        $asOfDate      = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $branchId      = $request->get('branch_id') ?: null;
        $groupId       = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $status        = $request->get('status') ?: 'active_completed';
        $exportType    = $request->get('export_type');

        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups       = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, fn($q) => $q->whereHas('branches', fn($sq) =>
                $sq->where('branches.id', $branchId)))
            ->get();
        $company  = Company::first();
        $showData = $request->isMethod('get');

        $reportData = null;
        if ($showData) {
            $reportData = $this->getPortfolioClassificationData(
                $asOfDate, $branchId, $groupId, $loanOfficerId, $status
            );

            if ($exportType === 'excel') {
                return $this->exportPortfolioClassificationToExcel($request);
            }
            if ($exportType === 'pdf') {
                return $this->exportPortfolioClassificationToPdf($request);
            }
        }

        return view('loans.reports.portfolio_classification', compact(
            'reportData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'status', 'showData'
        ));
    }

    /**
     * Export Portfolio Classification report to Excel.
     */
    public function exportPortfolioClassificationToExcel(Request $request)
    {
        $asOfDate      = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $branchId      = $request->get('branch_id') ?: null;
        $groupId       = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $status        = $request->get('status') ?: 'active_completed';

        $reportData = $this->getPortfolioClassificationData(
            $asOfDate, $branchId, $groupId, $loanOfficerId, $status
        );

        $filename = 'loan_portfolio_classification_' . $asOfDate . '.xlsx';
        return Excel::download(new PortfolioClassificationExport($reportData, $status), $filename);
    }

    /**
     * Export Portfolio Classification report to PDF.
     */
    public function exportPortfolioClassificationToPdf(Request $request)
    {
        $asOfDate      = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $branchId      = $request->get('branch_id') ?: null;
        $groupId       = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $status        = $request->get('status') ?: 'active_completed';

        $branches     = Branch::all();
        $groups       = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, fn($q) => $q->whereHas('branches', fn($sq) =>
                $sq->where('branches.id', $branchId)))
            ->get();
        $company = Company::first();

        $reportData = $this->getPortfolioClassificationData(
            $asOfDate, $branchId, $groupId, $loanOfficerId, $status
        );

        $pdf = PDF::loadView('loans.reports.portfolio_classification_pdf', compact(
            'reportData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'status'
        ));
        $pdf->setPaper('A3', 'landscape');
        $pdf->setOptions(['margin-left' => 5, 'margin-right' => 5, 'margin-top' => 8, 'margin-bottom' => 8]);

        return $pdf->download('loan_portfolio_classification_' . $asOfDate . '.pdf');
    }

    /**
     * Fetch and compute all data for the Portfolio Classification report.
     */
    private function getPortfolioClassificationData(
        string $asOfDate,
        ?int $branchId      = null,
        ?int $groupId       = null,
        ?int $loanOfficerId = null,
        string $status      = 'active_completed'
    ): array {
        $user    = auth()->user();
        $company = $user->company;

        // Active arrears classification buckets for this company
        $classifications = ArrearsClassification::forCompany()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('days_from')
            ->get();
        $hasClassifications = $classifications->isNotEmpty();

        // Initialise summary
        $bucketTotals = $classifications->pluck('id')->mapWithKeys(fn($id) => [$id => 0.0])->all();
        $summary = [
            'total_loans'               => 0,
            'total_disbursed'           => 0.0,
            'total_interest_paid'       => 0.0,
            'total_due_interest_unpaid' => 0.0,
            'total_fee_unpaid'          => 0.0,
            'total_fee_paid'            => 0.0,
            'total_principal_collected' => 0.0,
            'total_accrued_interest'    => 0.0,
            'total_outstanding'         => 0.0,
            'total_provision'           => 0.0,
            'bucket_totals'             => $bucketTotals,
            'as_of_date'                => $asOfDate,
        ];

        // Scope to user's assigned branches
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        if (empty($assignedBranchIds)) {
            return [
                'classifications'    => collect([]),
                'has_classifications' => false,
                'loans'              => [],
                'summary'            => $summary,
            ];
        }

        $query = Loan::with(array_merge(\App\Support\Loans\LoanReportMetrics::eagerLoads(), ['schedule']))
        ->whereIn('branch_id', $assignedBranchIds)
        ->when($branchId && $branchId !== 'all', fn($q) => $q->where('branch_id', $branchId))
        ->when($groupId, fn($q) => $q->where('group_id', $groupId))
        ->when($loanOfficerId, fn($q) => $q->where('loan_officer_id', $loanOfficerId));

        if ($status === 'active_completed') {
            $query->whereIn('status', ['active', 'completed']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $loans    = $query->get();
        $loansData = [];
        $serial   = 0;
        $asOfCarbon = Carbon::parse($asOfDate);
        $metricsDate = \App\Support\Loans\LoanReportMetrics::metricsAsOfDate($asOfDate);

        foreach ($loans as $loan) {
            $serial++;

            // ---- Basic financials ----
            $principalDisbursed  = (float) ($loan->amount ?? 0);
            $interestPaid        = (float) $loan->repayments->sum('interest');
            $feePaid             = \App\Support\Loans\LoanFeeMetrics::totalFeesPaid($loan, $metricsDate);
            $principalCollected  = (float) $loan->repayments->sum('principal');
            $accruedInterest     = (float) $loan->schedule->sum('accrued_interest');
            $outstandingBalance  = (float) $loan->getTotalOutstandingAmount();
            $daysInArrears       = (int) ($loan->days_in_arrears ?? 0);

            $feeUnpaid = \App\Support\Loans\LoanReportMetrics::outstandingFeesAsOf($loan, $metricsDate);

            // Due interest unpaid: overdue schedules with remaining interest
            $dueInterestUnpaid = 0.0;
            foreach ($loan->schedule as $scheduleItem) {
                if (Carbon::parse($scheduleItem->due_date)->lt($asOfCarbon) && $scheduleItem->remaining_amount > 0) {
                    $schedInterestPaid  = (float) $scheduleItem->repayments->sum('interest');
                    $dueInterestUnpaid += max(0.0, (float) $scheduleItem->interest - $schedInterestPaid);
                }
            }

            // ---- Customer details ----
            $genderRaw = $loan->customer->sex ?? null;
            $gender    = $genderRaw === 'M' ? 'Male' : ($genderRaw === 'F' ? 'Female' : 'N/A');
            $age       = ($loan->customer && $loan->customer->dob)
                ? (int) Carbon::parse($loan->customer->dob)->diffInYears($asOfCarbon)
                : null;

            // ---- Loan product type ----
            $loanProductType = $loan->product->name ?? 'N/A';

            // ---- Bucket matching ----
            $bucketAmounts  = $classifications->pluck('id')->mapWithKeys(fn($id) => [$id => 0.0])->all();
            $matchedClsId   = null;
            $provisionPct   = 0.0;

            foreach ($classifications as $cls) {
                $inBucket = $daysInArrears >= $cls->days_from
                    && ($cls->days_to === null || $daysInArrears <= $cls->days_to);
                if ($inBucket) {
                    $bucketAmounts[$cls->id] = $outstandingBalance;
                    $matchedClsId            = $cls->id;
                    $provisionPct            = (float) $cls->provision_percentage;
                    break;
                }
            }

            $provisionAmount = round($outstandingBalance * ($provisionPct / 100), 2);

            $loansData[] = [
                'serial'                    => $serial,
                'disbursement_date'         => $loan->disbursed_on
                    ? Carbon::parse($loan->disbursed_on)->format('Y-m-d') : 'N/A',
                'customer_name'             => $loan->customer->name ?? 'N/A',
                'gender'                    => $gender,
                'age'                       => $age,
                'branch'                    => $loan->branch->name ?? 'N/A',
                'loan_product_type'         => $loanProductType,
                'principal_disbursed'       => $principalDisbursed,
                'interest_paid'             => round($interestPaid, 2),
                'due_interest_unpaid'       => round($dueInterestUnpaid, 2),
                'fee_unpaid'                => $feeUnpaid,
                'fee_paid'                  => round($feePaid, 2),
                'principal_collected'       => round($principalCollected, 2),
                'accrued_interest'          => round($accruedInterest, 2),
                'outstanding_balance'       => round($outstandingBalance, 2),
                'past_due_days'             => $daysInArrears,
                'provision_rate'            => $provisionPct,
                'bucket_amounts'            => $bucketAmounts,
                'matched_classification_id' => $matchedClsId,
                'provision_amount'          => $provisionAmount,
                'status'                    => $loan->status,
            ];

            // Accumulate summary
            $summary['total_loans']++;
            $summary['total_disbursed']           += $principalDisbursed;
            $summary['total_interest_paid']       += $interestPaid;
            $summary['total_due_interest_unpaid'] += $dueInterestUnpaid;
            $summary['total_fee_unpaid']          += $feeUnpaid;
            $summary['total_fee_paid']            += $feePaid;
            $summary['total_principal_collected'] += $principalCollected;
            $summary['total_accrued_interest']    += $accruedInterest;
            $summary['total_outstanding']         += $outstandingBalance;
            $summary['total_provision']           += $provisionAmount;
            foreach ($bucketAmounts as $clsId => $bucketAmt) {
                $summary['bucket_totals'][$clsId] = ($summary['bucket_totals'][$clsId] ?? 0.0) + $bucketAmt;
            }
        }

        // Round summary totals
        foreach (['total_disbursed', 'total_interest_paid', 'total_due_interest_unpaid',
                  'total_fee_unpaid', 'total_fee_paid', 'total_principal_collected',
                  'total_accrued_interest', 'total_outstanding', 'total_provision'] as $key) {
            $summary[$key] = round($summary[$key], 2);
        }
        foreach ($summary['bucket_totals'] as $id => $val) {
            $summary['bucket_totals'][$id] = round($val, 2);
        }

        return [
            'classifications'    => $classifications,
            'has_classifications' => $hasClassifications,
            'loans'              => $loansData,
            'summary'            => $summary,
        ];
    }

    /**
     * Loan Performance Report - Analyze loan performance metrics and repayment trends
     */
    public function performanceReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $fromDate = ($request->get('from_date') ?? now()->subMonth()->format('Y-m-d'));
        $toDate = ($request->get('to_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $exportType = $request->get('export_type');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();
        $company = Company::first();

        // Determine if we should show data (when form is submitted)
        $showData = $request->has('from_date') || $request->has('to_date') || $request->has('branch_id') ||
                   $request->has('group_id') || $request->has('loan_officer_id') || $request->isMethod('get');

        $performanceData = null;
        if ($showData) {
            $performanceData = $this->getPerformanceData($fromDate, $toDate, $branchId, $groupId, $loanOfficerId);

            // Handle exports
            if ($exportType) {
                if ($exportType === 'excel') {
                    return $this->exportPerformanceToExcel($request);
                } elseif ($exportType === 'pdf') {
                    return $this->exportPerformanceToPdf($request);
                }
            }
        }

        return view('loans.reports.performance', compact(
            'performanceData', 'branches', 'groups', 'loanOfficers', 'company',
            'fromDate', 'toDate', 'branchId', 'groupId', 'loanOfficerId', 'showData'
        ));
    }

    /**
     * Export Performance Report to Excel
     */
    public function exportPerformanceToExcel(Request $request)
    {
        $fromDate = ($request->get('from_date') ?? now()->subMonth()->format('Y-m-d'));
        $toDate = ($request->get('to_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;

        $performanceData = $this->getPerformanceData($fromDate, $toDate, $branchId, $groupId, $loanOfficerId);

        $filename = 'loan_performance_report_' . $fromDate . '_to_' . $toDate . '.xlsx';

        return Excel::download(new PerformanceExport($performanceData), $filename);
    }

    /**
     * Export Performance Report to PDF
     */
    public function exportPerformanceToPdf(Request $request)
    {
        $fromDate = ($request->get('from_date') ?? now()->subMonth()->format('Y-m-d'));
        $toDate = ($request->get('to_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;

        $branches = Branch::all();
        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();
        $company = Company::first();

        $performanceData = $this->getPerformanceData($fromDate, $toDate, $branchId, $groupId, $loanOfficerId);

        $pdf = PDF::loadView('loans.reports.performance_pdf', compact(
            'performanceData', 'branches', 'groups', 'loanOfficers', 'company',
            'fromDate', 'toDate', 'branchId', 'groupId', 'loanOfficerId'
        ));

        $pdf->setPaper('A3', 'landscape');
        $pdf->setOptions(['margin-left' => 10, 'margin-right' => 10, 'margin-top' => 10, 'margin-bottom' => 10]);

        $filename = 'loan_performance_report_' . $fromDate . '_to_' . $toDate . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get Performance Data
     */
    private function getPerformanceData($fromDate, $toDate, $branchId = null, $groupId = null, $loanOfficerId = null)
    {
        Loan::syncActiveLoansEligibleForCompletion();

        $user = auth()->user();
        $company = $user->company;

        // Get user's assigned branches
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        if (empty($assignedBranchIds)) {
            return [
                'loans' => collect([]),
                'summary' => [
                    'total_loans' => 0,
                    'excellent_loans' => 0,
                    'good_loans' => 0,
                    'fair_loans' => 0,
                    'poor_loans' => 0,
                    'critical_loans' => 0,
                    'total_disbursed' => 0,
                    'total_outstanding' => 0,
                    'total_repaid' => 0,
                    'loans_in_arrears' => 0,
                    'on_time_payments' => 0,
                    'late_payments' => 0,
                    'average_days_in_arrears' => 0,
                    'periodic_repayments' => 0,
                    'repayment_rate' => 0,
                    'collection_rate' => 0
                ]
            ];
        }

        $query = Loan::with(['customer', 'branch', 'group', 'loanOfficer', 'schedule', 'schedule.repayments'])
            ->where('status', 'active')
            ->whereIn('branch_id', $assignedBranchIds)
            ->when($branchId && $branchId !== 'all', function($q) use ($branchId) {
                return $q->where('branch_id', $branchId);
            })
            ->when($groupId, function($q) use ($groupId) {
                return $q->where('group_id', $groupId);
            })
            ->when($loanOfficerId, function($q) use ($loanOfficerId) {
                return $q->where('loan_officer_id', $loanOfficerId);
            });

        $loans = $query->get();
        $performanceData = [];

        // Period metrics
        $periodicRepayments = Repayment::whereBetween('payment_date', [$fromDate, $toDate])
            ->whereHas('schedule.loan', function($lq) use ($assignedBranchIds) {
                $lq->whereIn('branch_id', $assignedBranchIds);
            })
            ->when($branchId && $branchId !== 'all', function($q) use ($branchId) {
                return $q->whereHas('schedule.loan', function($lq) use ($branchId) {
                    $lq->where('branch_id', $branchId);
                });
            })
            ->when($groupId, function($q) use ($groupId) {
                return $q->whereHas('schedule.loan', function($lq) use ($groupId) {
                    $lq->where('group_id', $groupId);
                });
            })
            ->when($loanOfficerId, function($q) use ($loanOfficerId) {
                return $q->whereHas('schedule.loan', function($lq) use ($loanOfficerId) {
                    $lq->where('loan_officer_id', $loanOfficerId);
                });
            })
            ->sum(DB::raw('principal + interest + COALESCE(fee_amount, 0) + COALESCE(penalt_amount, 0)'));

        $totalLoans = $loans->count();
        $totalDisbursed = 0;
        $totalOutstanding = 0;
        $totalRepaid = 0;
        $loansInArrears = 0;
        $onTimePayments = 0;
        $latePayments = 0;
        $averageDaysInArrears = 0;
        $totalDaysInArrears = 0;

        foreach ($loans as $loan) {
            $disbursedAmount = $loan->amount ?? 0;
            $totals = LoanReportMetrics::contractTotals($loan);
            $totalDue = $totals['total_due'];
            $totalPaid = $totals['total_paid'];
            $outstandingAmount = $totals['total_outstanding'];
            $daysInArrears = $loan->days_in_arrears ?? 0;
            $isInArrears = $daysInArrears > 0;
            $repaymentRate = $totals['repayment_rate'];

            if ($isInArrears) {
                $loansInArrears++;
                $totalDaysInArrears += $daysInArrears;
            }

            // Payment performance analysis
            $schedulePayments = $loan->schedule()->whereHas('repayments', function($q) use ($fromDate, $toDate) {
                $q->whereBetween('payment_date', [$fromDate, $toDate]);
            })->get();

            foreach ($schedulePayments as $schedule) {
                $repayments = $schedule->repayments()->whereBetween('payment_date', [$fromDate, $toDate])->get();
                foreach ($repayments as $repayment) {
                    if ($repayment->payment_date <= $schedule->due_date) {
                        $onTimePayments++;
                    } else {
                        $latePayments++;
                    }
                }
            }

            $performanceData[] = [
                'loan_id' => $loan->id,
                'customer' => $loan->customer->name ?? 'N/A',
                'customer_no' => $loan->customer->customerNo ?? 'N/A',
                'branch' => $loan->branch->name ?? 'N/A',
                'group' => $loan->group->name ?? 'N/A',
                'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
                'disbursed_amount' => $disbursedAmount,
                'outstanding_amount' => $outstandingAmount,
                'total_paid' => $totalPaid,
                'repayment_rate' => $repaymentRate,
                'days_in_arrears' => $daysInArrears,
                'is_in_arrears' => $isInArrears,
                'performance_grade' => $this->getPerformanceGrade($repaymentRate, $daysInArrears),
                'risk_category' => $this->getRiskCategory($daysInArrears),
            ];

            $totalDisbursed += $disbursedAmount;
            $totalOutstanding += $outstandingAmount;
            $totalRepaid += $totalPaid;
        }

        // Calculate averages and ratios
        $averageDaysInArrears = $loansInArrears > 0 ? $totalDaysInArrears / $loansInArrears : 0;
        $totalPayments = $onTimePayments + $latePayments;
        $onTimePaymentRate = $totalPayments > 0 ? ($onTimePayments / $totalPayments) * 100 : 0;
        $latePaymentRate = $totalPayments > 0 ? ($latePayments / $totalPayments) * 100 : 0;
        $arrearsRate = $totalLoans > 0 ? ($loansInArrears / $totalLoans) * 100 : 0;
        $overallRepaymentRate = $totalDisbursed > 0 ? ($totalRepaid / $totalDisbursed) * 100 : 0;

        // Calculate performance grades counts
        $excellent_loans = collect($performanceData)->where('performance_grade', 'Excellent')->count();
        $good_loans = collect($performanceData)->where('performance_grade', 'Good')->count();
        $fair_loans = collect($performanceData)->where('performance_grade', 'Fair')->count();
        $poor_loans = collect($performanceData)->where('performance_grade', 'Poor')->count();
        $critical_loans = collect($performanceData)->where('performance_grade', 'Critical')->count();

        // Calculate average repayment rate
        $average_repayment_rate = $totalLoans > 0
            ? collect($performanceData)->avg('repayment_rate')
            : 0;

        // Calculate total collections (all time)
        $total_collections = collect($performanceData)->sum('total_paid');

        // Calculate period collections (for the selected period)
        $period_collections = $periodicRepayments;

        return [
            'summary' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'total_loans' => $totalLoans,
                'excellent_loans' => $excellent_loans,
                'good_loans' => $good_loans,
                'fair_loans' => $fair_loans,
                'poor_loans' => $poor_loans,
                'critical_loans' => $critical_loans,
                'average_repayment_rate' => $average_repayment_rate,
                'total_collections' => $total_collections,
                'period_collections' => $period_collections,
                'total_disbursed' => $totalDisbursed,
                'total_outstanding' => $totalOutstanding,
                'total_repaid' => $totalRepaid,
                'periodic_repayments' => $periodicRepayments,
                'loans_in_arrears' => $loansInArrears,
                'average_days_in_arrears' => $averageDaysInArrears,
                'on_time_payments' => $onTimePayments,
                'late_payments' => $latePayments,
                'on_time_payment_rate' => $onTimePaymentRate,
                'late_payment_rate' => $latePaymentRate,
                'arrears_rate' => $arrearsRate,
                'overall_repayment_rate' => $overallRepaymentRate,
            ],
            'loans' => $performanceData,
        ];
    }

    /**
     * Get Performance Grade
     */
    private function getPerformanceGrade($repaymentRate, $daysInArrears)
    {
        if ($repaymentRate >= 95 && $daysInArrears == 0) {
            return 'Excellent';
        } elseif ($repaymentRate >= 85 && $daysInArrears <= 15) {
            return 'Good';
        } elseif ($repaymentRate >= 70 && $daysInArrears <= 30) {
            return 'Fair';
        } elseif ($repaymentRate >= 50 && $daysInArrears <= 60) {
            return 'Poor';
        } else {
            return 'Critical';
        }
    }

    /**
     * Get Risk Category
     */
    private function getRiskCategory($daysInArrears)
    {
        if ($daysInArrears == 0) {
            return 'Low Risk';
        } elseif ($daysInArrears <= 30) {
            return 'Medium Risk';
        } elseif ($daysInArrears <= 90) {
            return 'High Risk';
        } else {
            return 'Critical Risk';
        }
    }

    /**
     * Delinquency Report - Track overdue loans and payment delinquencies
     */
    public function delinquencyReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $bucket = $request->get('bucket');
        $delinquencyDays = $request->get('delinquency_days', 1); // Minimum days to be considered delinquent
        $exportType = $request->get('export_type');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();
        $company = Company::first();

        // Determine if we should show data (when form is submitted)
        $showData = $request->has('as_of_date') || $request->has('branch_id') || $request->has('group_id') ||
                   $request->has('loan_officer_id') || $request->has('bucket') || $request->isMethod('get');

        $delinquencyData = null;
        if ($showData) {
            $delinquencyData = $this->getDelinquencyData($asOfDate, $branchId, $groupId, $loanOfficerId, $delinquencyDays, $bucket);

            // Handle exports
            if ($exportType) {
                if ($exportType === 'excel') {
                    return $this->exportDelinquencyToExcel($request);
                } elseif ($exportType === 'pdf') {
                    return $this->exportDelinquencyToPdf($request);
                }
            }
        }

        return view('loans.reports.delinquency', compact(
            'delinquencyData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'bucket', 'delinquencyDays', 'showData'
        ));
    }

    /**
     * Export Delinquency Report to Excel
     */
    public function exportDelinquencyToExcel(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $delinquencyDays = $request->get('delinquency_days', 1);
        $bucket = $request->get('bucket') ?: null;

        $delinquencyData = $this->getDelinquencyData($asOfDate, $branchId, $groupId, $loanOfficerId, $delinquencyDays, $bucket);

        $filename = 'delinquency_report_' . $asOfDate . '.xlsx';

        return Excel::download(new DelinquencyExport($delinquencyData), $filename);
    }

    /**
     * Export Delinquency Report to PDF
     */
    public function exportDelinquencyToPdf(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id') ?: null;
        $groupId = $request->get('group_id') ?: null;
        $loanOfficerId = $request->get('loan_officer_id') ?: null;
        $delinquencyDays = $request->get('delinquency_days', 1);
        $bucket = $request->get('bucket') ?: null;

        $branches = Branch::all();
        $groups = Group::all();
        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                });
            })
            ->get();
        $company = Company::first();

        $delinquencyData = $this->getDelinquencyData($asOfDate, $branchId, $groupId, $loanOfficerId, $delinquencyDays, $bucket);

        $pdf = PDF::loadView('loans.reports.delinquency_pdf', compact(
            'delinquencyData', 'branches', 'groups', 'loanOfficers', 'company',
            'asOfDate', 'branchId', 'groupId', 'loanOfficerId', 'delinquencyDays', 'bucket'
        ));

        $pdf->setPaper('A3', 'landscape');
        $pdf->setOptions(['margin-left' => 10, 'margin-right' => 10, 'margin-top' => 10, 'margin-bottom' => 10]);

        $filename = 'delinquency_report_' . $asOfDate . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get Delinquency Data
     */
    private function getDelinquencyData($asOfDate, $branchId = null, $groupId = null, $loanOfficerId = null, $delinquencyDays = 1, $bucket = null)
    {
        $user = auth()->user();
        $company = $user->company;

        // Get user's assigned branches
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        if (empty($assignedBranchIds)) {
            return [
                'loans' => collect([]),
                'summary' => [
                    'total_loans' => 0,
                    'total_delinquent_loans' => 0,
                    'total_delinquent_amount' => 0,
                    'total_outstanding' => 0,
                    'delinquency_rate' => 0,
                    'bucket_1_30' => 0,
                    'bucket_31_60' => 0,
                    'bucket_61_90' => 0,
                    'bucket_91_180' => 0,
                    'bucket_180_plus' => 0
                ]
            ];
        }

        $query = Loan::with(['customer', 'branch', 'group', 'loanOfficer', 'schedule', 'schedule.repayments'])
            ->where('status', 'active')
            ->whereIn('branch_id', $assignedBranchIds)
            ->when($branchId && $branchId !== 'all', function($q) use ($branchId) {
                return $q->where('branch_id', $branchId);
            })
            ->when($groupId, function($q) use ($groupId) {
                return $q->where('group_id', $groupId);
            })
            ->when($loanOfficerId, function($q) use ($loanOfficerId) {
                return $q->where('loan_officer_id', $loanOfficerId);
            });

        $loans = $query->get();
        $delinquencyData = [];

        $totalLoans = $loans->count();
        $delinquentLoans = 0;
        $totalDelinquentAmount = 0;
        $totalOutstanding = 0;

        // Delinquency buckets
        $bucket1to30 = ['count' => 0, 'amount' => 0]; // 1-30 days
        $bucket31to60 = ['count' => 0, 'amount' => 0]; // 31-60 days
        $bucket61to90 = ['count' => 0, 'amount' => 0]; // 61-90 days
        $bucket91to180 = ['count' => 0, 'amount' => 0]; // 91-180 days
        $bucket180plus = ['count' => 0, 'amount' => 0]; // 180+ days

        foreach ($loans as $loan) {
            // Calculate loan metrics
            $totalDue = $loan->schedule->sum(function($schedule) {
                return $schedule->principal + $schedule->interest + ($schedule->fee_amount ?? 0);
            });
            $totalPaid = $loan->schedule->sum(function($schedule) {
                return $schedule->repayments->sum('amount');
            });
            $outstandingAmount = $totalDue - $totalPaid;
            $totalOutstanding += $outstandingAmount;

            // Get days in arrears
            $daysInArrears = $loan->days_in_arrears ?? 0;
            $isDelinquent = $daysInArrears >= $delinquencyDays;

            if ($isDelinquent) {
                $delinquentLoans++;
                $totalDelinquentAmount += $outstandingAmount;

                // Categorize into buckets
                if ($daysInArrears >= 1 && $daysInArrears <= 30) {
                    $bucket1to30['count']++;
                    $bucket1to30['amount'] += $outstandingAmount;
                } elseif ($daysInArrears >= 31 && $daysInArrears <= 60) {
                    $bucket31to60['count']++;
                    $bucket31to60['amount'] += $outstandingAmount;
                } elseif ($daysInArrears >= 61 && $daysInArrears <= 90) {
                    $bucket61to90['count']++;
                    $bucket61to90['amount'] += $outstandingAmount;
                } elseif ($daysInArrears >= 91 && $daysInArrears <= 180) {
                    $bucket91to180['count']++;
                    $bucket91to180['amount'] += $outstandingAmount;
                } else {
                    $bucket180plus['count']++;
                    $bucket180plus['amount'] += $outstandingAmount;
                }

                $delinquencyData[] = [
                    'loan_id' => $loan->id,
                    'customer' => $loan->customer->name ?? 'N/A',
                    'customer_no' => $loan->customer->customerNo ?? 'N/A',
                    'phone' => $loan->customer->phone1 ?? 'N/A',
                    'branch' => $loan->branch->name ?? 'N/A',
                    'group' => $loan->group->name ?? 'N/A',
                    'loan_officer' => $loan->loanOfficer->name ?? 'N/A',
                    'outstanding_amount' => $outstandingAmount,
                    'days_in_arrears' => $daysInArrears,
                    'delinquency_bucket' => $this->getDelinquencyBucket($daysInArrears),
                    'severity_level' => $this->getSeverityLevel($daysInArrears),
                    'disbursed_date' => $loan->disbursed_on ? Carbon::parse($loan->disbursed_on)->format('Y-m-d') : 'N/A', // Use 'disbursed_on'
                    'last_payment_date' => $this->getLastPaymentDate($loan),
                    'next_due_date' => $this->getNextDueDate($loan),
                ];
            }
        }

        // Apply bucket filter if specified
        if ($bucket && !empty($delinquencyData)) {
            $delinquencyData = collect($delinquencyData)->filter(function($loan) use ($bucket) {
                $daysInArrears = $loan['days_in_arrears'];

                switch ($bucket) {
                    case '1-30':
                        return $daysInArrears >= 1 && $daysInArrears <= 30;
                    case '31-60':
                        return $daysInArrears >= 31 && $daysInArrears <= 60;
                    case '61-90':
                        return $daysInArrears >= 61 && $daysInArrears <= 90;
                    case '91-180':
                        return $daysInArrears >= 91 && $daysInArrears <= 180;
                    case '180+':
                        return $daysInArrears > 180;
                    default:
                        return true;
                }
            })->values()->toArray();

            // Recalculate summary metrics for filtered data
            $delinquentLoans = count($delinquencyData);
            $totalDelinquentAmount = collect($delinquencyData)->sum('outstanding_amount');
        }

        // Calculate percentages
        $delinquencyRate = $totalLoans > 0 ? ($delinquentLoans / $totalLoans) * 100 : 0;
        $delinquentAmountRate = $totalOutstanding > 0 ? ($totalDelinquentAmount / $totalOutstanding) * 100 : 0;

        return [
            'summary' => [
                'total_loans' => $totalLoans,
                'delinquent_loans' => $delinquentLoans,
                'total_delinquent_loans' => $delinquentLoans,
                'average_days_overdue' => $delinquentLoans > 0 ? collect($delinquencyData)->avg('days_in_arrears') : 0,
                'current_loans' => $totalLoans - $delinquentLoans,
                'delinquency_rate' => $delinquencyRate,
                'total_outstanding' => $totalOutstanding,
                'total_delinquent_amount' => $totalDelinquentAmount,
                'delinquent_amount_rate' => $delinquentAmountRate,
                'delinquency_days_threshold' => $delinquencyDays,
            ],
            'buckets' => [
                '1-30' => $bucket1to30,
                '31-60' => $bucket31to60,
                '61-90' => $bucket61to90,
                '91-180' => $bucket91to180,
                '180+' => $bucket180plus,
            ],
            'loans' => $delinquencyData,
        ];
    }

    /**
     * Get Delinquency Bucket
     */
    private function getDelinquencyBucket($daysInArrears)
    {
        if ($daysInArrears >= 1 && $daysInArrears <= 30) {
            return '1-30 Days';
        } elseif ($daysInArrears >= 31 && $daysInArrears <= 60) {
            return '31-60 Days';
        } elseif ($daysInArrears >= 61 && $daysInArrears <= 90) {
            return '61-90 Days';
        } elseif ($daysInArrears >= 91 && $daysInArrears <= 180) {
            return '91-180 Days';
        } else {
            return '180+ Days';
        }
    }

    /**
     * Get Severity Level
     */
    private function getSeverityLevel($daysInArrears)
    {
        if ($daysInArrears >= 1 && $daysInArrears <= 15) {
            return 'Low';
        } elseif ($daysInArrears >= 16 && $daysInArrears <= 30) {
            return 'Medium';
        } elseif ($daysInArrears >= 31 && $daysInArrears <= 90) {
            return 'High';
        } else {
            return 'Critical';
        }
    }

    /**
     * Get Last Payment Date
     */
    private function getLastPaymentDate($loan)
    {
        $lastRepayment = $loan->schedule()
            ->whereHas('repayments')
            ->with('repayments')
            ->get()
            ->flatMap->repayments
            ->sortByDesc('payment_date')
            ->first();

        return $lastRepayment ? Carbon::parse($lastRepayment->payment_date)->format('Y-m-d') : 'N/A';
    }

    /**
     * Get Next Due Date
     */
    private function getNextDueDate($loan)
    {
        $nextSchedule = $loan->schedule()
            ->where('due_date', '>=', now())
            ->orderBy('due_date')
            ->first();

        return $nextSchedule ? Carbon::parse($nextSchedule->due_date)->format('Y-m-d') : 'N/A';
    }

        /**
     * Non Performing Loan Report - List NPLs with metrics and export options
     */
    public function nonPerformingLoanReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $groupId = $request->get('group_id');
        $loanOfficerId = $request->get('loan_officer_id');
        $exportType = $request->get('export_type');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $loanOfficers = User::excludeSuperAdmin()
            ->when($branchId, function ($query) use ($branchId) {
                if ($branchId !== 'all') {
                    $query->whereHas('branches', function ($q) use ($branchId) {
                        $q->where('branches.id', $branchId);
                    });
                }
            })
            ->get();
        $company = Company::first();
        $groups = Group::all();

        $showData = $request->has('as_of_date') || $request->has('branch_id') || $request->has('loan_officer_id') || $request->isMethod('get');
        $nplData = null;
        $nplSummary = [
            'total_npl_loans' => 0,
            'total_npl_amount' => 0,
            'average_dpd' => 0,
            'provision_total' => 0,
        ];
        if ($showData) {
            $nplData = $this->getNPLData($asOfDate, $branchId, $loanOfficerId, $groupId);
            if (count($nplData) > 0) {
                $nplSummary['total_npl_loans'] = count($nplData);
                $nplSummary['total_npl_amount'] = collect($nplData)->sum('outstanding');
                $nplSummary['average_dpd'] = round(collect($nplData)->avg('dpd'), 1);
                $nplSummary['provision_total'] = collect($nplData)->sum('provision_amount');
            }
            if ($exportType === 'excel') {
                return $this->exportNPLToExcel($request);
            } elseif ($exportType === 'pdf') {
                return $this->exportNPLToPdf($request);
            }
        }
        return view('loans.reports.npl_report', compact('nplData', 'nplSummary', 'branches', 'loanOfficers', 'company', 'asOfDate', 'branchId', 'loanOfficerId', 'showData','groups') );
    }

    /**
     * Query NPL data from database
     */
    private function getNPLData($asOfDate, $branchId = null, $loanOfficerId = null, $groupId = null)
    {
        $user = auth()->user();
        $company = $user->company;

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $query = Loan::with(['customer', 'branch','group','loanOfficer', 'collaterals', 'schedule.repayments'])
            ->where('status', 'active')
            ->whereDate('disbursed_on', '<=', $asOfDate)
            ->whereIn('branch_id', $assignedBranchIds);
        if ($branchId && $branchId !== 'all') {
            $query->where('branch_id', $branchId);
        }
        if ($loanOfficerId) {
            $query->where('loan_officer_id', $loanOfficerId);
        }
        if ($groupId) {
            $query->where('group_id', $groupId);
        }
        $loans = $query->get();
        $nplData = [];

        foreach ($loans as $loan) {
            $maxDpd = 0;
            $hasNplSchedule = false;
            $totalOutstanding = 0;
            $nplOutstanding = 0;

            foreach ($loan->schedule as $schedule) {
                // Calculate outstanding amount for this schedule
                $totalDue = $schedule->principal + $schedule->interest + ($schedule->fee_amount ?? 0);
                $totalPaid = $schedule->repayments->sum(function($repayment) {
                    return $repayment->principal + $repayment->interest + ($repayment->fee_amount ?? 0);
                });
                $outstanding = $totalDue - $totalPaid;
                $totalOutstanding += $outstanding;

                // Check if this schedule is overdue and has outstanding amount
                if ($schedule->due_date < $asOfDate && $outstanding > 0) {
                    $dpd = Carbon::parse($asOfDate)->diffInDays(Carbon::parse($schedule->due_date), false);
                    // Use absolute value since diffInDays returns negative for past dates
                    $dpd = abs($dpd);
                    if ($dpd > $maxDpd) {
                        $maxDpd = $dpd;
                    }
                    if ($dpd > 90) {
                        $hasNplSchedule = true;
                        $nplOutstanding += $outstanding;
                    }
                }
            }

            // Only include loans that have NPL schedules (overdue > 90 days with outstanding amounts)
            if ($hasNplSchedule && $nplOutstanding > 0) {
                $nplData[] = [
                    'date_of' => $asOfDate,
                    'branch' => $loan->branch->name ?? '',
                    'loan_officer' => $loan->loanOfficer->name ?? '',
                    'loan_id' => $loan->loanNo ?? $loan->id,
                    'borrower' => $loan->customer->name ?? '',
                    'outstanding' => $totalOutstanding, // Total outstanding for the loan
                    'npl_outstanding' => $nplOutstanding, // Only NPL portion
                    'dpd' => $maxDpd,
                    'classification' => $maxDpd > 360 ? 'Loss' : ($maxDpd > 180 ? 'Doubtful' : ($maxDpd > 90 ? 'Substandard' : 'Standard')),
                    'provision_percent' => $maxDpd > 360 ? '100%' : ($maxDpd > 180 ? '50%' : ($maxDpd > 90 ? '20%' : '0%')),
                    'provision_amount' => $nplOutstanding * ($maxDpd > 360 ? 1 : ($maxDpd > 180 ? 0.5 : ($maxDpd > 90 ? 0.2 : 0))),
                    'collateral' => $loan->collaterals->pluck('type')->implode(', '),
                    'status' => $loan->status ?? '',
                    'disbursed_date' => $loan->disbursed_on ? Carbon::parse($loan->disbursed_on)->format('d-m-Y') : 'N/A',
                    'last_payment_date' => $this->getLastPaymentDate($loan),
                ];
            }
        }
        return $nplData;
    }

    /**
     * Export NPL Report to Excel
     */
    public function exportNPLToExcel(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $groupId = $request->get('group_id');
        $loanOfficerId = $request->get('loan_officer_id');
        $nplData = $this->getNPLData($asOfDate, $branchId, $loanOfficerId);
        $filename = 'npl_report_' . $asOfDate . '.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\NPLExport($nplData, $asOfDate, $branchId, $loanOfficerId), $filename);
    }

    /**
     * Export NPL Report to PDF
     */
    public function exportNPLToPdf(Request $request)
    {
        $asOfDate = ($request->get('as_of_date') ?? now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');
        $loanOfficerId = $request->get('loan_officer_id');
        $groupId = $request->get('group_id');
        $nplData = $this->getNPLData($asOfDate, $branchId, $loanOfficerId, $groupId);
        $company = Company::first();
        $pdf = \PDF::loadView('loans.reports.npl_report_pdf', compact('nplData', 'asOfDate', 'branchId', 'loanOfficerId', 'company'));
        $pdf->setPaper('A3', 'landscape');
        $filename = 'npl_report_' . $asOfDate . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Export Loan Outstanding Balance Report to Excel
     */
    private function exportLoanOutstandingToExcel($outstandingData, $summary, $asOfDate, $branchId = null, $loanOfficerId = null)
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;

        return \Maatwebsite\Excel\Facades\Excel::download(new class($outstandingData, $summary, $asOfDate, $branch, $loanOfficer) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithTitle, \Maatwebsite\Excel\Concerns\WithStyles, \Maatwebsite\Excel\Concerns\ShouldAutoSize {
            private $outstandingData;
            private $summary;
            private $asOfDate;
            private $branch;
            private $loanOfficer;

            public function __construct($outstandingData, $summary, $asOfDate, $branch, $loanOfficer)
            {
                $this->outstandingData = collect($outstandingData);
                $this->summary = $summary;
                $this->asOfDate = $asOfDate;
                $this->branch = $branch;
                $this->loanOfficer = $loanOfficer;
            }

            public function collection()
            {
                $rows = $this->outstandingData->map(function ($row) {
                    return [
                        $row['customer'],
                        $row['customer_no'],
                        $row['phone'],
                        $row['loan_no'],
                        $row['expires'],
                        $row['branch'],
                        $row['loan_officer'],
                        $row['disbursed_date'],
                        $row['disbursed_amount'],
                        $row['total_interest'],
                        $row['total_principal_interest'],
                        $row['expected_fees'],
                        $row['total_penalties'],
                        $row['principal_paid'],
                        $row['interest_paid'],
                        $row['fees_paid'],
                        $row['penalty_paid'],
                        $row['outstanding_principal'],
                        $row['outstanding_interest'],
                        $row['outstanding_fees'],
                        $row['outstanding_penalty'],
                        $row['other_outstanding'],
                        $row['outstanding_balance'],
                    ];
                });

                if ($this->outstandingData->isNotEmpty()) {
                    $s = $this->summary;
                    $rows->push([
                        'TOTALS', '', '', '', '', '', '', '',
                        $s['total_disbursed'] ?? 0,
                        $s['total_interest'] ?? 0,
                        $s['total_principal_interest'] ?? 0,
                        $s['total_expected_fees'] ?? 0,
                        $s['total_penalties'] ?? 0,
                        $s['total_principal_paid'] ?? 0,
                        $s['total_interest_paid'] ?? 0,
                        $s['total_fees_paid'] ?? 0,
                        $s['total_penalty_paid'] ?? 0,
                        $s['total_outstanding_principal'] ?? 0,
                        $s['total_outstanding_interest'] ?? 0,
                        $s['total_outstanding_fees'] ?? 0,
                        $s['total_outstanding_penalty'] ?? 0,
                        0,
                        $s['total_outstanding_balance'] ?? 0,
                    ]);
                }

                return $rows;
            }

            public function headings(): array
            {
                return [
                    'Customer',
                    'Customer No',
                    'Phone',
                    'Loan No',
                    'Expires',
                    'Branch',
                    'Loan Officer',
                    'Disbursed Date',
                    'Disbursed Amount',
                    'Total Interest',
                    'Total Principal & Interest (P+I)',
                    'Expected Fees (Schedule)',
                    'Total penalties',
                    'Principal Paid',
                    'Interest Paid',
                    'Fees Paid',
                    'Penalty Paid',
                    'Outstanding Principal',
                    'Accrued/Outstanding Interest',
                    'Outstanding Fees',
                    'Outstanding Penalty',
                    'Other Outstanding',
                    'Outstanding Balance',
                ];
            }

            public function title(): string
            {
                return 'Loan Outstanding Balance Report';
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                $lastRow = (int) $sheet->getHighestRow();
                $styles = [
                    1 => ['font' => ['bold' => true]],
                ];
                if ($lastRow > 1) {
                    $styles[$lastRow] = [
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => [
                            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['rgb' => '343A40'],
                        ],
                    ];
                }

                return $styles;
            }
        }, 'loan_outstanding_balance_' . $asOfDate . '.xlsx');
    }

    /**
     * Export Loan Outstanding Balance Report to PDF
     */
    private function exportLoanOutstandingToPdf($outstandingData, $summary, $asOfDate, $branchId = null, $loanOfficerId = null)
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;
        $company = Company::first();

        $pdf = \PDF::loadView('loans.reports.loan_outstanding_pdf', compact('outstandingData', 'summary', 'asOfDate', 'branch', 'loanOfficer', 'company'));
        $pdf->setPaper('A3', 'landscape');
        $filename = 'loan_outstanding_balance_' . $asOfDate . '.pdf';
        return $pdf->download($filename);
    }

        /**
     * CRB (Credit Reference Bureau) Report
     */
    public function crbReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        // Get filter parameters
        $reportingDate = $request->input('reporting_date', Carbon::now()->toDateString());
        $branchId = $request->input('branch_id');
        $loanOfficerId = $request->input('loan_officer_id');

        // Get user's assigned branches
        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        // If user has exactly one branch, force-select it
        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        // Get user's assigned branch IDs for filtering
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        // Build query for loans
        $loansQuery = Loan::with(['customer', 'product', 'branch', 'loanOfficer', 'schedule', 'repayments', 'collaterals'])
            ->whereIn('branch_id', $assignedBranchIds)
            ->whereIn('status', ['active', 'disbursed', 'defaulted']);

        // Apply branch filter
        if ($branchId && $branchId !== 'all') {
            $loansQuery->where('branch_id', $branchId);
        }

        // Apply loan officer filter
        if ($loanOfficerId) {
            $loansQuery->where('loan_officer_id', $loanOfficerId);
        }

        $loans = $loansQuery->get();

        // Process CRB data
        $asOfDate = Carbon::parse($reportingDate)->endOfDay();
        $crbData = [];
        foreach ($loans as $loan) {
            // Build paid amounts per schedule from repayments (source of truth)
            $schedulePaidTotals = $loan->repayments
                ->filter(function ($repayment) use ($asOfDate) {
                    return $repayment->payment_date && Carbon::parse($repayment->payment_date)->lte($asOfDate);
                })
                ->groupBy('loan_schedule_id')
                ->map(function ($repayments) {
                    return (float) $repayments->sum(function ($repayment) {
                        return (float) ($repayment->principal ?? 0) + (float) ($repayment->interest ?? 0);
                    });
                });

            // Calculate number of installments and outstanding installments
            $totalInstallments = $loan->period;
            $scheduleItems = $loan->schedule;
            // Outstanding installments = installments that are not fully paid
            $outstandingInstallments = $scheduleItems->filter(function ($schedule) use ($schedulePaidTotals) {
                $expectedTotal = (float) (($schedule->principal ?? 0) + ($schedule->interest ?? 0));
                $paidTotal = (float) ($schedulePaidTotals->get($schedule->id, 0));
                return $paidTotal < $expectedTotal;
            })->count();

            // Calculate installment amount
            $installmentAmount = $totalInstallments > 0 ? ($loan->amount_total / $totalInstallments) : 0;

            // Repayments only up to reporting date
            $repaymentsUpToAsOf = $loan->repayments->filter(function ($repayment) use ($asOfDate) {
                return $repayment->payment_date && Carbon::parse($repayment->payment_date)->lte($asOfDate);
            });

            // Calculate outstanding amount (as of reporting date)
            $totalPaid = $repaymentsUpToAsOf->sum(function ($r) {
                return $r->principal + $r->interest;
            });
            $outstandingAmount = max(0, $loan->amount_total - $totalPaid);

            // Schedules that are due (up to reporting date) and not fully paid
            $dueSchedules = $scheduleItems->filter(function ($schedule) use ($asOfDate, $schedulePaidTotals) {
                $expectedTotal = (float) (($schedule->principal ?? 0) + ($schedule->interest ?? 0));
                $paidTotal = (float) ($schedulePaidTotals->get($schedule->id, 0));
                $isUnpaid = $paidTotal < $expectedTotal;
                $isDue = $schedule->due_date && Carbon::parse($schedule->due_date)->lte($asOfDate);
                return $isUnpaid && $isDue;
            });

            // Past due amount based on due unpaid schedules
            $pastDueAmount = $dueSchedules->sum(function ($schedule) use ($schedulePaidTotals) {
                $expectedTotal = (float) (($schedule->principal ?? 0) + ($schedule->interest ?? 0));
                $paidTotal = (float) ($schedulePaidTotals->get($schedule->id, 0));
                return max(0, $expectedTotal - $paidTotal);
            });

            // Calculate past due days from oldest due unpaid installment
            $pastDueDays = 0;
            if ($dueSchedules->count() > 0) {
                $oldestDueDate = $dueSchedules->min('due_date');
                if ($oldestDueDate) {
                    $pastDueDays = Carbon::parse($oldestDueDate)->startOfDay()->diffInDays($asOfDate->copy()->startOfDay());
                }
            }

            // Date of last payment up to reporting date
            $lastPayment = $repaymentsUpToAsOf->sortByDesc('payment_date')->first();
            $dateOfLastPayment = $lastPayment ? $lastPayment->payment_date : null;

            // Total monthly payment for reporting month (up to reporting date)
            $totalMonthlyPayment = 0;
            if ($repaymentsUpToAsOf->count() > 0) {
                $currentMonthPayments = $repaymentsUpToAsOf->filter(function ($repayment) use ($asOfDate) {
                    return $repayment->payment_date && 
                           Carbon::parse($repayment->payment_date)->format('Y-m') === $asOfDate->format('Y-m');
                });
                $totalMonthlyPayment = $currentMonthPayments->sum(function ($r) {
                    return $r->principal + $r->interest;
                });
            }

            // Payment Periodicity
            $paymentPeriodicity = 'Monthly'; // Default
            if ($loan->product && $loan->product->repayment_cycle) {
                $repaymentCycle = $loan->product->repayment_cycle;
                $paymentPeriodicity = ucfirst($repaymentCycle);
            }

            // Start date (disbursed on)
            $startDate = $loan->disbursed_on;

            // End Date (expected last repayment date)
            $endDate = $loan->last_repayment_date;

            // Real end date: actual completion date if fully paid by reporting date
            $realEndDate = $outstandingAmount <= 0 ? $dateOfLastPayment : null;

            // Number of due installments (unpaid installments due up to reporting date)
            $numberOfDueInstallments = $dueSchedules->count();

            // Collateral information (from loan_collaterals table)
            $collateralType = 'N/A';
            $collateralValue = 0;
            if ($loan->collaterals && $loan->collaterals->count() > 0) {
                $collateralTypes = $loan->collaterals->pluck('type')->filter()->unique()->toArray();
                $collateralType = implode(', ', $collateralTypes) ?: 'N/A';
                // Prefer appraised value; fallback to estimated value when appraised is not set.
                $collateralValue = $loan->collaterals->sum(function ($collateral) {
                    return (float) ($collateral->appraised_value ?? $collateral->estimated_value ?? 0);
                });
            }

            $crbData[] = [
                'reporting_date' => $reportingDate,
                'fullname' => $loan->customer->name ?? 'N/A',
                'contract_code' => $loan->loanNo ?? $loan->id,
                'customer_code' => $loan->customer->customerNo ?? $loan->customer->id,
                'branch' => $loan->branch->name ?? 'N/A',
                'loan_status' => ucfirst($loan->status),
                'type_of_contract' => 'Installment',
                'loan_purpose' => $loan->sector ?? 'N/A',
                'interest_rate' => number_format($loan->interest ?? 0, 2),
                'total_loan' => $loan->amount_total,
                'total_loan_taken' => $loan->amount,
                'installment_amount' => $installmentAmount,
                'number_of_installments' => $totalInstallments,
                'number_of_outstanding_installments' => $outstandingInstallments,
                'outstanding_amount' => $outstandingAmount,
                'past_due_amount' => $pastDueAmount,
                'past_due_days' => $pastDueDays,
                'number_of_due_installments' => $numberOfDueInstallments,
                'date_of_last_payment' => $dateOfLastPayment,
                'total_monthly_payment' => $totalMonthlyPayment,
                'payment_periodicity' => $paymentPeriodicity,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'real_end_date' => $realEndDate,
                'collateral_type' => $collateralType,
                'collateral_value' => $collateralValue,
                'role_of_customer' => 'Main Debtor',
                'currency' => 'TZS',
            ];
        }

        // Calculate summary
        $summary = [
            'total_loans' => count($crbData),
            'total_loan_amount' => collect($crbData)->sum('total_loan'),
            'total_outstanding' => collect($crbData)->sum('outstanding_amount'),
            'total_past_due' => collect($crbData)->sum('past_due_amount'),
        ];

        // Get loan officers for filter
        $loanOfficers = User::whereHas('roles', function ($q) {
            $q->where('name', 'Loan Officer');
        })->get();

        // Handle export
        if ($request->has('export')) {
            $exportType = $request->input('export');
            if ($exportType === 'excel') {
                return $this->exportCrbToExcel($crbData, $summary, $reportingDate, $branchId, $loanOfficerId);
            } elseif ($exportType === 'pdf') {
                return $this->exportCrbToPdf($crbData, $summary, $reportingDate, $branchId, $loanOfficerId);
            }
        }

        return view('loans.reports.crb_report', compact('crbData', 'summary', 'reportingDate', 'branches', 'loanOfficers', 'branchId', 'loanOfficerId'));
    }

    /**
     * Export CRB Report to Excel
     */
    private function exportCrbToExcel($crbData, $summary, $reportingDate, $branchId = null, $loanOfficerId = null)
    {
        return Excel::download(new class($crbData, $summary, $reportingDate, $branchId, $loanOfficerId) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithTitle, \Maatwebsite\Excel\Concerns\WithStyles, \Maatwebsite\Excel\Concerns\ShouldAutoSize {
            private $crbData;
            private $summary;
            private $reportingDate;
            private $branchId;
            private $loanOfficerId;

            public function __construct($crbData, $summary, $reportingDate, $branchId, $loanOfficerId)
            {
                $this->crbData = $crbData;
                $this->summary = $summary;
                $this->reportingDate = $reportingDate;
                $this->branchId = $branchId;
                $this->loanOfficerId = $loanOfficerId;
            }

            public function collection()
            {
                return collect($this->crbData)->map(function ($item) {
                    return [
                        $item['reporting_date'],
                        $item['fullname'],
                        $item['contract_code'],
                        $item['customer_code'],
                        $item['branch'],
                        $item['loan_status'],
                        $item['type_of_contract'],
                        $item['loan_purpose'],
                        $item['interest_rate'] . '%',
                        number_format($item['total_loan'], 2),
                        number_format($item['total_loan_taken'], 2),
                        number_format($item['installment_amount'], 2),
                        $item['number_of_installments'],
                        $item['number_of_outstanding_installments'],
                        number_format($item['outstanding_amount'], 2),
                        number_format($item['past_due_amount'], 2),
                        $item['past_due_days'],
                        $item['number_of_due_installments'],
                        $item['date_of_last_payment'] ? \Carbon\Carbon::parse($item['date_of_last_payment'])->format('Y-m-d') : 'N/A',
                        number_format($item['total_monthly_payment'], 2),
                        $item['payment_periodicity'],
                        $item['start_date'] ? \Carbon\Carbon::parse($item['start_date'])->format('Y-m-d') : 'N/A',
                        $item['end_date'] ? \Carbon\Carbon::parse($item['end_date'])->format('Y-m-d') : 'N/A',
                        $item['real_end_date'] ? \Carbon\Carbon::parse($item['real_end_date'])->format('Y-m-d') : 'N/A',
                        $item['collateral_type'],
                        number_format($item['collateral_value'], 2),
                        $item['role_of_customer'],
                        $item['currency'],
                    ];
                });
            }

            public function headings(): array
            {
                return [
                    'Reporting Date',
                    'Full Name',
                    'Contract Code',
                    'Customer Code',
                    'Branch',
                    'Loan Status',
                    'Type of Contract',
                    'Loan Purpose',
                    'Interest Rate',
                    'Total Loan',
                    'Total Loan Taken',
                    'Installment Amount',
                    'Number of Installments',
                    'Number of Outstanding Installments',
                    'Outstanding Amount',
                    'Past Due Amount',
                    'Past Due Days',
                    'Number of Due Installments',
                    'Date of Last Payment',
                    'Total Monthly Payment',
                    'Payment Periodicity',
                    'Start Date',
                    'End Date',
                    'Real End Date',
                    'Collateral Type',
                    'Collateral Value',
                    'Role of Customer',
                    'Currency',
                ];
            }

            public function title(): string
            {
                return 'CRB Report';
            }

            public function styles(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet)
            {
                // Get the highest row and column
                $highestRow = $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                
                // Style header row (row 1) - blue background, bold, white text
                $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '4472C4'],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);
                
                // Apply borders to all data cells
                if ($highestRow > 1) {
                    $sheet->getStyle('A2:' . $highestColumn . $highestRow)->applyFromArray([
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                'color' => ['rgb' => '000000'],
                            ],
                        ],
                    ]);
                }
                
                return [];
            }
        }, 'crb_report_' . $reportingDate . '.xlsx');
    }

    /**
     * Export CRB Report to PDF
     */
    private function exportCrbToPdf($crbData, $summary, $reportingDate, $branchId = null, $loanOfficerId = null)
    {
        $branch = $branchId ? Branch::find($branchId) : null;
        $loanOfficer = $loanOfficerId ? User::find($loanOfficerId) : null;
        $company = Company::first();

        $pdf = PDF::loadView('loans.reports.crb_report_pdf', array_merge(compact('crbData', 'summary', 'reportingDate', 'branch', 'loanOfficer', 'company')));
        $pdf->setPaper('A4', 'landscape');
        $filename = 'crb_report_' . $reportingDate . '.pdf';
        return $pdf->download($filename);
    }
    /**
     * Group repayment schedule card — members with loans and instalments per due date in range.
     */
    public function groupRepaymentScheduleReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $startDate = $request->input('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', Carbon::now()->toDateString());
        $groupId = $request->input('group_id');
        $branchId = $request->input('branch_id');

        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $company->id)
            ->pluck('branches.id')
            ->toArray();

        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $groups = Group::query()
            ->where('id', '!=', Group::getIndividualGroupId())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! empty($assignedBranchIds), fn ($q) => $q->whereIn('branch_id', $assignedBranchIds))
            ->orderBy('name')
            ->get();

        $showData = $request->filled('group_id');
        $reportData = $showData
            ? \App\Support\Loans\GroupRepaymentScheduleCardBuilder::build((int) $groupId, $startDate, $endDate)
            : ['group' => null, 'schedule_dates' => [], 'date_keys' => [], 'rows' => [], 'column_totals' => []];

        $company = Company::first();

        return view('loans.reports.group_repayment_schedule', compact(
            'branches', 'groups', 'startDate', 'endDate', 'groupId', 'branchId',
            'showData', 'reportData', 'company'
        ));
    }

    /**
     * Export group repayment schedule card to PDF.
     */
    public function exportGroupRepaymentScheduleToPdf(Request $request)
    {
        $request->validate([
            'group_id' => 'required|exists:groups,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $groupId = (int) $request->input('group_id');

        $reportData = \App\Support\Loans\GroupRepaymentScheduleCardBuilder::build($groupId, $startDate, $endDate);
        $company = Company::first();

        $pdf = PDF::loadView('loans.reports.group_repayment_schedule_pdf', compact(
            'reportData', 'company', 'startDate', 'endDate'
        ));
        $this->configureLoanReportPdf($pdf, 'A3', 'landscape');

        $groupName = $reportData['group']->name ?? 'group';
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $groupName);

        return $pdf->download('group_repayment_schedule_' . $safeName . '_' . $startDate . '_to_' . $endDate . '.pdf');
    }

    public function customerLoanStatementReport(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $asOfDate = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $branchId = $request->get('branch_id') ?: null;
        $loanId = $request->get('loan_id') ?: null;

        $branches = $user->branches()
            ->where('branches.company_id', $company->id)
            ->select('branches.id', 'branches.name')
            ->get();

        if (($branches->count() ?? 0) === 1) {
            $branchId = $branches->first()->id;
        }

        $assignedBranchIds = $branches->pluck('id')->toArray();
        $loans = $this->getCustomerStatementLoanOptions($assignedBranchIds, $branchId);
        $showData = $request->filled('loan_id');
        $reportData = null;

        if ($showData) {
            $loan = $this->findCustomerStatementLoan((int) $loanId, $assignedBranchIds);
            if ($loan) {
                $reportData = CustomerLoanStatementBuilder::build($loan, $asOfDate);
            }
        }

        return view('loans.reports.customer_loan_statement', compact(
            'reportData', 'branches', 'loans', 'asOfDate', 'branchId', 'loanId', 'showData'
        ));
    }

    public function exportCustomerLoanStatementToExcel(Request $request)
    {
        $loan = $this->resolveCustomerStatementLoan($request);
        $asOfDate = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $reportData = CustomerLoanStatementBuilder::build($loan, $asOfDate);

        $filename = 'customer_loan_statement_' . ($loan->loanNo ?? $loan->id) . '_' . $asOfDate . '.xlsx';

        return Excel::download(new CustomerLoanStatementExport($reportData), $filename);
    }

    public function exportCustomerLoanStatementToPdf(Request $request)
    {
        $loan = $this->resolveCustomerStatementLoan($request);
        $asOfDate = $request->get('as_of_date') ?? now()->format('Y-m-d');
        $reportData = CustomerLoanStatementBuilder::build($loan, $asOfDate);

        $company = Company::first();
        $branch = $loan->branch;
        $customer = $loan->customer;
        $user = auth()->user();
        if ($user) {
            $user->load('roles');
        }

        $pdf = PDF::loadView('loans.reports.customer_loan_statement_pdf', compact(
            'reportData', 'loan', 'company', 'branch', 'customer', 'asOfDate', 'user'
        ));
        $this->configureLoanReportPdf($pdf, 'A4', 'landscape');

        $filename = 'customer_loan_statement_' . ($loan->loanNo ?? $loan->id) . '_' . $asOfDate . '.pdf';

        return $pdf->download($filename);
    }

    private function getCustomerStatementLoanOptions(array $assignedBranchIds, ?int $branchId)
    {
        return Loan::query()
            ->with('customer:id,name')
            ->whereIn('status', [
                Loan::STATUS_ACTIVE,
                Loan::STATUS_COMPLETE,
                Loan::STATUS_DEFAULTED,
            ])
            ->when(! empty($assignedBranchIds), fn ($q) => $q->whereIn('branch_id', $assignedBranchIds))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('disbursed_on')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'loanNo', 'amount', 'customer_id', 'branch_id', 'disbursed_on']);
    }

    private function findCustomerStatementLoan(int $loanId, array $assignedBranchIds): ?Loan
    {
        return Loan::query()
            ->with(LoanReportMetrics::eagerLoads())
            ->where('id', $loanId)
            ->when(! empty($assignedBranchIds), fn ($q) => $q->whereIn('branch_id', $assignedBranchIds))
            ->first();
    }

    private function resolveCustomerStatementLoan(Request $request): Loan
    {
        $user = auth()->user();
        $assignedBranchIds = $user->branches()
            ->where('branches.company_id', $user->company->id)
            ->pluck('branches.id')
            ->toArray();

        $loanId = (int) $request->get('loan_id');
        abort_unless($loanId > 0, 422, 'Loan is required.');

        $loan = $this->findCustomerStatementLoan($loanId, $assignedBranchIds);
        abort_unless($loan, 404, 'Loan not found or not accessible.');

        return $loan;
    }

    private function configureLoanReportPdf($pdf, $paper = 'A3', $orientation = 'landscape')
    {
        $pdf->setPaper($paper, $orientation);
        $pdf->setOptions($this->loanReportPdfDomOptions());

        return $pdf;
    }

    private function loanReportPdfDomOptions(): array
    {
        return [
            'margin-left' => 10,
            'margin-right' => 10,
            'margin-top' => 10,
            'margin-bottom' => 10,
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
        ];
    }
}
