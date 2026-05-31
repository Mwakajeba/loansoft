<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dcb_transactions', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('callback_payload');
        });
    }

    public function down(): void
    {
        Schema::table('dcb_transactions', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
