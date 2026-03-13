<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\GlTransaction;
use App\Models\Group;
use App\Models\User;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\PaymentItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BulkLoanImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $chunkData;
    protected $productId;
    protected $chartAccountId;
    protected $branchId;
    protected $userId;
    protected $skipErrors;
    protected $chunkIndex;
    protected $totalChunks;
    protected $importId;

    /**
     * Create a new job instance.
     */
    public function __construct($chunkData, $productId, $chartAccountId, $branchId, $userId, $skipErrors, $chunkIndex = 0, $totalChunks = 1, $importId = null)
    {
        $this->chunkData = $chunkData;
        $this->productId = $productId;
        $this->chartAccountId = $chartAccountId;
        $this->branchId = $branchId;
        $this->userId = $userId;
        $this->skipErrors = $skipErrors;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
        $this->importId = $importId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing bulk loan import chunk', [
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'chunk_size' => count($this->chunkData),
            'user_id' => $this->userId
        ]);

        $product = LoanProduct::with('principalReceivableAccount')->findOrFail($this->productId);
        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        foreach ($this->chunkData as $rowIndex => $rowData) {
            try {
                $rowNumber = ($this->chunkIndex * count($this->chunkData)) + $rowIndex + 2;
                
                // Validate row
                $validated = $this->validateLoanRow($rowData, $rowNumber);
                
                if (isset($validated['error'])) {
                    if (strpos($validated['error'], 'Customer number') !== false && strpos($validated['error'], 'not found') !== false) {
                        $skippedCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    }
                    
                    if ($this->skipErrors) {
                        $skippedCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    } else {
                        $errorCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    }
                }

                // Check product limits
                try {
                    $this->validateProductLimits($validated, $product);
                } catch (\Exception $e) {
                    if ($this->skipErrors) {
                        $skippedCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    } else {
                        $errorCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    }
                }

                // Check if customer already has active loan for this product
                $existingLoan = Loan::where('customer_id', $validated['customer_id'])
                    ->where('product_id', $this->productId)
                    ->where('status', 'active')
                    ->first();

                if ($existingLoan) {
                    if ($this->skipErrors) {
                        $skippedCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    } else {
                        $errorCount++;
                        $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                        continue;
                    }
                }

                // Check collateral if required
                if ($product->requiresCollateral()) {
                    $requiredCollateral = $product->calculateRequiredCollateral($validated['amount']);
                    $availableCollateral = \App\Models\CashCollateral::getCashCollateralBalance($validated['customer_id']);

                    if ($availableCollateral < $requiredCollateral) {
                        if ($this->skipErrors) {
                            $skippedCount++;
                            $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                            continue;
                        } else {
                            $errorCount++;
                            $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                            continue;
                        }
                    }
                }

                // Create loan
                DB::beginTransaction();
                
                $loan = $this->createLoan($validated, $product);
                
                DB::commit();
                $successCount++;
                $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to create loan in bulk import', [
                    'row_data' => $rowData,
                    'error' => $e->getMessage()
                ]);
                $errorCount++;
                $this->updateProgress($skippedCount, $errorCount, $successCount, $rowIndex + 1);
            }
        }
        
        // Update final progress when chunk completes
        if ($this->importId) {
            $progress = Cache::get($this->importId, []);
            $progress['success'] = ($progress['success'] ?? 0) + $successCount;
            $progress['failed'] = ($progress['failed'] ?? 0) + $errorCount;
            $progress['skipped'] = ($progress['skipped'] ?? 0) + $skippedCount;
            
            // Check if all chunks are done
            $allChunksDone = ($this->chunkIndex + 1) >= $this->totalChunks;
            if ($allChunksDone) {
                $progress['status'] = 'completed';
                $progress['percentage'] = 100;
            }
            
            Cache::put($this->importId, $progress, 600);
        }

        Log::info('Completed bulk loan import chunk', [
            'chunk_index' => $this->chunkIndex,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'skipped_count' => $skippedCount
        ]);
    }

    private function validateLoanRow($rowData, $rowNumber)
    {
        try {
            $required = ['customer_no', 'amount', 'period', 'interest', 'date_applied', 'interest_cycle', 'loan_officer', 'group_id', 'sector'];
            foreach ($required as $field) {
                if (empty($rowData[$field])) {
                    return ['error' => "Row $rowNumber: Missing required field '$field'"];
                }
            }

            $customer = Customer::where('customerNo', $rowData['customer_no'])->first();
            if (!$customer) {
                return ['error' => "Row $rowNumber: Customer number '{$rowData['customer_no']}' not found"];
            }

            if (!is_numeric($rowData['amount']) || $rowData['amount'] <= 0) {
                return ['error' => "Row $rowNumber: Invalid amount"];
            }

            if (!is_numeric($rowData['period']) || $rowData['period'] <= 0) {
                return ['error' => "Row $rowNumber: Invalid period"];
            }

            if (!is_numeric($rowData['interest']) || $rowData['interest'] < 0) {
                return ['error' => "Row $rowNumber: Invalid interest"];
            }

            // Parse date
            $dateValue = $rowData['date_applied'];
            $parsedDate = null;
            if (is_numeric($dateValue)) {
                try {
                    $carbon = \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $dateValue));
                    $parsedDate = $carbon->format('Y-m-d');
                } catch (\Throwable $t) {
                    return ['error' => "Row $rowNumber: Invalid date_applied"];
                }
            } else {
                try {
                    $carbon = \Carbon\Carbon::createFromFormat('Y-m-d', (string) $dateValue);
                    $parsedDate = $carbon->format('Y-m-d');
                } catch (\Throwable $t) {
                    return ['error' => "Row $rowNumber: Invalid date_applied (expected YYYY-MM-DD)"];
                }
            }
            
            if (strtotime($parsedDate) > time()) {
                return ['error' => "Row $rowNumber: Invalid date_applied (future date)"];
            }

            $validCycles = ['daily', 'weekly', 'monthly', 'quarterly', 'semi_annually', 'annually', 'yearly'];
            if (!in_array(strtolower($rowData['interest_cycle']), $validCycles, true)) {
                return ['error' => "Row $rowNumber: Invalid interest_cycle"];
            }

            // Handle loan_officer - can be ID or name
            $loanOfficerId = null;
            if (is_numeric($rowData['loan_officer'])) {
                $loanOfficerId = $rowData['loan_officer'];
            } else {
                $officer = User::where('name', trim($rowData['loan_officer']))->first();
                $loanOfficerId = $officer ? $officer->id : null;
            }
            
            if (!$loanOfficerId || !User::find($loanOfficerId)) {
                return ['error' => "Row $rowNumber: Invalid loan_officer"];
            }

            if (!is_numeric($rowData['group_id']) || !Group::find($rowData['group_id'])) {
                return ['error' => "Row $rowNumber: Invalid group_id"];
            }

            return [
                'customer_id' => $customer->id,
                'customer_no' => $rowData['customer_no'],
                'amount' => (float) $rowData['amount'],
                'period' => (int) $rowData['period'],
                'interest' => (float) $rowData['interest'],
                'date_applied' => $parsedDate,
                'interest_cycle' => strtolower($rowData['interest_cycle']),
                'loan_officer' => $loanOfficerId,
                'group_id' => (int) $rowData['group_id'],
                'sector' => $rowData['sector'],
            ];
        } catch (\Exception $e) {
            return ['error' => "Row $rowNumber: Validation error - " . $e->getMessage()];
        }
    }

    private function validateProductLimits($validated, $product)
    {
        if (!$product->isAmountWithinLimits($validated['amount'])) {
            throw new \Exception("Loan amount {$validated['amount']} is outside product limits ({$product->min_amount} - {$product->max_amount})");
        }

        if (!$product->isPeriodWithinLimits($validated['period'])) {
            throw new \Exception("Loan period {$validated['period']} is outside product limits ({$product->min_period} - {$product->max_period})");
        }

        $minInterest = $product->min_interest_rate ?? 0;
        $maxInterest = $product->max_interest_rate ?? 100;
        if ($validated['interest'] < $minInterest || $validated['interest'] > $maxInterest) {
            throw new \Exception("Interest rate {$validated['interest']} is outside product limits ({$minInterest} - {$maxInterest})");
        }
    }

    private function createLoan($validated, $product)
    {
        // Create Loan
        $loan = Loan::create([
            'product_id' => $this->productId,
            'period' => $validated['period'],
            'interest' => $validated['interest'],
            'amount' => $validated['amount'],
            'customer_id' => $validated['customer_id'],
            'group_id' => $validated['group_id'],
            'bank_account_id' => $this->chartAccountId,
            'date_applied' => $validated['date_applied'],
            'disbursed_on' => $validated['date_applied'],
            'sector' => $validated['sector'],
            'branch_id' => $this->branchId,
            'status' => 'active',
            'interest_cycle' => $validated['interest_cycle'],
            'loan_officer_id' => $validated['loan_officer'],
        ]);

        // Calculate interest and repayment dates
        $interestAmount = $loan->calculateInterestAmount($validated['interest']);
        $repaymentDates = $loan->getRepaymentDates();

        // Update Loan with totals and schedule
        $loan->update([
            'interest_amount' => $interestAmount,
            'amount_total' => $loan->amount + $interestAmount,
            'first_repayment_date' => $repaymentDates['first_repayment_date'],
            'last_repayment_date' => $repaymentDates['last_repayment_date'],
        ]);

        // Generate repayment schedule
        $loan->generateRepaymentSchedule($validated['interest']);

        // Post matured interest for past loans
        $loan->postMaturedInterestForPastLoan();

        // Record Payment
        $bankAccount = \App\Models\BankAccount::findOrFail($this->chartAccountId);
        $notes = "Being disbursement for loan of {$product->name}, paid to {$loan->customer->name}, TSHS.{$validated['amount']}";
        $principalReceivable = optional($product->principalReceivableAccount)->id;

        if (!$principalReceivable) {
            throw new \Exception('Principal receivable account not set for this loan product.');
        }

        $payment = \App\Models\Payment::create([
            'reference' => $loan->id,
            'reference_type' => 'Loan Payment',
            'reference_number' => null,
            'date' => $validated['date_applied'],
            'amount' => $validated['amount'],
            'description' => $notes,
            'user_id' => $this->userId,
            'payee_type' => 'customer',
            'customer_id' => $validated['customer_id'],
            'bank_account_id' => $this->chartAccountId,
            'branch_id' => $this->branchId,
            'approved' => true,
            'approved_by' => $this->userId,
            'approved_at' => now(),
        ]);

        \App\Models\PaymentItem::create([
            'payment_id' => $payment->id,
            'chart_account_id' => $principalReceivable,
            'amount' => $validated['amount'],
            'description' => $notes,
        ]);

        // GL Transactions
        $releaseFeeTotal = 0;
        if ($product && $product->fees_ids) {
            $feeIds = is_array($product->fees_ids) ? $product->fees_ids : json_decode($product->fees_ids, true);
            if (is_array($feeIds)) {
                $releaseFees = DB::table('fees')
                    ->whereIn('id', $feeIds)
                    ->where('deduction_criteria', 'charge_fee_on_release_date')
                    ->where('status', 'active')
                    ->get();
                foreach ($releaseFees as $fee) {
                    $feeAmount = (float) $fee->amount;
                    $feeType = $fee->fee_type;
                    $releaseFeeTotal += $feeType === 'percentage'
                        ? ((float) $validated['amount'] * (float) $feeAmount / 100)
                        : (float) $feeAmount;
                }
            }
        }

        $disbursementAmount = $validated['amount'] - $releaseFeeTotal;

        GlTransaction::insert([
            [
                'transaction_date' => $validated['date_applied'],
                'chart_account_id' => $this->chartAccountId,
                'debit' => $validated['amount'],
                'credit' => 0,
                'description' => $notes,
                'reference_type' => 'Loan',
                'reference_id' => $loan->id,
                'branch_id' => $this->branchId,
                'company_id' => auth()->user()->company_id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_date' => $validated['date_applied'],
                'chart_account_id' => $principalReceivable,
                'debit' => 0,
                'credit' => $validated['amount'],
                'description' => $notes,
                'reference_type' => 'Loan',
                'reference_id' => $loan->id,
                'branch_id' => $this->branchId,
                'company_id' => auth()->user()->company_id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $loan;
    }
    
    /**
     * Update progress tracking
     */
    private function updateProgress($skippedCount, $errorCount, $successCount, $currentRowInChunk)
    {
        if (!$this->importId) {
            return;
        }
        
        $progress = Cache::get($this->importId, []);
        $totalProcessed = ($this->chunkIndex * count($this->chunkData)) + $currentRowInChunk;
        $total = $progress['total'] ?? 1;
        
        // Calculate incremental changes
        $lastSuccess = $progress['last_success_' . $this->chunkIndex] ?? 0;
        $lastFailed = $progress['last_failed_' . $this->chunkIndex] ?? 0;
        $lastSkipped = $progress['last_skipped_' . $this->chunkIndex] ?? 0;
        
        $progress['current'] = $totalProcessed;
        $progress['success'] = ($progress['success'] ?? 0) + ($successCount - $lastSuccess);
        $progress['failed'] = ($progress['failed'] ?? 0) + ($errorCount - $lastFailed);
        $progress['skipped'] = ($progress['skipped'] ?? 0) + ($skippedCount - $lastSkipped);
        $progress['percentage'] = min(round(($totalProcessed / $total) * 100), 99); // Cap at 99% until complete
        $progress['status'] = 'processing';
        
        // Store current counts for this chunk
        $progress['last_success_' . $this->chunkIndex] = $successCount;
        $progress['last_failed_' . $this->chunkIndex] = $errorCount;
        $progress['last_skipped_' . $this->chunkIndex] = $skippedCount;
        
        Cache::put($this->importId, $progress, 600);
    }
}
