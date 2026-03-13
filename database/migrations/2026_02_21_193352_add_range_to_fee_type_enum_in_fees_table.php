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
        // For MySQL, we need to modify the enum column
        DB::statement("ALTER TABLE fees MODIFY COLUMN fee_type ENUM('fixed', 'percentage', 'range') DEFAULT 'fixed'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'range' from enum
        DB::statement("ALTER TABLE fees MODIFY COLUMN fee_type ENUM('fixed', 'percentage') DEFAULT 'fixed'");
    }
};
