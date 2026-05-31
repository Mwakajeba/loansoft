<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add columns that exist in create_receipts migration but may be missing on older DBs.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('receipts', 'customer_id')) {
                $table->foreignId('customer_id')
                    ->nullable()
                    ->after('payee_name')
                    ->constrained('customers')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('receipts', 'supplier_id')) {
                $table->foreignId('supplier_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('suppliers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            if (Schema::hasColumn('receipts', 'supplier_id')) {
                $table->dropForeign(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
            if (Schema::hasColumn('receipts', 'customer_id')) {
                $table->dropForeign(['customer_id']);
                $table->dropColumn('customer_id');
            }
        });
    }
};
