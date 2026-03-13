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
        Schema::table('job_logs', function (Blueprint $table) {
            // Store structured details about what the job processed (e.g. backup type)
            $table->json('result_details')->nullable()->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_logs', function (Blueprint $table) {
            if (Schema::hasColumn('job_logs', 'result_details')) {
                $table->dropColumn('result_details');
            }
        });
    }
};

