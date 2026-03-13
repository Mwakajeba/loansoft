<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the existing enum column
        DB::statement("ALTER TABLE loan_schedules MODIFY COLUMN status ENUM('active', 'cancelled', 'paid', 'restructured') DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum values
        DB::statement("ALTER TABLE loan_schedules MODIFY COLUMN status ENUM('active', 'cancelled', 'paid') DEFAULT 'active'");
    }
};
