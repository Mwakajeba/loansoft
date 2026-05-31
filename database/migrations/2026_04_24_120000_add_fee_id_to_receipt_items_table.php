<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->foreignId('fee_id')->nullable()->after('receipt_id')->constrained('fees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_id');
        });
    }
};
