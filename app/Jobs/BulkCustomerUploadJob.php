<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Region;
use App\Models\District;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class BulkCustomerUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $chunkData;
    protected $userId;
    protected $branchId;
    protected $companyId;
    protected $hasCashCollateral;
    protected $collateralTypeId;
    protected $chunkIndex;
    protected $totalChunks;

    /**
     * Create a new job instance.
     */
    public function __construct($chunkData, $userId, $branchId, $companyId, $hasCashCollateral = false, $collateralTypeId = null, $chunkIndex = 0, $totalChunks = 1)
    {
        $this->chunkData = $chunkData;
        $this->userId = $userId;
        $this->branchId = $branchId;
        $this->companyId = $companyId;
        $this->hasCashCollateral = $hasCashCollateral;
        $this->collateralTypeId = $collateralTypeId;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing bulk customer upload chunk', [
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'chunk_size' => count($this->chunkData),
            'user_id' => $this->userId
        ]);

        $successCount = 0;
        $errorCount = 0;

        foreach ($this->chunkData as $rowData) {
            try {
                DB::beginTransaction();

                // Validate required fields
                if (
                    empty($rowData['name']) || empty($rowData['phone1']) || empty($rowData['dob']) ||
                    empty($rowData['sex'])
                ) {
                    throw new \Exception("Missing required fields");
                }

                // Validate sex
                if (!in_array(strtoupper($rowData['sex']), ['M', 'F'])) {
                    throw new \Exception("Sex must be M or F");
                }

                // Handle region and district - convert names to IDs if provided
                $regionId = null;
                $districtId = null;

                if (!empty($rowData['region_id'])) {
                    if (is_numeric($rowData['region_id'])) {
                        $regionId = $rowData['region_id'];
                    } else {
                        $region = Region::where('name', trim($rowData['region_id']))->first();
                        $regionId = $region ? $region->id : null;
                    }
                }

                if (!empty($rowData['district_id'])) {
                    if (is_numeric($rowData['district_id'])) {
                        $districtId = $rowData['district_id'];
                    } else {
                        $district = District::where('name', trim($rowData['district_id']))->first();
                        $districtId = $district ? $district->id : null;
                    }
                }

                // Format phone number
                $phone1 = $this->formatPhoneNumber(trim($rowData['phone1']));
                $phone2 = !empty($rowData['phone2']) ? $this->formatPhoneNumber(trim($rowData['phone2'])) : null;

                // Determine category (Borrower/Guarantor) from file, default to Borrower
                $rawCategory = trim($rowData['category'] ?? '');
                $normalizedCategory = strtolower($rawCategory);
                $category = in_array($normalizedCategory, ['borrower', 'guarantor'], true)
                    ? ucfirst($normalizedCategory)
                    : 'Borrower';

                // Create customer data
                $customerData = [
                    'name' => trim($rowData['name']),
                    'phone1' => $phone1,
                    'phone2' => $phone2,
                    'dob' => $rowData['dob'],
                    'sex' => strtoupper($rowData['sex']),
                    'region_id' => $regionId,
                    'district_id' => $districtId,
                    'work' => trim($rowData['work'] ?? ''),
                    'workAddress' => trim($rowData['workaddress'] ?? $rowData['workAddress'] ?? ''),
                    'idType' => trim($rowData['idtype'] ?? $rowData['idType'] ?? ''),
                    'idNumber' => trim($rowData['idnumber'] ?? $rowData['idNumber'] ?? ''),
                    'relation' => trim($rowData['relation'] ?? ''),
                    'description' => trim($rowData['description'] ?? ''),
                    'customerNo' => 100000 + (Customer::max('id') ?? 0) + 1,
                    'password' => Hash::make('1234567890'),
                    'branch_id' => $this->branchId,
                    'company_id' => $this->companyId,
                    'registrar' => $this->userId,
                    'dateRegistered' => now()->toDateString(),
                    'has_cash_collateral' => $this->hasCashCollateral,
                    'category' => $category,
                ];

                $customer = Customer::create($customerData);

                // Add cash collateral if selected
                if ($this->hasCashCollateral && $this->collateralTypeId) {
                    \App\Models\CashCollateral::create([
                        'customer_id' => $customer->id,
                        'type_id' => $this->collateralTypeId,
                        'amount' => 0,
                        'branch_id' => $this->branchId,
                        'company_id' => $this->companyId,
                    ]);
                }

                // Assign to individual group if not already in a group
                $existingMembership = DB::table('group_members')->where('customer_id', $customer->id)->first();
                if (!$existingMembership) {
                    DB::table('group_members')->insert([
                        'group_id' => 1,
                        'customer_id' => $customer->id,
                        'status' => 'active',
                        'joined_date' => now()->toDateString(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::commit();
                $successCount++;
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to create customer in bulk upload', [
                    'row_data' => $rowData,
                    'error' => $e->getMessage()
                ]);
                $errorCount++;
            }
        }

        Log::info('Completed bulk customer upload chunk', [
            'chunk_index' => $this->chunkIndex,
            'success_count' => $successCount,
            'error_count' => $errorCount
        ]);
    }

    /**
     * Format phone number to standard format - always starts with 255
     */
    private function formatPhoneNumber($phoneNumber)
    {
        if (empty($phoneNumber)) {
            return $phoneNumber;
        }

        // Remove any spaces, dashes, or special characters except +
        $phoneNumber = preg_replace("/[^0-9+]/", "", $phoneNumber);

        // If starts with 0, remove 0 and add 255
        if (substr($phoneNumber, 0, 1) === "0") {
            return "255" . substr($phoneNumber, 1);
        }

        // If starts with +255, remove +
        if (substr($phoneNumber, 0, 4) === "+255") {
            return substr($phoneNumber, 1);
        }

        // If doesn't start with 255, add 255
        if (substr($phoneNumber, 0, 3) !== "255") {
            return "255" . $phoneNumber;
        }

        return $phoneNumber;
    }
}
