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
        Schema::create('filetype_loan_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->foreignId('filetype_id')->constrained('filetypes')->onDelete('cascade');
            $table->timestamps();
            
            // Unique constraint to prevent duplicate entries
            $table->unique(['loan_product_id', 'filetype_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('filetype_loan_product');
    }
};
