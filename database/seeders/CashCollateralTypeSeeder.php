<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CashCollateralTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cashCollateralTypes = [
            [
                'name' => 'Customer Operation Account',
                'chart_account_code' => '2010', // Customer operation account from ChartAccountSeeder
                'description' => 'Customer Operation Account performing various customer operations and transaction management',
                'is_active' => true,
            ],
        ];

        foreach ($cashCollateralTypes as $type) {
            $chartAccountCode = $type['chart_account_code'];
            unset($type['chart_account_code']);

            $chartAccount = DB::table('chart_accounts')->where('account_code', $chartAccountCode)->first();
            $type['chart_account_id'] = $chartAccount?->id;

            DB::table('cash_collateral_types')->updateOrInsert(
                ['name' => $type['name']],
                array_merge($type, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
