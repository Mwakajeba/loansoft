<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Note: base table is named 'loan_schedules'
        Schema::table('loan_schedules', function (Blueprint $table) {
            // Accrued interest per schedule (for daily accrual tracking)
            $table->decimal('accrued_interest', 15, 2)->default(0)->after('penalty_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_schedules', function (Blueprint $table) {
            $table->dropColumn('accrued_interest');
        });
    }
};
