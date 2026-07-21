<?php

namespace App\Jobs;

use App\Models\BankAccount;
use App\Models\CashCollateral;
use App\Models\User;
use App\Services\CashCollateralDepositService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BulkCashCollateralDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 2;

    /** @var array<int, array<string, mixed>> */
    protected array $chunkData;

    protected int $userId;

    protected int $bankAccountId;

    protected string $depositDate;

    protected string $notes;

    protected int $chunkIndex;

    protected int $totalChunks;

    protected ?string $importId;

    /**
     * @param  array<int, array<string, mixed>>  $chunkData
     */
    public function __construct(
        array $chunkData,
        int $userId,
        int $bankAccountId,
        string $depositDate,
        string $notes,
        int $chunkIndex = 0,
        int $totalChunks = 1,
        ?string $importId = null
    ) {
        $this->chunkData = $chunkData;
        $this->userId = $userId;
        $this->bankAccountId = $bankAccountId;
        $this->depositDate = $depositDate;
        $this->notes = $notes;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
        $this->importId = $importId;
    }

    public function handle(CashCollateralDepositService $depositService): void
    {
        $user = User::findOrFail($this->userId);
        $bankAccount = BankAccount::findOrFail($this->bankAccountId);

        Log::info('Bulk cash collateral deposit chunk started', [
            'import_id' => $this->importId,
            'chunk_index' => $this->chunkIndex,
            'chunk_size' => count($this->chunkData),
        ]);

        foreach ($this->chunkData as $rowIndex => $row) {
            try {
                $collateral = CashCollateral::with(['customer', 'type'])->find($row['collateral_id'] ?? null);
                if (! $collateral) {
                    throw new \RuntimeException('Deposit account not found.');
                }

                if ((int) $collateral->branch_id !== (int) $user->branch_id
                    || (int) $collateral->company_id !== (int) $user->company_id) {
                    throw new \RuntimeException('Deposit account is outside your branch.');
                }

                $amount = (float) ($row['amount'] ?? 0);
                if ($amount <= 0) {
                    throw new \RuntimeException('Amount must be greater than zero.');
                }

                $depositService->processDeposit(
                    $collateral,
                    $bankAccount,
                    $amount,
                    $this->depositDate,
                    $this->notes,
                    $user,
                    false
                );

                $this->touchProgress(1, 0);
            } catch (\Throwable $e) {
                $this->recordFailure(
                    $rowIndex,
                    (string) ($row['customer_no'] ?? 'Unknown'),
                    $e->getMessage()
                );
            }
        }

        if ($this->importId && ($this->chunkIndex + 1) >= $this->totalChunks) {
            $this->finalizeImport();
        }

        Log::info('Bulk cash collateral deposit chunk completed', [
            'import_id' => $this->importId,
            'chunk_index' => $this->chunkIndex,
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
            $errors[] = [
                'row' => $rowIndex + 1,
                'customer_no' => $customerNo,
                'message' => $message,
            ];
            $progress['errors'] = $errors;
            Cache::put($this->importId, $progress, 7200);
        }
    }

    protected function finalizeImport(): void
    {
        $progress = Cache::get($this->importId, []);
        $progress['status'] = 'completed';
        $progress['percentage'] = 100;
        $progress['current'] = $progress['total'] ?? $progress['current'] ?? 0;
        Cache::put($this->importId, $progress, 7200);
    }
}
