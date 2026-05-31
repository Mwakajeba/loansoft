<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcb_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->default('transfer');
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('client_reference', 32)->unique();
            $table->string('destination_account', 64);
            $table->string('institution_code', 64);
            $table->string('institution_name')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('beneficiary_name', 120);
            $table->string('msisdn', 20);
            $table->string('sender_name', 120);
            $table->string('purpose', 32)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('transfer_reference')->nullable();
            $table->string('response_code', 32)->nullable();
            $table->text('message')->nullable();
            $table->json('gateway_response')->nullable();
            $table->json('callback_payload')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcb_transactions');
    }
};
