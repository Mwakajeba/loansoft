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
            if (!Schema::hasColumn('penalties', 'charge_frequency')) {
                $table->enum('charge_frequency', ['daily', 'one_time'])->default('one_time');
            }

            if (!Schema::hasColumn('penalties', 'frequency_cycle')) {
                $table->enum('frequency_cycle', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'])->nullable();
            }

            if (!Schema::hasColumn('penalties', 'penalty_limit_days')) {
                $table->integer('penalty_limit_days')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('penalties', 'frequency_cycle')) {
                $columns[] = 'frequency_cycle';
            }

            if (Schema::hasColumn('penalties', 'penalty_limit_days')) {
                $columns[] = 'penalty_limit_days';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
