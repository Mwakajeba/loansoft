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
        // Check if password column already exists
        if (Schema::hasColumn('customers', 'password')) {
            // If it exists, just make it nullable
            Schema::table('customers', function (Blueprint $table) {
                $table->string('password')->nullable()->change();
            });
        } else {
            // If it doesn't exist, add it after sex column
            Schema::table('customers', function (Blueprint $table) {
                $table->string('password')->nullable()->after('sex');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
