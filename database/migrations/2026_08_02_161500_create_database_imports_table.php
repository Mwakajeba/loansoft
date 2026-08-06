<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mode', 32); // backup | replace
            $table->string('status', 32)->default('queued'); // queued|processing|completed|failed
            $table->text('message')->nullable();
            $table->unsignedBigInteger('backup_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_imports');
    }
};
