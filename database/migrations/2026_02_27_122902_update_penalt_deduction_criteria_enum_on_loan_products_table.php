<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Change ENUM values for penalt_deduction_criteria to support new options
        DB::statement("ALTER TABLE `loan_products` MODIFY `penalt_deduction_criteria` ENUM('daily_bases','full_amount','daily','as_expected_interest') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert ENUM to original values
        DB::statement("ALTER TABLE `loan_products` MODIFY `penalt_deduction_criteria` ENUM('daily_bases','full_amount') NULL");
    }
};
