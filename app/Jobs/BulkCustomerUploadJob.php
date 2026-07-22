<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\District;
use App\Models\Region;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class BulkCustomerUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    /** @var array<int, array<string, mixed>> */
    protected array $chunkData;

    protected int $userId;

    protected int $branchId;

    protected int $companyId;

    protected bool $hasCashCollateral;

    protected ?int $collateralTypeId;

    protected int $chunkIndex;

    protected int $totalChunks;

    protected ?string $importId;

    protected int $startingCustomerNo;

    protected string $passwordHash;

    /**
     * @param  array<int, array<string, mixed>>  $chunkData
     */
    public function __construct(
        array $chunkData,
        int $userId,
        int $branchId,
        int $companyId,
        bool $hasCashCollateral = false,
        ?int $collateralTypeId = null,
        int $chunkIndex = 0,
        int $totalChunks = 1,
        ?string $importId = null,
        int $startingCustomerNo = 0,
        string $passwordHash = ''
    ) {
        $this->chunkData = $chunkData;
        $this->userId = $userId;
        $this->branchId = $branchId;
        $this->companyId = $companyId;
        $this->hasCashCollateral = $hasCashCollateral;
        $this->collateralTypeId = $collateralTypeId;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
        $this->importId = $importId;
        $this->startingCustomerNo = $startingCustomerNo;
        $this->passwordHash = $passwordHash !== '' ? $passwordHash : Hash::make('1234567890');
    }

    public function handle(): void
    {
        Log::info('Processing bulk customer upload chunk', [
            'import_id' => $this->importId,
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'chunk_size' => count($this->chunkData),
            'user_id' => $this->userId,
        ]);

        $regionCache = Region::query()->pluck('id', 'name')->mapWithKeys(
            fn ($id, $name) => [strtolower(trim((string) $name)) => (int) $id]
        )->all();
        $districtCache = District::query()->pluck('id', 'name')->mapWithKeys(
            fn ($id, $name) => [strtolower(trim((string) $name)) => (int) $id]
        )->all();

        $successCount = 0;
        $errorCount = 0;
        $nextCustomerNo = $this->startingCustomerNo > 0
            ? $this->startingCustomerNo
            : (100000 + (int) (Customer::max('id') ?? 0) + 1);
        // Offset by fixed chunk size (50) so parallel chunks do not collide even when the last chunk is smaller
        $nextCustomerNo += $this->chunkIndex * 50;

        $today = now()->toDateString();
        $now = now();

        foreach ($this->chunkData as $rowOffset => $rowData) {
            $rowNumber = (int) ($rowData['_row_number'] ?? (($this->chunkIndex * 50) + $rowOffset + 2));

            try {
                if (
                    empty($rowData['name']) || empty($rowData['phone1']) || empty($rowData['dob'])
                    || empty($rowData['sex'])
                ) {
                    throw new \RuntimeException('Missing required fields (name, phone1, dob, sex)');
                }

                if (! in_array(strtoupper((string) $rowData['sex']), ['M', 'F'], true)) {
                    throw new \RuntimeException('Sex must be M or F');
                }

                $regionId = $this->resolveLookupId($rowData['region_id'] ?? null, $regionCache);
                $districtId = $this->resolveLookupId($rowData['district_id'] ?? null, $districtCache);

                $phone1 = $this->formatPhoneNumber(trim((string) $rowData['phone1']));
                $phone2 = ! empty($rowData['phone2'])
                    ? $this->formatPhoneNumber(trim((string) $rowData['phone2']))
                    : null;

                if ($phone1 === '') {
                    throw new \RuntimeException('Invalid phone1');
                }

                if (Customer::where('phone1', $phone1)->exists()) {
                    throw new \RuntimeException("Phone already exists: {$phone1}");
                }

                $rawCategory = trim((string) ($rowData['category'] ?? ''));
                $normalizedCategory = strtolower($rawCategory);
                $category = in_array($normalizedCategory, ['borrower', 'guarantor'], true)
                    ? ucfirst($normalizedCategory)
                    : 'Borrower';

                $customerNo = $nextCustomerNo++;

                Customer::withoutEvents(function () use (
                    $rowData,
                    $phone1,
                    $phone2,
                    $regionId,
                    $districtId,
                    $category,
                    $customerNo,
                    $today,
                    $now
                ) {
                    DB::transaction(function () use (
                        $rowData,
                        $phone1,
                        $phone2,
                        $regionId,
                        $districtId,
                        $category,
                        $customerNo,
                        $today,
                        $now
                    ) {
                        $customer = Customer::create([
                            'name' => trim((string) $rowData['name']),
                            'phone1' => $phone1,
                            'phone2' => $phone2,
                            'dob' => $rowData['dob'],
                            'sex' => strtoupper((string) $rowData['sex']),
                            'region_id' => $regionId,
                            'district_id' => $districtId,
                            'work' => trim((string) ($rowData['work'] ?? '')),
                            'workAddress' => trim((string) ($rowData['workaddress'] ?? $rowData['workAddress'] ?? '')),
                            'idType' => trim((string) ($rowData['idtype'] ?? $rowData['idType'] ?? '')),
                            'idNumber' => trim((string) ($rowData['idnumber'] ?? $rowData['idNumber'] ?? '')),
                            'relation' => trim((string) ($rowData['relation'] ?? '')),
                            'description' => trim((string) ($rowData['description'] ?? '')),
                            'customerNo' => $customerNo,
                            'password' => $this->passwordHash,
                            'branch_id' => $this->branchId,
                            'company_id' => $this->companyId,
                            'registrar' => $this->userId,
                            'dateRegistered' => $today,
                            'has_cash_collateral' => $this->hasCashCollateral,
                            'category' => $category,
                        ]);

                        if ($this->hasCashCollateral && $this->collateralTypeId) {
                            \App\Models\CashCollateral::create([
                                'customer_id' => $customer->id,
                                'type_id' => $this->collateralTypeId,
                                'amount' => 0,
                                'branch_id' => $this->branchId,
                                'company_id' => $this->companyId,
                            ]);
                        }

                        DB::table('group_members')->insert([
                            'group_id' => 1,
                            'customer_id' => $customer->id,
                            'status' => 'active',
                            'joined_date' => $today,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    });
                });

                $successCount++;
                $this->bumpProgress(1, 0);
            } catch (\Throwable $e) {
                $errorCount++;
                $this->bumpProgress(0, 1);
                $this->appendError($rowNumber, trim((string) ($rowData['name'] ?? '')), $e->getMessage());
                Log::warning('Failed to create customer in bulk upload', [
                    'import_id' => $this->importId,
                    'row' => $rowNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($this->chunkIndex === $this->totalChunks - 1) {
            $this->markCompleted();
        }

        Log::info('Completed bulk customer upload chunk', [
            'import_id' => $this->importId,
            'chunk_index' => $this->chunkIndex,
            'success_count' => $successCount,
            'error_count' => $errorCount,
        ]);
    }

    /**
     * @param  array<string, int>  $cache
     */
    private function resolveLookupId(mixed $value, array $cache): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $key = strtolower(trim((string) $value));

        return $cache[$key] ?? null;
    }

    private function bumpProgress(int $successDelta, int $failedDelta): void
    {
        if (! $this->importId) {
            return;
        }

        $progress = Cache::get($this->importId, []);
        $progress['success'] = (int) ($progress['success'] ?? 0) + $successDelta;
        $progress['failed'] = (int) ($progress['failed'] ?? 0) + $failedDelta;
        $progress['current'] = (int) ($progress['current'] ?? 0) + $successDelta + $failedDelta;
        $total = max(1, (int) ($progress['total'] ?? 1));
        if ($progress['current'] >= $total) {
            $progress['percentage'] = 100;
            $progress['status'] = 'completed';
        } else {
            $progress['percentage'] = min(99, (int) round(($progress['current'] / $total) * 100));
            $progress['status'] = 'processing';
        }
        Cache::put($this->importId, $progress, 7200);
    }

    private function appendError(int $row, string $name, string $message): void
    {
        if (! $this->importId) {
            return;
        }

        $progress = Cache::get($this->importId, []);
        $errors = $progress['errors'] ?? [];
        if (count($errors) < 50) {
            $errors[] = [
                'row' => $row,
                'name' => $name,
                'message' => $message,
            ];
            $progress['errors'] = $errors;
            Cache::put($this->importId, $progress, 7200);
        }
    }

    private function markCompleted(): void
    {
        if (! $this->importId) {
            return;
        }

        // Wait briefly for sibling chunks on async queues
        $deadline = microtime(true) + 15;
        do {
            $progress = Cache::get($this->importId, []);
            $current = (int) ($progress['current'] ?? 0);
            $total = (int) ($progress['total'] ?? 0);
            if ($total > 0 && $current >= $total) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        $progress = Cache::get($this->importId, []);
        $progress['status'] = 'completed';
        $progress['percentage'] = 100;
        $progress['current'] = $progress['total'] ?? $progress['current'] ?? 0;
        Cache::put($this->importId, $progress, 7200);
    }

    private function formatPhoneNumber($phoneNumber)
    {
        if (empty($phoneNumber)) {
            return $phoneNumber;
        }

        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        if (substr($phoneNumber, 0, 1) === '0') {
            return '255'.substr($phoneNumber, 1);
        }

        if (substr($phoneNumber, 0, 4) === '+255') {
            return substr($phoneNumber, 1);
        }

        if (substr($phoneNumber, 0, 3) !== '255') {
            return '255'.$phoneNumber;
        }

        return $phoneNumber;
    }
}
