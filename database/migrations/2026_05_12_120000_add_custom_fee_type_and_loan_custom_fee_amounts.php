<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE fees MODIFY COLUMN fee_type ENUM('fixed', 'percentage', 'range', 'custom') NOT NULL DEFAULT 'fixed'");

        Schema::table('loans', function (Blueprint $table) {
            $table->json('custom_fee_amounts')->nullable()->after('amount_total');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('custom_fee_amounts');
        });

        DB::statement("ALTER TABLE fees MODIFY COLUMN fee_type ENUM('fixed', 'percentage', 'range') NOT NULL DEFAULT 'fixed'");
    }
};
