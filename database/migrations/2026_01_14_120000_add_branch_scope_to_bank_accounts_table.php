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
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('bank_accounts', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')
                    ->nullable()
                    ->comment('When set, this bank account is scoped to a specific branch');

                $table->foreign('branch_id')
                    ->references('id')
                    ->on('branches')
                    ->onDelete('set null');
            }

            if (!Schema::hasColumn('bank_accounts', 'is_all_branches')) {
                $table->boolean('is_all_branches')
                    ->default(true)
                    ->after('branch_id')
                    ->comment('If true, bank account is available for all branches');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('bank_accounts', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }

            if (Schema::hasColumn('bank_accounts', 'is_all_branches')) {
                $table->dropColumn('is_all_branches');
            }
        });
    }
};

