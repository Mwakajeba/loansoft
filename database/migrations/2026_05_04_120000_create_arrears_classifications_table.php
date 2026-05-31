<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrears_classifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            $table->unsignedInteger('days_from')->default(0);
            $table->unsignedInteger('days_to')->nullable();
            $table->string('bucket_label');
            $table->string('status');
            $table->decimal('provision_percentage', 8, 2)->default(0);
            $table->text('comments')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrears_classifications');
    }
};

