<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Change loan_officer foreign key from CASCADE to SET NULL
     * so that groups are not deleted when a user is deleted.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            // First, make the column nullable if it isn't
            $table->unsignedBigInteger('loan_officer')->nullable()->change();
        });

        Schema::table('groups', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['loan_officer']);
        });

        Schema::table('groups', function (Blueprint $table) {
            // Re-add the foreign key with SET NULL on delete
            $table->foreign('loan_officer')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            // Drop the SET NULL foreign key
            $table->dropForeign(['loan_officer']);
        });

        Schema::table('groups', function (Blueprint $table) {
            // Re-add the foreign key with CASCADE on delete (original behavior)
            $table->foreign('loan_officer')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        Schema::table('groups', function (Blueprint $table) {
            // Make the column non-nullable again
            $table->unsignedBigInteger('loan_officer')->nullable(false)->change();
        });
    }
};
