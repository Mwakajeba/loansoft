<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Fee;
use App\Models\ContributionAccount;
use App\Models\ContributionProduct;
use App\Models\ShareAccount;
use App\Models\ShareDeposit;
use App\Models\GlTransaction;
use App\Models\Journal;
use App\Models\CashCollateral;
use App\Models\Filetype;
use App\Models\LoanFile;
use App\Models\Receipt;
use App\Models\Company;
use App\Models\Announcement;
use App\Support\InterestRateConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CustomerAuthController extends Controller
{
    /**
     * Get all filetypes (used for KYC / loan documents).
     */
    public function filetypes(Request $request)
    {
        try {
            $filetypes = Filetype::orderBy('name', 'asc')->get()->map(function ($ft) {
                return [
                    'id' => $ft->id,
                    'name' => $ft->name,
                ];
            })->values(); // Ensure it's a proper array, not a keyed collection

            Log::info('Filetypes API called', [
                'count' => $filetypes->count(),
                'filetypes' => $filetypes->toArray()
            ]);

            return response()->json([
                'status' => 200,
                'filetypes' => $filetypes->toArray(), // Explicitly convert to array
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching filetypes: ' . $e->getMessage());
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List loan documents for a given loan (customer can only access own loans).
     */
    public function loanDocuments(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'loan_id' => 'required|integer|exists:loans,id',
            ]);

            $customerId = (int) $request->input('customer_id');
            $loanId = (int) $request->input('loan_id');

            $loan = Loan::where('id', $loanId)->where('customer_id', $customerId)->first();
            if (!$loan) {
                return response()->json([
                    'status' => 403,
                    'message' => 'Unauthorized loan access',
                ], 403);
            }

            $disk = config('upload.storage_disk', 'public');
            $docs = LoanFile::with('fileType')
                ->where('loan_id', $loanId)
                ->latest()
                ->get()
                ->map(function ($lf) use ($disk) {
                    return [
                        'id' => $lf->id,
                        'file_type_id' => $lf->file_type_id,
                        'file_type' => $lf->fileType?->name,
                        'status' => $lf->status ?? 'pending',
                        'reviewed_at' => optional($lf->reviewed_at)->toDateTimeString(),
                        'review_notes' => $lf->review_notes,
                        'file_path' => $lf->file_path,
                        'url' => $lf->file_path ? Storage::disk($disk)->url($lf->file_path) : null,
                        'created_at' => optional($lf->created_at)->toDateTimeString(),
                    ];
                });

            return response()->json([
                'status' => 200,
                'documents' => $docs,
                'total' => $docs->count(),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a single loan document (KYC) for a given loan.
     * Mobile requirement: PDF only.
     */
    public function uploadLoanDocument(Request $request)
    {
        try {
            $maxFileSize = (int) config('upload.max_file_size', 5120); // KB

            $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'loan_id' => 'required|integer|exists:loans,id',
                'file_type_id' => 'required|integer|exists:filetypes,id',
                'file' => 'required|file|max:' . $maxFileSize . '|mimes:pdf',
            ]);

            $customerId = (int) $request->input('customer_id');
            $loanId = (int) $request->input('loan_id');

            $loan = Loan::where('id', $loanId)->where('customer_id', $customerId)
                ->with('product.filetypes')
                ->first();
            if (!$loan) {
                return response()->json([
                    'status' => 403,
                    'message' => 'Unauthorized loan access',
                ], 403);
            }

            // Ensure uploaded document type is required by this product (KYC config)
            $requiredFiletypeIds = ($loan->product?->filetypes ?? collect())->pluck('id')->map(fn($v) => (int) $v)->values()->all();
            $fileTypeId = (int) $request->input('file_type_id');
            if (!empty($requiredFiletypeIds) && !in_array($fileTypeId, $requiredFiletypeIds, true)) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Selected document type is not required for this loan product.',
                    'errors' => [
                        'file_type_id' => ['Selected document type is not required for this loan product.'],
                    ],
                ], 422);
            }

            $disk = config('upload.storage_disk', 'public');
            $path = config('upload.storage_path', 'loan_documents');

            $uploaded = $request->file('file');
            $filePath = $uploaded->store($path, $disk);

            $loanFile = LoanFile::create([
                'loan_id' => $loanId,
                'file_type_id' => $fileTypeId,
                'file_path' => $filePath,
                'status' => 'pending',
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Document uploaded successfully',
                'document' => [
                    'id' => $loanFile->id,
                    'file_type_id' => $loanFile->file_type_id,
                    'file_path' => $loanFile->file_path,
                    'status' => $loanFile->status ?? 'pending',
                    'url' => Storage::disk($disk)->url($loanFile->file_path),
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Customer login API
     */
    public function login(Request $request)
    {
        try {
            // Validate input
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            // Normalize phone: digits only, then to 9-digit form (723456789)
            $digits = preg_replace('/\D/', '', trim((string) $request->username));
            $digits = ltrim($digits, '0');
            if (strlen($digits) > 9 && str_starts_with($digits, '255')) {
                $digits = substr($digits, 3);
            }
            $normalized = $digits;

            if (strlen($normalized) < 9) {
                return response()->json([
                    'message' => 'User Does Not Exist',
                    'status' => 401,
                ], 401);
            }

            // All forms that might be stored in DB (723456789, 0723456789, 255723456789)
            $variants = [$normalized];
            if (strlen($normalized) === 9) {
                $variants[] = '0' . $normalized;
                $variants[] = '255' . $normalized;
            }

            // Fetch customer by phone (exact or TRIM(phone1) in case DB has spaces)
            $customer = Customer::where(function ($q) use ($variants) {
                $q->whereIn('phone1', $variants)
                    ->orWhereRaw('TRIM(phone1) IN (' . implode(',', array_fill(0, count($variants), '?')) . ')', $variants);
            })->first();

            if (!$customer) {
                return response()->json([
                    'message' => 'User Does Not Exist',
                    'status' => 401
                ], 401);
            }

            // Verify password
            if (!Hash::check($request->password, $customer->password)) {
                return response()->json([
                    'message' => 'Invalid credentials',
                    'status' => 401
                ], 401);
            }

            // Get customer's group
            $groupMembership = DB::table('group_members')
                ->where('customer_id', $customer->id)
                ->first();

            $groupId = $groupMembership->group_id ?? null;
            $group = null;

            if ($groupId) {
                $group = DB::table('groups')->where('id', $groupId)->first();
            }

            // Get customer's loans with repayments
            $customerLoans = $this->getLoansWithRepayments($customer->id);

            // Calculate total balance for active loans only (exclude applied)
            $activeLoans = collect($customerLoans)->where('status', 'active');
            $totalLoanBalance = $activeLoans->sum('total_due');
            $totalLoanAmount = collect($customerLoans)->sum('total_amount');
            $totalRepaid = collect($customerLoans)->sum('total_repaid');

            // Next due: soonest future due date, or most recent overdue (for dashboard "days" and amount display)
            // Only consider active loans (exclude applied)
            $nextDueDays = null;
            $nextDueAmount = null;
            $today = now()->startOfDay();
            $futureCandidates = []; // [days => amount]
            $overdueCandidates = [];
            foreach ($activeLoans as $loan) {
                $next = $loan['next_schedule'] ?? null;
                if (!$next || empty($next['due_date'])) {
                    continue;
                }
                $amount = (float) ($next['total_due'] ?? $next['amount'] ?? 0);
                try {
                    $due = \Carbon\Carbon::parse($next['due_date'])->startOfDay();
                    $days = (int) $today->diffInDays($due, false); // positive = future, negative = overdue
                    if ($days >= 0) {
                        $futureCandidates[] = ['days' => $days, 'amount' => $amount];
                    } else {
                        $overdueCandidates[] = ['days' => $days, 'amount' => $amount];
                    }
                } catch (\Throwable $e) {
                    // ignore parse errors
                }
            }
            if (!empty($futureCandidates)) {
                $soonest = collect($futureCandidates)->sortBy('days')->first();
                $nextDueDays = $soonest['days'];
                $nextDueAmount = $soonest['amount'];
            } elseif (!empty($overdueCandidates)) {
                $mostRecent = collect($overdueCandidates)->sortByDesc('days')->first();
                $nextDueDays = $mostRecent['days'];
                $nextDueAmount = $mostRecent['amount'];
            }

            // Get group members and their loans
            $members = [];
            if ($groupId) {
                $groupMembers = DB::table('group_members')
                    ->join('customers', 'group_members.customer_id', '=', 'customers.id')
                    ->where('group_members.group_id', $groupId)
                    ->select('customers.*')
                    ->orderBy('customers.name', 'asc')
                    ->get();

                foreach ($groupMembers as $member) {
                    $members[] = [
                        'id' => $member->id,
                        'name' => $member->name,
                        'phone1' => $member->phone1,
                        'phone2' => $member->phone2,
                        'sex' => $member->sex,
                        'picture' => $member->photo ? asset('storage/' . $member->photo) : null,
                        'loans' => $this->getLoansWithRepayments($member->id),
                    ];
                }
            }

            // Return successful response (include photo so app shows it after login)
            return response()->json([
                'message' => 'Login successful',
                'status' => 200,
                'user_id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone1,
                'photo' => $customer->photo ? asset('storage/' . $customer->photo) : null,
                'branch' => $customer->branch->name ?? '',
                'group_id' => $groupId,
                'group_name' => $group->name ?? '',
                'email' => '',
                'memberno' => $customer->customerNo,
                'gender' => $customer->sex,
                'role' => 'customer',
                'loans' => $customerLoans,
                'total_loan_balance' => $totalLoanBalance,
                'total_loan_amount' => $totalLoanAmount,
                'total_repaid' => $totalRepaid,
                'loans_count' => count($customerLoans),
                'next_due_days' => $nextDueDays,
                'next_due_amount' => $nextDueAmount,
                'members' => $members,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer profile
     */
    public function profile(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            $customer = Customer::with(['branch', 'region', 'district'])->find($customerId);

            if (!$customer) {
                return response()->json([
                    'message' => 'Customer not found',
                    'status' => 404
                ], 404);
            }

            // Get customer's group
            $groupMembership = DB::table('group_members')
                ->where('customer_id', $customer->id)
                ->first();

            $groupId = $groupMembership->group_id ?? null;
            $group = null;

            if ($groupId) {
                $group = DB::table('groups')->where('id', $groupId)->first();
            }

            return response()->json([
                'status' => 200,
                'customer' => [
                    'id' => $customer->id,
                    'customerNo' => $customer->customerNo,
                    'name' => $customer->name,
                    'description' => $customer->description,
                    'phone1' => $customer->phone1,
                    'phone2' => $customer->phone2,
                    'work' => $customer->work,
                    'workAddress' => $customer->workAddress,
                    'idType' => $customer->idType,
                    'idNumber' => $customer->idNumber,
                    'dob' => $customer->dob,
                    'sex' => $customer->sex,
                    'category' => $customer->category,
                    'dateRegistered' => $customer->dateRegistered,
                    'photo' => $customer->photo ? asset('storage/' . $customer->photo) : null,
                    'branch' => $customer->branch->name ?? '',
                    'region' => $customer->region->name ?? '',
                    'district' => $customer->district->name ?? '',
                    'group_id' => $groupId,
                    'group_name' => $group->name ?? '',
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer loans
     */
    public function loans(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            $loans = $this->getLoansWithRepayments($customerId);

            // Calculate totals - only active loans for balance (exclude applied)
            $activeLoans = collect($loans)->where('status', 'active');
            $totalLoanBalance = $activeLoans->sum('total_due');
            $totalLoanAmount = collect($loans)->sum('total_amount');
            $totalRepaid = collect($loans)->sum('total_repaid');

            return response()->json([
                'status' => 200,
                'loans' => $loans,
                'total_loan_balance' => $totalLoanBalance,
                'total_loan_amount' => $totalLoanAmount,
                'total_repaid' => $totalRepaid,
                'loans_count' => count($loans),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single loan detail with full schedules and repayments (for loan details / Marejesho tab).
     */
    public function loanDetail(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'loan_id' => 'required|integer|exists:loans,id',
            ]);
            $customerId = (int) $request->input('customer_id');
            $loanId = (int) $request->input('loan_id');

            $loan = Loan::where('id', $loanId)->where('customer_id', $customerId)
                ->with(['product.filetypes', 'loanOfficer', 'schedule', 'topUpLoan'])
                ->first();

            if (!$loan) {
                return response()->json([
                    'message' => 'Loan not found or access denied',
                    'status' => 404,
                ], 404);
            }

            // Get original loan if this loan was restructured from another loan
            $originalLoan = null;
            if ($loan->top_up_id) {
                $originalLoan = Loan::where('id', $loan->top_up_id)
                    ->where('customer_id', $customerId)
                    ->first();
            }

            // All schedules for this loan (loan_schedules)
            $schedules = DB::table('loan_schedules')
                ->where('loan_id', $loan->id)
                ->orderBy('due_date', 'asc')
                ->get();

            $scheduleList = [];
            $totalDue = 0;
            $totalPaid = 0;
            foreach ($schedules as $schedule) {
                $principal = (float) ($schedule->principal ?? 0);
                $interest = (float) ($schedule->interest ?? 0);
                $feeAmount = (float) ($schedule->fee_amount ?? 0);
                $penaltyAmount = (float) ($schedule->penalty_amount ?? 0);
                $totalDueForSchedule = $principal + $interest + $feeAmount + $penaltyAmount;
                $totalDue += $totalDueForSchedule;

                $paidAmount = (float) DB::table('repayments')
                    ->where('loan_schedule_id', $schedule->id)
                    ->sum(DB::raw('COALESCE(principal, 0) + COALESCE(interest, 0) + COALESCE(penalt_amount, 0) + COALESCE(fee_amount, 0)'));
                $totalPaid += $paidAmount;
                $remaining = max(0, $totalDueForSchedule - $paidAmount);

                $isPaid = $remaining < 0.01;
                $isPending = !$isPaid && $paidAmount > 0;
                $isUpcoming = !$isPaid && $paidAmount <= 0;

                $scheduleList[] = [
                    'id' => $schedule->id,
                    'due_date' => $schedule->due_date,
                    'principal' => $principal,
                    'interest' => $interest,
                    'fee_amount' => $feeAmount,
                    'penalty_amount' => $penaltyAmount,
                    'total_due' => round($totalDueForSchedule, 2),
                    'paid_amount' => round($paidAmount, 2),
                    'remaining' => round($remaining, 2),
                    'status' => $isPaid ? 'paid' : ($isPending ? 'pending' : 'upcoming'),
                ];
            }

            // All repayments for this loan
            $repayments = DB::table('repayments')
                ->where('loan_id', $loan->id)
                ->orderBy('payment_date', 'asc')
                ->get()
                ->map(function ($r) {
                    $amt = ($r->principal ?? 0) + ($r->interest ?? 0) + ($r->penalt_amount ?? 0) + ($r->fee_amount ?? 0);
                    return [
                        'id' => $r->id,
                        'amount' => round($amt, 2),
                        'payment_date' => $r->payment_date,
                        'due_date' => $r->due_date,
                        'principal' => $r->principal ?? 0,
                        'interest' => $r->interest ?? 0,
                        'penalty' => $r->penalt_amount ?? 0,
                        'fee' => $r->fee_amount ?? 0,
                    ];
                });

            $totalRepaid = $repayments->sum('amount');
            $loanTotal = (float) ($loan->amount_total ?? 0);
            $progressPercent = $loanTotal > 0 ? min(100, round(($totalRepaid / $loanTotal) * 100, 1)) : 0;

            // KYC requirements for this product + uploaded documents for this loan
            $kycRequired = ($loan->product?->filetypes ?? collect())->map(function ($ft) {
                return [
                    'id' => $ft->id,
                    'name' => $ft->name,
                    'description' => $ft->description,
                ];
            })->values();

            $disk = config('upload.storage_disk', 'public');
            $kycDocuments = LoanFile::with('fileType')
                ->where('loan_id', $loan->id)
                ->latest()
                ->get()
                ->map(function ($lf) use ($disk) {
                    return [
                        'id' => $lf->id,
                        'file_type_id' => $lf->file_type_id,
                        'file_type' => $lf->fileType?->name,
                        'status' => $lf->status ?? 'pending',
                        'reviewed_at' => optional($lf->reviewed_at)->toDateTimeString(),
                        'review_notes' => $lf->review_notes,
                        'url' => $lf->file_path ? Storage::disk($disk)->url($lf->file_path) : null,
                        'created_at' => optional($lf->created_at)->toDateTimeString(),
                    ];
                })->values();

            // Calculate original loan totals if it exists
            $originalLoanData = null;
            if ($originalLoan) {
                $originalRepayments = DB::table('repayments')
                    ->where('loan_id', $originalLoan->id)
                    ->get()
                    ->map(function ($r) {
                        return ($r->principal ?? 0) + ($r->interest ?? 0) + ($r->penalt_amount ?? 0) + ($r->fee_amount ?? 0);
                    });
                $originalTotalRepaid = $originalRepayments->sum();
                $originalLoanTotal = (float) ($originalLoan->amount_total ?? 0);
                $originalTotalDue = round(max(0, $originalLoanTotal - $originalTotalRepaid), 2);

                $originalLoanData = [
                    'loanid' => $originalLoan->id,
                    'loan_no' => $originalLoan->loanNo,
                    'amount' => $originalLoan->amount,
                    'total_amount' => $originalLoanTotal,
                    'total_due' => $originalTotalDue,
                    'status' => $originalLoan->status,
                ];
            }

            return response()->json([
                'status' => 200,
                'loan' => [
                    'loanid' => $loan->id,
                    'loan_no' => $loan->loanNo,
                    'product_id' => $loan->product_id,
                    'amount' => $loan->amount,
                    'interest' => $loan->interest,
                    'interest_amount' => $loan->interest_amount,
                    'total_amount' => $loanTotal,
                    'period' => $loan->period,
                    'interest_cycle' => $loan->interest_cycle ?? 'monthly',
                    'disbursed_on' => $loan->disbursed_on,
                    'last_repayment_date' => $loan->last_repayment_date,
                    'status' => $loan->status,
                    'product_name' => $loan->product->name ?? '',
                    'kyc_required' => $kycRequired,
                    'kyc_documents' => $kycDocuments,
                    'schedules' => $scheduleList,
                    'repayments' => $repayments,
                    'total_repaid' => round($totalRepaid, 2),
                    'total_due' => round(max(0, $loanTotal - $totalRepaid), 2),
                    'progress_percent' => $progressPercent,
                    'original_loan' => $originalLoanData,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'status' => 422,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active announcements for the mobile dashboard (Matangazo).
     */
    public function announcements(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            $customer = Customer::with('branch.company')->find($customerId);
            if (!$customer || !$customer->branch || !$customer->branch->company) {
                return response()->json([
                    'status' => 200,
                    'announcements' => [],
                ], 200);
            }

            $companyId = $customer->branch->company->id;
            $today = now()->toDateString();

            $disk = config('upload.storage_disk', 'public');

            $announcements = Announcement::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereDate('publish_date', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $today);
                })
                ->orderBy('publish_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function (Announcement $a) use ($disk) {
                    return [
                        'id' => $a->id,
                        'title' => $a->title,
                        'description' => $a->description,
                        'image_url' => $a->image_path ? Storage::disk($disk)->url($a->image_path) : null,
                        'publish_date' => optional($a->publish_date)->toDateString(),
                        'end_date' => optional($a->end_date)->toDateString(),
                    ];
                })->values();

            return response()->json([
                'status' => 200,
                'announcements' => $announcements,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get group members
     */
    public function groupMembers(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            // Get customer's group
            $groupMembership = DB::table('group_members')
                ->where('customer_id', $customerId)
                ->first();

            if (!$groupMembership) {
                return response()->json([
                    'status' => 200,
                    'members' => [],
                ], 200);
            }

            $groupId = $groupMembership->group_id;

            // Get all group members
            $groupMembers = DB::table('group_members')
                ->join('customers', 'group_members.customer_id', '=', 'customers.id')
                ->where('group_members.group_id', $groupId)
                ->select('customers.*')
                ->orderBy('customers.name', 'asc')
                ->get();

            $members = [];
            foreach ($groupMembers as $member) {
                $members[] = [
                    'id' => $member->id,
                    'name' => $member->name,
                    'phone1' => $member->phone1,
                    'phone2' => $member->phone2,
                    'sex' => $member->sex,
                    'picture' => $member->photo ? asset('storage/' . $member->photo) : null,
                    'customerNo' => $member->customerNo,
                    'loans' => $this->getLoansWithRepayments($member->id),
                ];
            }

            return response()->json([
                'status' => 200,
                'members' => $members,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all loan products
     */
    public function loanProducts(Request $request)
    {
        try {
            $products = LoanProduct::where('is_active', true)
                ->with('filetypes')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'product_type' => $product->product_type,
                        'min_amount' => $product->minimum_principal,
                        'max_amount' => $product->maximum_principal,
                        'min_interest_rate' => $product->minimum_interest_rate,
                        'max_interest_rate' => $product->maximum_interest_rate,
                        'default_interest_rate' => $product->default_interest_rate ?? $product->minimum_interest_rate,
                        'interest_cycle' => $product->interest_cycle,
                        'interest_method' => $product->interest_method,
                        'min_period' => $product->minimum_period,
                        'max_period' => $product->maximum_period,
                        'grace_period' => $product->grace_period ?? 0,
                        'has_cash_collateral' => $product->has_cash_collateral ?? false,
                        'cash_collateral_type' => $product->cash_collateral_type,
                        'cash_collateral_value_type' => $product->cash_collateral_value_type,
                        'cash_collateral_value' => $product->cash_collateral_value ?? 0,
                        'allowed_in_app' => $product->allowed_in_app ?? false,
                        'filetypes' => $product->filetypes->map(function ($filetype) {
                            return [
                                'id' => $filetype->id,
                                'name' => $filetype->name,
                                'description' => $filetype->description,
                            ];
                        }),
                    ];
                });

            return response()->json([
                'status' => 200,
                'products' => $products,
                'total_products' => $products->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer photo
     */
    public function updatePhoto(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer',
                'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $customer = Customer::find($request->customer_id);

            if (!$customer) {
                return response()->json([
                    'message' => 'Customer not found',
                    'status' => 404
                ], 404);
            }

            // Delete old photo if exists
            if ($customer->photo && \Storage::disk('public')->exists($customer->photo)) {
                \Storage::disk('public')->delete($customer->photo);
            }

            // Store new photo
            $photoPath = $request->file('photo')->store('photos', 'public');
            $customer->photo = $photoPath;
            $saved = $customer->save();

            Log::info('Photo upload attempt', [
                'customer_id' => $customer->id,
                'photo_path' => $photoPath,
                'saved' => $saved,
                'customer_photo' => $customer->photo,
            ]);

            // Refresh customer to verify save
            $customer->refresh();

            return response()->json([
                'message' => 'Photo updated successfully',
                'status' => 200,
                'photo_url' => asset('storage/' . $photoPath),
                'photo_path' => $photoPath,
                'customer_id' => $customer->id,
                'saved_photo' => $customer->photo,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper function to get loans with repayments
     */
    private function getLoansWithRepayments($customerId)
    {
        $loans = Loan::where('customer_id', $customerId)
            ->with(['product', 'loanOfficer', 'schedule'])
            ->orderBy('id', 'desc')
            ->get();

        $result = [];
        foreach ($loans as $loan) {
            // Get repayments from repayments table
            $repayments = DB::table('repayments')
                ->where('loan_id', $loan->id)
                ->orderBy('payment_date', 'asc')
                ->get()
                ->map(function ($repayment) {
                    $totalAmount = ($repayment->principal ?? 0) + 
                                   ($repayment->interest ?? 0) + 
                                   ($repayment->penalt_amount ?? 0) + 
                                   ($repayment->fee_amount ?? 0);
                    return [
                        'id' => $repayment->id,
                        'amount' => $totalAmount,
                        'principal' => $repayment->principal ?? 0,
                        'interest' => $repayment->interest ?? 0,
                        'penalty' => $repayment->penalt_amount ?? 0,
                        'fee' => $repayment->fee_amount ?? 0,
                        'date' => $repayment->payment_date,
                        'due_date' => $repayment->due_date,
                    ];
                });

            // Get next unpaid schedule (only the first upcoming repayment)
            $schedules = DB::table('loan_schedules')
                ->where('loan_id', $loan->id)
                ->where('customer_id', $customerId)
                ->orderBy('due_date', 'asc')
                ->get();
            
            $nextSchedule = null;
            
            \Log::info("Loan ID: {$loan->id}, Customer ID: {$customerId}, Schedules count: " . $schedules->count());
            
            foreach ($schedules as $schedule) {
                $principal = (float)($schedule->principal ?? 0);
                $interest = (float)($schedule->interest ?? 0);
                $feeAmount = (float)($schedule->fee_amount ?? 0);
                $penaltyAmount = (float)($schedule->penalty_amount ?? 0);
                
                $totalDue = $principal + $interest + $feeAmount + $penaltyAmount;
                
                // Get paid amount for this schedule
                $paidAmount = (float)DB::table('repayments')
                    ->where('loan_schedule_id', $schedule->id)
                    ->sum(DB::raw('COALESCE(principal, 0) + COALESCE(interest, 0) + COALESCE(penalt_amount, 0) + COALESCE(fee_amount, 0)'));
                
                $remainingAmount = max(0, $totalDue - $paidAmount);
                
                \Log::info("Schedule ID: {$schedule->id}, Due Date: {$schedule->due_date}, Total Due: {$totalDue}, Paid: {$paidAmount}, Remaining: {$remainingAmount}");
                
                // Get the first schedule with remaining amount (next repayment)
                if ($remainingAmount > 0.01) { // Use 0.01 to handle floating point precision
                    $nextSchedule = [
                        'id' => $schedule->id,
                        'due_date' => $schedule->due_date,
                        'amount' => round($remainingAmount, 2),
                        'principal' => $principal,
                        'interest' => $interest,
                        'fee' => $feeAmount,
                        'penalty' => $penaltyAmount,
                        'total_due' => round($totalDue, 2),
                        'paid_amount' => round($paidAmount, 2),
                    ];
                    \Log::info("Next schedule found: " . json_encode($nextSchedule));
                    break; // Only get the first one
                }
            }
            
            if ($nextSchedule === null) {
                \Log::info("No unpaid schedule found for loan ID: {$loan->id}");
            }

            // Calculate totals
            $totalRepaid = $repayments->sum('amount');
            $totalDue = ($loan->amount_total ?? 0) - $totalRepaid;

            $result[] = [
                'loanid' => $loan->id,
                'loan_no' => $loan->loanNo,
                'amount' => $loan->amount,
                'interest' => $loan->interest,
                'interest_amount' => $loan->interest_amount,
                'total_amount' => $loan->amount_total,
                'period' => $loan->period,
                'interest_cycle' => $loan->interest_cycle ?? 'monthly',
                'disbursed_on' => $loan->disbursed_on,
                'last_repayment_date' => $loan->last_repayment_date,
                'status' => $loan->status,
                'product_name' => $loan->product->name ?? '',
                'loan_officer' => $loan->loanOfficer->name ?? '',
                'repayments' => $repayments,
                'next_schedule' => $nextSchedule, // Only the next upcoming repayment
                'total_repaid' => $totalRepaid,
                'total_due' => $totalDue,
            ];
        }

        return $result;
    }

    /**
     * Get customer contributions
     */
    public function contributions(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            // Get customer's contribution accounts with product details
            $contributions = ContributionAccount::with(['contributionProduct', 'branch'])
                ->where('customer_id', $customerId)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($account) {
                    return [
                        'id' => $account->id,
                        'account_number' => $account->account_number,
                        'product_name' => $account->contributionProduct->product_name ?? '',
                        'balance' => $account->balance,
                        'status' => $account->status,
                        'opening_date' => $account->opening_date,
                        'branch' => $account->branch->name ?? '',
                        'interest_rate' => $account->contributionProduct->interest ?? 0,
                        'can_withdraw' => $account->contributionProduct->can_withdraw ?? false,
                    ];
                });

            $totalBalance = $contributions->sum('balance');

            return response()->json([
                'status' => 200,
                'contributions' => $contributions,
                'total_balance' => $totalBalance,
                'accounts_count' => $contributions->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer shares
     */
    public function shares(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            // Get customer's share accounts with product details
            $shares = ShareAccount::with(['shareProduct', 'branch'])
                ->where('customer_id', $customerId)
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($account) {
                    $totalValue = $account->share_balance * $account->nominal_value;
                    
                    return [
                        'id' => $account->id,
                        'account_number' => $account->account_number,
                        'certificate_number' => $account->certificate_number,
                        'product_name' => $account->shareProduct->share_name ?? '',
                        'share_balance' => $account->share_balance,
                        'nominal_value' => $account->nominal_value,
                        'total_value' => $totalValue,
                        'status' => $account->status,
                        'opening_date' => $account->opening_date,
                        'last_transaction_date' => $account->last_transaction_date,
                        'branch' => $account->branch->name ?? '',
                        'dividend_rate' => $account->shareProduct->dividend_rate ?? 0,
                    ];
                });

            $totalShares = $shares->sum('share_balance');
            $totalValue = $shares->sum('total_value');

            return response()->json([
                'status' => 200,
                'shares' => $shares,
                'total_shares' => $totalShares,
                'total_value' => $totalValue,
                'accounts_count' => $shares->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get contribution account transactions
     * Uses the same logic as ContributionAccountController::getAccountTransactionsData
     */
    public function contributionTransactions(Request $request)
    {
        try {
            $accountId = $request->input('account_id');

            if (!$accountId) {
                return response()->json([
                    'message' => 'Account ID is required',
                    'status' => 400
                ], 400);
            }

            // Get contribution account
            $account = ContributionAccount::find($accountId);
            
            if (!$account) {
                return response()->json([
                    'message' => 'Account not found',
                    'status' => 404
                ], 404);
            }

            $product = $account->contributionProduct;
            if (!$product || !$product->liability_account_id) {
                return response()->json([
                    'message' => 'Product or liability account not configured',
                    'status' => 400
                ], 400);
            }

            $branchId = $account->branch_id;

            // Build query for transactions - same logic as web version
            $query = GlTransaction::where('chart_account_id', $product->liability_account_id)
                ->where('customer_id', $account->customer_id)
                ->where('branch_id', $branchId)
                ->whereIn('transaction_type', ['contribution_deposit', 'contribution_withdrawal', 'contribution_transfer', 'journal']);

            // Get date filters if provided
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            if ($startDate) {
                $query->whereDate('date', '>=', $startDate);
            }
            if ($endDate) {
                $query->whereDate('date', '<=', $endDate);
            }

            $transactions = $query->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // Process transactions - separate credit (iliyoingia) and debit (iliyotoka)
            $deposits = [];
            $withdrawals = [];

            foreach ($transactions as $transaction) {
                // Generate transaction ID - same logic as web version
                $trxId = '';
                if ($transaction->transaction_type === 'journal') {
                    $journal = Journal::find($transaction->transaction_id);
                    $trxId = $journal ? $journal->reference : 'JRN-' . str_pad($transaction->transaction_id, 6, '0', STR_PAD_LEFT);
                } elseif ($transaction->transaction_type === 'contribution_transfer') {
                    $journal = Journal::find($transaction->transaction_id);
                    $trxId = $journal ? $journal->reference : 'CT-' . str_pad($transaction->transaction_id, 6, '0', STR_PAD_LEFT);
                } else {
                    $prefix = $transaction->transaction_type === 'contribution_deposit' ? 'CD' : 'CW';
                    $trxId = $prefix . '-' . str_pad($transaction->transaction_id, 6, '0', STR_PAD_LEFT);
                }

                $transactionData = [
                    'id' => $transaction->id,
                    'trx_id' => $trxId,
                    'date' => $transaction->date->format('Y-m-d'),
                    'amount' => (float) $transaction->amount,
                    'reference' => $trxId,
                    'notes' => $transaction->description ?? '',
                    'type' => ucfirst(str_replace('_', ' ', $transaction->transaction_type)),
                ];

                // Credit = iliyoingia (money coming in)
                // Debit = iliyotoka (money going out)
                if ($transaction->nature === 'credit') {
                    $deposits[] = $transactionData;
                } else {
                    $withdrawals[] = $transactionData;
                }
            }

            return response()->json([
                'status' => 200,
                'deposits' => $deposits,
                'withdrawals' => $withdrawals,
                'total_transactions' => $transactions->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get share account transactions
     */
    public function shareTransactions(Request $request)
    {
        try {
            $accountId = $request->input('account_id');

            if (!$accountId) {
                return response()->json([
                    'message' => 'Account ID is required',
                    'status' => 400
                ], 400);
            }

            // Get deposits from share_deposits table
            $deposits = ShareDeposit::where('share_account_id', $accountId)
                ->orderBy('deposit_date', 'desc')
                ->get()
                ->map(function ($deposit) {
                    return [
                        'id' => $deposit->id,
                        'date' => $deposit->deposit_date,
                        'type' => 'deposit',
                        'amount' => $deposit->deposit_amount,
                        'shares' => $deposit->number_of_shares,
                        'charge' => $deposit->charge_amount ?? 0,
                        'total_amount' => $deposit->total_amount,
                        'reference' => $deposit->transaction_reference ?? '',
                        'notes' => $deposit->notes ?? '',
                        'status' => $deposit->status ?? 'completed',
                    ];
                });

            // Get withdrawals from share_withdrawals table
            $withdrawals = DB::table('share_withdrawals')
                ->where('share_account_id', $accountId)
                ->orderBy('withdrawal_date', 'desc')
                ->get()
                ->map(function ($withdrawal) {
                    return [
                        'id' => $withdrawal->id,
                        'date' => $withdrawal->withdrawal_date,
                        'type' => 'withdrawal',
                        'amount' => $withdrawal->withdrawal_amount ?? 0,
                        'shares' => $withdrawal->number_of_shares ?? 0,
                        'charge' => $withdrawal->charge_amount ?? 0,
                        'total_amount' => $withdrawal->total_amount ?? 0,
                        'reference' => $withdrawal->transaction_reference ?? '',
                        'notes' => $withdrawal->notes ?? '',
                        'status' => $withdrawal->status ?? 'completed',
                    ];
                });

            return response()->json([
                'status' => 200,
                'deposits' => $deposits,
                'withdrawals' => $withdrawals,
                'total_transactions' => $deposits->count() + $withdrawals->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit loan application from mobile app
     */
    public function submitLoanApplication(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:loan_products,id',
                'period' => 'required|integer|min:1',
                'interest' => 'required|numeric|min:0',
                'amount' => 'required|numeric|min:0',
                'date_applied' => 'required|date|before_or_equal:today',
                'customer_id' => 'required|exists:customers,id',
                'group_id' => 'nullable|exists:groups,id',
                'sector' => 'required|string',
                'interest_cycle' => 'required|string|in:daily,weekly,monthly,quarterly,semi_annually,annually',
                'custom_fee_amounts' => 'nullable|array',
                'custom_fee_amounts.*' => 'nullable|numeric|min:0',
            ]);

            $product = LoanProduct::findOrFail($validated['product_id']);
            $customer = Customer::findOrFail($validated['customer_id']);

            // Check if product is active
            if (!$product->is_active) {
                return response()->json([
                    'message' => 'Loan product is not active.',
                    'status' => 400
                ], 400);
            }

            // Validate product limits
            if ($validated['amount'] < $product->minimum_principal || $validated['amount'] > $product->maximum_principal) {
                return response()->json([
                    'message' => 'Loan amount must be between ' . number_format($product->minimum_principal, 2) . ' and ' . number_format($product->maximum_principal, 2) . '.',
                    'status' => 400
                ], 400);
            }

            if ($validated['interest'] < $product->minimum_interest_rate || $validated['interest'] > $product->maximum_interest_rate) {
                return response()->json([
                    'message' => 'Interest rate must be between ' . $product->minimum_interest_rate . '% and ' . $product->maximum_interest_rate . '%.',
                    'status' => 400
                ], 400);
            }

            if ($validated['period'] < $product->minimum_period || $validated['period'] > $product->maximum_period) {
                return response()->json([
                    'message' => 'Period must be between ' . $product->minimum_period . ' and ' . $product->maximum_period . ' months.',
                    'status' => 400
                ], 400);
            }

            $feeIds = is_array($product->fees_ids) ? $product->fees_ids : (json_decode($product->fees_ids ?? '[]', true) ?: []);
            $customFees = Fee::whereIn('id', $feeIds)->where('fee_type', 'custom')->where('status', 'active')->get();
            $input = $request->input('custom_fee_amounts', []);
            $customFeeAmounts = [];
            foreach ($customFees as $fee) {
                if (!array_key_exists($fee->id, $input) && !array_key_exists((string) $fee->id, $input)) {
                    return response()->json([
                        'message' => 'Amount is required for custom fee: ' . $fee->name,
                        'status' => 422,
                    ], 422);
                }
                $val = $input[$fee->id] ?? $input[(string) $fee->id];
                if (!is_numeric($val) || (float) $val < 0) {
                    return response()->json([
                        'message' => 'Invalid amount for custom fee: ' . $fee->name,
                        'status' => 422,
                    ], 422);
                }
                $customFeeAmounts[(int) $fee->id] = max(0, (float) $val);
            }

            // Check cash collateral if required
            if ($product->has_cash_collateral) {
                $requiredCollateral = $product->cash_collateral_value_type === 'percentage'
                    ? $customer->cash_collateral_balance * ($product->cash_collateral_value / 100)
                    : $product->cash_collateral_value;

                if ($requiredCollateral < $validated['amount']) {
                    return response()->json([
                        'message' => 'Member does not have enough collateral balance. Required: ' . number_format($requiredCollateral, 2),
                        'status' => 400
                    ], 400);
                }
            }

            // Check maximum number of loans
            if ($product->hasReachedMaxLoans($validated['customer_id'])) {
                $maxLoans = $product->maximum_number_of_loans;
                return response()->json([
                    'message' => "You have reached the maximum number of loans ({$maxLoans}) for this product.",
                    'status' => 400
                ], 400);
            }

            // Get customer's branch
            $branchId = $customer->branch_id;
            if (!$branchId) {
                return response()->json([
                    'message' => 'Customer branch not found.',
                    'status' => 400
                ], 400);
            }

            // Get group_id from customer if not provided
            // NOTE: nullable fields may be omitted from $validated entirely
            $groupId = $validated['group_id'] ?? null;
            if (!$groupId) {
                $groupMembership = DB::table('group_members')
                    ->where('customer_id', $validated['customer_id'])
                    ->first();
                $groupId = $groupMembership->group_id ?? null;
            }

            DB::beginTransaction();

            // Monthly rate from form → per-period rate (same as web direct loan / application)
            $convertedInterest = InterestRateConverter::fromMonthlyToCycle(
                (float) $validated['interest'],
                $validated['interest_cycle']
            );

            // Create loan application with 'applied' status
            $loan = Loan::create([
                'product_id' => $validated['product_id'],
                'period' => $validated['period'],
                'interest' => $convertedInterest,
                'amount' => $validated['amount'],
                'customer_id' => $validated['customer_id'],
                'group_id' => $groupId,
                'bank_account_id' => null, // Set to null for loan applications
                'date_applied' => $validated['date_applied'],
                'sector' => $validated['sector'],
                'interest_cycle' => $validated['interest_cycle'],
                'loan_officer_id' => null, // Will be set during approval
                'branch_id' => $branchId,
                'status' => 'applied', // Loan application status
                'interest_amount' => 0, // Will be calculated below
                'amount_total' => 0, // Will be calculated below
                'first_repayment_date' => null,
                'last_repayment_date' => null,
                'disbursed_on' => null,
                'top_up_id' => null,
                'custom_fee_amounts' => !empty($customFeeAmounts) ? $customFeeAmounts : null,
            ]);

            // Calculate interest amount using converted per-period rate
            $interestAmount = $loan->calculateInterestAmount($convertedInterest);
            $loan->update([
                'interest_amount' => $interestAmount,
                'amount_total' => $validated['amount'] + $interestAmount,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Loan application submitted successfully and awaiting approval.',
                'status' => 200,
                'loan' => [
                    'id' => $loan->id,
                    'loan_no' => $loan->loanNo,
                    'amount' => $loan->amount,
                    'total_amount' => $loan->amount_total,
                    'status' => $loan->status,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'status' => 422,
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting loan application: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to submit loan application: ' . $e->getMessage(),
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get complain categories for mobile app
     */
    public function getComplainCategories()
    {
        try {
            $categories = \App\Models\ComplainCategory::where('id', '>', 0)
                ->orderBy('priority', 'desc')
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'description' => $category->description,
                        'priority' => $category->priority,
                    ];
                });

            return response()->json([
                'status' => 200,
                'categories' => $categories,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit complain from mobile app
     */
    public function submitComplain(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'complain_category_id' => 'required|exists:complain_categories,id',
                'description' => 'required|string|min:10',
            ]);

            $customer = Customer::findOrFail($validated['customer_id']);
            $branchId = $customer->branch_id;

            $complain = \App\Models\Complain::create([
                'customer_id' => $validated['customer_id'],
                'complain_category_id' => $validated['complain_category_id'],
                'description' => $validated['description'],
                'status' => 'pending',
                'branch_id' => $branchId,
            ]);

            return response()->json([
                'message' => 'Complain submitted successfully.',
                'status' => 200,
                'complain' => [
                    'id' => $complain->id,
                    'status' => $complain->status,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'status' => 422,
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error submitting complain: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to submit complain: ' . $e->getMessage(),
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer complains
     */
    public function getCustomerComplains(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');

            if (!$customerId) {
                return response()->json([
                    'message' => 'Customer ID is required',
                    'status' => 400
                ], 400);
            }

            $complains = \App\Models\Complain::with(['category', 'respondedBy'])
                ->where('customer_id', $customerId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($complain) {
                    $response = $complain->response;
                    if (is_string($response)) {
                        $response = trim($response);
                        if ($response === '') $response = null;
                    }
                    return [
                        'id' => $complain->id,
                        'category_name' => $complain->category->name ?? 'N/A',
                        'description' => $complain->description,
                        'status' => $complain->status,
                        'response' => $response,
                        'responded_by' => $complain->respondedBy->name ?? null,
                        'responded_at' => $complain->responded_at ? $complain->responded_at->format('Y-m-d H:i:s') : null,
                        'created_at' => $complain->created_at->format('Y-m-d H:i:s'),
                    ];
                });

            return response()->json([
                'status' => 200,
                'complains' => $complains,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting customer complains: ' . $e->getMessage());
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer next of kin
     */
    public function getNextOfKin(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            
            if (!$userId) {
                return response()->json([
                    'status' => 400,
                    'message' => 'User ID is required'
                ], 400);
            }

            $customer = Customer::with('nextOfKin')->find($userId);
            
            if (!$customer) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Customer not found'
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'nextOfKin' => $customer->nextOfKin ?? []
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching next of kin: ' . $e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'Server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active announcements for mobile app
     */
    public function getAnnouncements(Request $request)
    {
        try {
            $customerId = $request->input('customer_id');
            
            if (!$customerId) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Customer ID is required'
                ], 400);
            }

            $customer = Customer::find($customerId);
            
            if (!$customer) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Customer not found'
                ], 404);
            }

            // Get active announcements for the customer's company
            $announcements = \App\Models\Announcement::active()
                ->where('company_id', $customer->company_id)
                ->orderBy('order', 'asc')
                ->orderBy('created_at', 'desc')
                ->limit(10) // Limit to 10 most recent
                ->get();
            
            Log::info('Announcements query', [
                'customer_id' => $customerId,
                'company_id' => $customer->company_id,
                'count' => $announcements->count(),
            ]);
            
            $mappedAnnouncements = $announcements->map(function ($announcement) {
                // Map color name to Flutter Color
                $colorMap = [
                    'blue' => 0xFF0D6EFD,
                    'green' => 0xFF198754,
                    'orange' => 0xFFFD7E14,
                    'red' => 0xFFDC3545,
                    'purple' => 0xFF6F42C1,
                    'yellow' => 0xFFFFC107,
                ];
                
                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'message' => $announcement->message,
                    'icon' => $announcement->icon ?? 'info_outline',
                    'color' => $colorMap[$announcement->color] ?? 0xFF0D6EFD,
                    'image_url' => $announcement->image_url ?? null,
                ];
            });

            return response()->json([
                'status' => 200,
                'announcements' => $mappedAnnouncements->values()->all(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error getting announcements: ' . $e->getMessage());
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List transactions (receipts) for the logged-in customer: loan repayments, fees, penalty.
     * Expects: customer_id (POST).
     */
    public function customerTransactions(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
            ]);
            $customerId = (int) $request->input('customer_id');

            $receipts = Receipt::byCustomer($customerId)
                ->whereIn('reference_type', ['loan_repayment', 'Repayment'])
                ->orderByDesc('date')
                ->get()
                ->map(function (Receipt $r) {
                    return [
                        'id' => $r->id,
                        'date' => $r->date?->format('Y-m-d'),
                        'description' => $r->description ?? 'Malipo ya mkopo',
                        'amount' => (float) $r->amount,
                        'reference_type' => $r->reference_type,
                        'reference_number' => $r->reference_number,
                    ];
                });

            return response()->json([
                'status' => 200,
                'transactions' => $receipts->values()->all(),
                'total' => $receipts->count(),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error getting customer transactions: ' . $e->getMessage());
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change customer password. Expects: customer_id, old_password, new_password.
     * On success the client should log the user out and require re-login.
     */
    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:6',
            ]);
            $customerId = (int) $request->input('customer_id');
            $customer = Customer::findOrFail($customerId);

            if (!Hash::check($request->old_password, $customer->password)) {
                return response()->json([
                    'status' => 401,
                    'message' => 'Neno la siri la zamani si sahihi.',
                ], 401);
            }

            $customer->update([
                'password' => Hash::make($request->new_password),
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Neno la siri limebadilishwa.',
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error changing password: ' . $e->getMessage());
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get company contact (phone, email) for Msaada / support. From companies table.
     */
    public function companyContact(Request $request)
    {
        try {
            $company = Company::active()->first() ?? Company::first();
            if (!$company) {
                return response()->json([
                    'status' => 200,
                    'company' => ['name' => null, 'phone' => null, 'email' => null],
                ], 200);
            }
            return response()->json([
                'status' => 200,
                'company' => [
                    'name' => $company->name,
                    'phone' => $company->phone,
                    'email' => $company->email,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error getting company contact: ' . $e->getMessage());
            return response()->json([
                'message' => 'Server error',
                'status' => 500,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}