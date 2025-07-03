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
        Schema::table('import_runs', function (Blueprint $table) {
            // Change the 'status' column to a string to accommodate longer status names.
            // The default length of 255 characters is more than enough.
            $table->string('status')->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            // Revert the 'status' column back to its likely original ENUM state.
            // This is a best-effort reversal based on the previous context.
            $table->enum('status', ['processing', 'completed', 'failed'])->change();
        });
    }
};
