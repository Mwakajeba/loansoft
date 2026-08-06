<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loan_bills')) {
            return;
        }

        Schema::table('loan_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_bills', 'bill_date')) {
                $table->date('bill_date')->nullable()->after('description');
            }

            if (! Schema::hasColumn('loan_bills', 'receivable_account_id')) {
                $table->foreignId('receivable_account_id')
                    ->nullable()
                    ->after('bill_date')
                    ->constrained('chart_accounts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('loan_bills', 'income_account_id')) {
                $table->foreignId('income_account_id')
                    ->nullable()
                    ->after('receivable_account_id')
                    ->constrained('chart_accounts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('loan_bills')) {
            return;
        }

        Schema::table('loan_bills', function (Blueprint $table) {
            if (Schema::hasColumn('loan_bills', 'income_account_id')) {
                $table->dropConstrainedForeignId('income_account_id');
            }

            if (Schema::hasColumn('loan_bills', 'receivable_account_id')) {
                $table->dropConstrainedForeignId('receivable_account_id');
            }

            if (Schema::hasColumn('loan_bills', 'bill_date')) {
                $table->dropColumn('bill_date');
            }
        });
    }
};
