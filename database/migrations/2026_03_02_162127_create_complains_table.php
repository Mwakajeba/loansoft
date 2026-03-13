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
        if (Schema::hasTable('complains')) {
            Schema::dropIfExists('complains');
        }
        
        Schema::create('complains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('complain_category_id')->constrained('complain_categories')->onDelete('restrict');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->text('description');
            $table->enum('status', ['pending', 'resolved', 'closed'])->default('pending');
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            // Indexes for better query performance
            $table->index('customer_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complains');
    }
};
