<?php

namespace App\Jobs;

use App\Models\BankAccount;
use App\Models\CashCollateral;
use App\Models\Customer;
use App\Models\GlTransaction;
use App\Services\LoanDisbursementGlService;
use App\Models\Journal;
use App\Models\JournalItem;
use App\Models\Loan;
use App\Models\LoanProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BulkLoanCreationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 2;

    /** @var array<int, array<int, mixed>> */
    protected array $chunkData;

    /** @var array<string, mixed> */
    protected array $validated;

    protected int $userId;

    /** @var array<int, string> */
    protected array $headerRow;

    protected int $chunkIndex;

    protected int $totalChunks;

    protected ?string $importId;

    /** @var array<string, int>|null */
    protected ?array $columnMap = null;

    /**
     * @param  array<int, array<int, mixed>>  $chunkData
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $headerRow
     */
    public function __construct(
        array $chunkData,
        array $validated,
        int $userId,
        array $headerRow,
        int $chunkIndex = 0,
        int $totalChunks = 1,
        ?string $importId = null
    ) {
        $this->chunkData = $chunkData;
        $this->validated = $validated;
        $this->userId = $userId;
        $this->headerRow = $headerRow;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
        $this->importId = $importId;
    }

    public function handle(): void
    {
        Log::info('Opening balance chunk started', [
            'import_id' => $this->importId,
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'chunk_size' => count($this->chunkData),
        ]);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($this->validated['product_id']);
        $chartAccountId = (int) $this->validated['chart_account_id'];

        $chunkSuccess = 0;
        $chunkFailed = 0;

        foreach ($this->chunkData as $rowIndex => $row) {
            if (! is_array($row)) {
                $chunkFailed++;
                $this->recordFailure($rowIndex, 'Unknown', 'Invalid row data');

                continue;
            }

            try {
                $loanData = $this->processLoanRow($row, $product, $chartAccountId);

                if (! $loanData) {
                    continue;
                }

                $loan = $this->createLoan($loanData, $product, $chartAccountId);
                if ($loan) {
                    $chunkSuccess++;
                    $this->touchProgress(1, 0);

                    if ($loanData['amount_paid'] > 0) {
                        $this->appendRepayment([
                            'loan_id' => $loan->id,
                            'amount' => $loanData['amount_paid'],
                            'payment_date' => $loanData['date_applied'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $chunkFailed++;
                $this->recordFailure(
                    $rowIndex,
                    (string) $this->cell($row, 'customer_no', 'Unknown'),
                    $e->getMessage()
                );
            }
        }

        if ($this->importId && ($this->chunkIndex + 1) >= $this->totalChunks) {
            $this->finalizeImport();
        }

        Log::info('Opening balance chunk completed', [
            'import_id' => $this->importId,
            'chunk_index' => $this->chunkIndex,
            'success' => $chunkSuccess,
            'failed' => $chunkFailed,
        ]);
    }

    protected function touchProgress(int $successDelta, int $failedDelta): void
    {
        if (! $this->importId) {
            return;
        }

        $progress = Cache::get($this->importId, []);
        $progress['success'] = ($progress['success'] ?? 0) + $successDelta;
        $progress['failed'] = ($progress['failed'] ?? 0) + $failedDelta;
        $progress['current'] = ($progress['current'] ?? 0) + $successDelta + $failedDelta;
        $total = max(1, (int) ($progress['total'] ?? 1));
        $progress['percentage'] = min(99, (int) round(($progress['current'] / $total) * 100));
        $progress['status'] = 'processing';

        Cache::put($this->importId, $progress, 7200);
    }

    protected function recordFailure(int $rowIndex, string $customerNo, string $message): void
    {
        $this->touchProgress(0, 1);

        if (! $this->importId) {
            return;
        }

        $progress = Cache::get($this->importId, []);
        $errors = $progress['errors'] ?? [];
        if (count($errors) < 50) {
            $globalRow = ($this->chunkIndex * max(1, count($this->chunkData))) + $rowIndex + 1;
            $errors[] = [
                'row' => $globalRow,
                'customer_no' => $customerNo,
                'message' => $message,
            ];
            $progress['errors'] = $errors;
            Cache::put($this->importId, $progress, 7200);
        }

        Log::error('Opening balance row failed', [
            'import_id' => $this->importId,
            'customer_no' => $customerNo,
            'error' => $message,
        ]);
    }

    protected function finalizeImport(): void
    {
        $progress = Cache::get($this->importId, []);
        $progress['status'] = 'completed';
        $progress['percentage'] = 100;
        $progress['current'] = $progress['total'] ?? $progress['current'] ?? 0;
        Cache::put($this->importId, $progress, 7200);

        $repayments = Cache::pull($this->importId.'_repayments', []);
        if (! empty($repayments)) {
            Log::info('Dispatching bulk repayment after opening balance', [
                'import_id' => $this->importId,
                'count' => count($repayments),
            ]);
            BulkRepaymentJob::dispatch($repayments, $this->userId, (int) $this->validated['chart_account_id']);
        }
    }

    protected function appendRepayment(array $entry): void
    {
        if (! $this->importId) {
            return;
        }

        $key = $this->importId.'_repayments';
        $repayments = Cache::get($key, []);
        $repayments[] = $entry;
        Cache::put($key, $repayments, 7200);
    }

    /**
     * @return array<string, int>
     */
    protected function getColumnMap(): array
    {
        if ($this->columnMap !== null) {
            return $this->columnMap;
        }

        if (! empty($this->headerRow)) {
            $this->columnMap = [];
            foreach ($this->headerRow as $i => $h) {
                $key = strtolower(preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $h)));
                if ($key !== '') {
                    $this->columnMap[$key] = $i;
                }
            }

            return $this->columnMap;
        }

        $this->columnMap = [
            'customer_no' => 0,
            'customer_name' => 1,
            'group_id' => 2,
            'group_name' => 3,
            'amount' => 4,
            'interest' => 5,
            'period' => 6,
            'date_applied' => 7,
            'sector' => 8,
            'amount_paid' => 9,
        ];

        return $this->columnMap;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    protected function cell(array $row, string $name, $default = '')
    {
        $map = $this->getColumnMap();
        $i = $map[strtolower($name)] ?? null;
        if ($i === null || ! array_key_exists($i, $row)) {
            return $default;
        }

        return $row[$i];
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function processLoanRow(array $row, LoanProduct $product, int $chartAccountId): ?array
    {
        $firstRepaymentRaw = trim((string) $this->cell($row, 'first_repayment_date', ''));
        $firstRepaymentDate = $firstRepaymentRaw !== '' ? $firstRepaymentRaw : null;

        $cycleRaw = strtolower(trim((string) $this->cell($row, 'interest_cycle', '')));
        if ($cycleRaw === '') {
            // Default is independent of loan product; template forces user choice.
            $cycleRaw = 'monthly';
        }
        if ($cycleRaw === 'yearly') {
            $cycleRaw = 'annually';
        }

        $validCycles = ['daily', 'weekly', 'bimonthly', 'monthly', 'quarterly', 'semi_annually', 'annually'];
        if (! in_array($cycleRaw, $validCycles, true)) {
            throw new \Exception(
                'Invalid interest_cycle. Allowed: '.implode(', ', $validCycles)
            );
        }

        $data = [
            'customer_no' => trim((string) $this->cell($row, 'customer_no', '')),
            'customer_name' => trim((string) $this->cell($row, 'customer_name', '')),
            'group_id' => trim((string) $this->cell($row, 'group_id', '')),
            'group_name' => trim((string) $this->cell($row, 'group_name', '')),
            'amount' => floatval($this->cell($row, 'amount', 0)),
            'interest' => floatval($this->cell($row, 'interest', 0)),
            'period' => intval($this->cell($row, 'period', 0)),
            'interest_cycle' => $cycleRaw,
            'date_applied' => $this->cell($row, 'date_applied', date('Y-m-d')),
            'first_repayment_date' => $firstRepaymentDate,
            'sector' => $this->cell($row, 'sector', 'Business'),
            'amount_paid' => floatval($this->cell($row, 'amount_paid', 0)),
        ];

        if ($firstRepaymentDate) {
            $dFirst = \Carbon\Carbon::parse($firstRepaymentDate)->startOfDay();
            $dDisb = \Carbon\Carbon::parse($data['date_applied'])->startOfDay();
            if ($dFirst->lt($dDisb)) {
                throw new \Exception('first_repayment_date must be on or after date_applied');
            }
        }

        if (empty($data['customer_no']) || $data['amount'] <= 0 || $data['interest'] <= 0 || $data['period'] <= 0) {
            throw new \Exception('Invalid loan data: missing required fields or invalid values');
        }

        $customer = Customer::where('customerNo', $data['customer_no'])->first();
        if (! $customer) {
            throw new \Exception("Customer not found: {$data['customer_no']}");
        }

        if (! $product->isAmountWithinLimits($data['amount'])) {
            throw new \Exception("Loan amount {$data['amount']} is outside product limits");
        }

        if (! $product->isPeriodWithinLimits($data['period'])) {
            throw new \Exception("Loan period {$data['period']} is outside product limits");
        }

        if ($product->hasReachedMaxLoans($customer->id)) {
            $maxLoans = $product->maximum_number_of_loans ?? 'the configured maximum';
            throw new \Exception("Customer has reached the maximum number of active loans ({$maxLoans}) for this product");
        }

        if ($product->requiresCollateral()) {
            $requiredCollateral = $product->calculateRequiredCollateral($data['amount']);
            $availableCollateral = CashCollateral::getCashCollateralBalance($customer->id);

            if ($availableCollateral < $requiredCollateral) {
                throw new \Exception("Insufficient collateral. Required: {$requiredCollateral}, Available: {$availableCollateral}");
            }
        }

        return array_merge($data, [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'branch_id' => $this->validated['branch_id'],
            'chart_account_id' => $chartAccountId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $loanData
     */
    private function createLoan(array $loanData, LoanProduct $product, int $chartAccountId): ?Loan
    {
        return DB::transaction(function () use ($loanData, $product, $chartAccountId) {
            $loan = Loan::create([
                'product_id' => $loanData['product_id'],
                'period' => $loanData['period'],
                'interest' => $loanData['interest'],
                'amount' => $loanData['amount'],
                'customer_id' => $loanData['customer_id'],
                'group_id' => $loanData['group_id'] ?: null,
                'bank_account_id' => null,
                'date_applied' => $loanData['date_applied'],
                'disbursed_on' => $loanData['date_applied'],
                'sector' => $loanData['sector'],
                'branch_id' => $loanData['branch_id'],
                'status' => 'active',
                'interest_cycle' => $loanData['interest_cycle'],
                'loan_officer_id' => $this->userId,
            ]);

            $interestAmount = $loan->calculateInterestAmount($loanData['interest']);
            $repaymentDates = $loan->resolveRepaymentDates($loanData['first_repayment_date'] ?? null);

            $loan->update([
                'interest_amount' => $interestAmount,
                'amount_total' => $loan->amount + $interestAmount,
                'first_repayment_date' => $repaymentDates['first_repayment_date'],
                'last_repayment_date' => $repaymentDates['last_repayment_date'],
            ]);

            $loan->generateRepaymentSchedule($loanData['interest']);
            $loan->postMaturedInterestForPastLoan();
            $loan->accruePenaltiesForPastLoanWhenReady();

            $disbursementGlService = app(LoanDisbursementGlService::class);
            $loan->loadMissing(['product', 'customer']);
            $notes = $disbursementGlService->disbursementDescription($loan);
            $principalReceivable = $product->principal_receivable_account_id;

            if (! $principalReceivable) {
                throw new \Exception('Principal receivable account not set for this loan product.');
            }

            $principalAmount = round((float) $loan->amount, 2);
            $releaseFeeTotal = $disbursementGlService->calculateReleaseFeeTotal($loan);
            $disbursementAmount = round($principalAmount - $releaseFeeTotal, 2);

            $journal = Journal::create([
                'date' => $loanData['date_applied'],
                'description' => $notes,
                'branch_id' => $loanData['branch_id'],
                'user_id' => $this->userId,
                'reference_type' => LoanDisbursementGlService::TRANSACTION_TYPE,
                'reference' => $loan->id,
            ]);

            JournalItem::create([
                'journal_id' => $journal->id,
                'chart_account_id' => $chartAccountId,
                'amount' => $disbursementAmount,
                'nature' => 'credit',
                'description' => $notes,
            ]);

            JournalItem::create([
                'journal_id' => $journal->id,
                'chart_account_id' => $principalReceivable,
                'amount' => $principalAmount,
                'nature' => 'debit',
                'description' => $notes,
            ]);

            $bankAccount = BankAccount::where('chart_account_id', $chartAccountId)->first();
            if ($bankAccount) {
                $loan->update(['bank_account_id' => $bankAccount->id]);
                $loan->refresh();
            }

            if (! $disbursementGlService->hasDisbursementGl($loan->id)) {
                if ($loan->bank_account_id) {
                    $disbursementGlService->postDisbursement(
                        $loan,
                        $loanData['date_applied'],
                        $this->userId,
                        $loanData['branch_id']
                    );
                } else {
                    GlTransaction::insert([
                        [
                            'chart_account_id' => $chartAccountId,
                            'customer_id' => $loan->customer_id,
                            'amount' => $disbursementAmount,
                            'nature' => 'credit',
                            'transaction_id' => $loan->id,
                            'transaction_type' => LoanDisbursementGlService::TRANSACTION_TYPE,
                            'date' => $loanData['date_applied'],
                            'description' => $notes,
                            'branch_id' => $loanData['branch_id'],
                            'user_id' => $this->userId,
                        ],
                        [
                            'chart_account_id' => $principalReceivable,
                            'customer_id' => $loan->customer_id,
                            'amount' => $principalAmount,
                            'nature' => 'debit',
                            'transaction_id' => $loan->id,
                            'transaction_type' => LoanDisbursementGlService::TRANSACTION_TYPE,
                            'date' => $loanData['date_applied'],
                            'description' => $notes,
                            'branch_id' => $loanData['branch_id'],
                            'user_id' => $this->userId,
                        ],
                    ]);
                }
            }

            return $loan;
        });
    }
}
