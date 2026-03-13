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
        Schema::table('penalties', function (Blueprint $table) {
            $table->enum('frequency_cycle', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'])->nullable()->after('charge_frequency');
            $table->integer('penalty_limit_days')->nullable()->after('frequency_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn(['frequency_cycle', 'penalty_limit_days']);
        });
    }
};
