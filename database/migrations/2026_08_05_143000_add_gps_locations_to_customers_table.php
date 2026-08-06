<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('home_latitude', 10, 7)->nullable()->after('workAddress');
            $table->decimal('home_longitude', 10, 7)->nullable()->after('home_latitude');
            $table->decimal('home_location_accuracy', 10, 2)->nullable()->after('home_longitude');
            $table->timestamp('home_location_captured_at')->nullable()->after('home_location_accuracy');
            $table->foreignId('home_location_captured_by')
                ->nullable()
                ->after('home_location_captured_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('business_latitude', 10, 7)->nullable()->after('home_location_captured_by');
            $table->decimal('business_longitude', 10, 7)->nullable()->after('business_latitude');
            $table->decimal('business_location_accuracy', 10, 2)->nullable()->after('business_longitude');
            $table->timestamp('business_location_captured_at')->nullable()->after('business_location_accuracy');
            $table->foreignId('business_location_captured_by')
                ->nullable()
                ->after('business_location_captured_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_location_captured_by');
            $table->dropColumn([
                'business_location_captured_at',
                'business_location_accuracy',
                'business_longitude',
                'business_latitude',
            ]);

            $table->dropConstrainedForeignId('home_location_captured_by');
            $table->dropColumn([
                'home_location_captured_at',
                'home_location_accuracy',
                'home_longitude',
                'home_latitude',
            ]);
        });
    }
};
