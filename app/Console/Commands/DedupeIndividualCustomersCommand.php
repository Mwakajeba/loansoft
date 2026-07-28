<?php

namespace App\Console\Commands;

use App\Models\CashCollateral;
use App\Models\Customer;
use App\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DedupeIndividualCustomersCommand extends Command
{
    protected $signature = 'customers:dedupe-individual
                            {--dry-run : List duplicate individual customers without deleting}
                            {--force : Delete without confirmation prompt}
                            {--name= : Only process duplicates for this exact customer name (case-insensitive)}
                            {--keep=oldest : Which record to keep: oldest|newest}';

    protected $description = 'Find duplicate customer names in the Individual group, delete extras, and remove their cash collateral accounts';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $nameFilter = $this->option('name');
        $keep = strtolower((string) $this->option('keep'));

        if (! in_array($keep, ['oldest', 'newest'], true)) {
            $this->error('Invalid --keep value. Use oldest or newest.');

            return self::FAILURE;
        }

        $individualGroupId = Group::getIndividualGroupId();

        $customers = $this->individualCustomersQuery($individualGroupId)
            ->orderBy('id')
            ->get(['id', 'customerNo', 'name', 'phone1', 'branch_id', 'created_at']);

        if ($nameFilter) {
            $needle = $this->normalizeName($nameFilter);
            $customers = $customers->filter(
                fn (Customer $customer) => $this->normalizeName($customer->name) === $needle
            )->values();
        }

        $duplicateGroups = $customers
            ->groupBy(fn (Customer $customer) => $this->normalizeName($customer->name))
            ->filter(fn (Collection $group, string $name) => $name !== '' && $group->count() > 1);

        if ($duplicateGroups->isEmpty()) {
            $this->info('No duplicate individual customer names found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Found ' . $duplicateGroups->count() . ' duplicate name group(s).');

        $plannedDeletes = collect();
        $skipped = collect();

        foreach ($duplicateGroups as $normalizedName => $group) {
            $sorted = $keep === 'newest'
                ? $group->sortByDesc('id')->values()
                : $group->sortBy('id')->values();

            $keeper = $sorted->first();
            $duplicates = $sorted->slice(1);

            $this->newLine();
            $this->line("Name: <info>{$group->first()->name}</info> ({$group->count()} records)");
            $this->line("  Keep: #{$keeper->id} {$keeper->customerNo} {$keeper->phone1}");

            foreach ($duplicates as $duplicate) {
                $reason = $this->skipReason($duplicate);
                if ($reason) {
                    $skipped->push([
                        'customer' => $duplicate,
                        'reason' => $reason,
                    ]);
                    $this->warn("  Skip delete #{$duplicate->id} {$duplicate->customerNo}: {$reason}");

                    continue;
                }

                $collateralCount = CashCollateral::where('customer_id', $duplicate->id)->count();
                $collateralBalance = (float) CashCollateral::where('customer_id', $duplicate->id)->sum('amount');

                $plannedDeletes->push([
                    'customer' => $duplicate,
                    'collateral_count' => $collateralCount,
                    'collateral_balance' => $collateralBalance,
                ]);

                $this->line(sprintf(
                    '  Delete: #%d %s %s | cash collaterals: %d (balance %.2f)',
                    $duplicate->id,
                    $duplicate->customerNo,
                    $duplicate->phone1,
                    $collateralCount,
                    $collateralBalance
                ));
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Duplicate name groups', $duplicateGroups->count()],
                ['Customers to delete', $plannedDeletes->count()],
                ['Skipped', $skipped->count()],
                ['Cash collateral accounts to delete', $plannedDeletes->sum('collateral_count')],
            ]
        );

        if ($plannedDeletes->isEmpty()) {
            $this->warn('Nothing to delete.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Run without --dry-run to delete the listed duplicate customers and their cash collateral accounts.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Delete the duplicate individual customers listed above?', false)) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $deletedCustomers = 0;
        $deletedCollaterals = 0;

        DB::beginTransaction();

        try {
            foreach ($plannedDeletes as $item) {
                /** @var Customer $customer */
                $customer = $item['customer'];

                $deletedCollaterals += CashCollateral::where('customer_id', $customer->id)->delete();

                DB::table('customer_file_types')->where('customer_id', $customer->id)->delete();
                DB::table('customer_officer')->where('customer_id', $customer->id)->delete();
                DB::table('group_members')->where('customer_id', $customer->id)->delete();

                $customer->delete();
                $deletedCustomers++;
            }

            DB::commit();

            $this->info("Deleted {$deletedCustomers} duplicate customer(s) and {$deletedCollaterals} cash collateral account(s).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Delete failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function individualCustomersQuery(int $individualGroupId)
    {
        return Customer::query()
            ->whereExists(function ($query) use ($individualGroupId) {
                $query->select(DB::raw(1))
                    ->from('group_members')
                    ->whereColumn('group_members.customer_id', 'customers.id')
                    ->where('group_members.group_id', $individualGroupId);
            })
            ->whereNotExists(function ($query) use ($individualGroupId) {
                $query->select(DB::raw(1))
                    ->from('group_members as other_group_members')
                    ->whereColumn('other_group_members.customer_id', 'customers.id')
                    ->where('other_group_members.group_id', '!=', $individualGroupId);
            });
    }

    private function normalizeName(?string $name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower($name);
    }

    private function skipReason(Customer $customer): ?string
    {
        if ($customer->loans()->exists()) {
            return 'has loans';
        }

        if (DB::table('gl_transactions')->where('customer_id', $customer->id)->exists()) {
            return 'has GL transactions';
        }

        if (Group::where('group_leader', $customer->id)->exists()) {
            return 'is a group leader';
        }

        if ($customer->repayments()->exists()) {
            return 'has repayments';
        }

        return null;
    }
}
