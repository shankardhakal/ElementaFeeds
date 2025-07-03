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
            // Check if the column already exists before trying to add it
            if (!Schema::hasColumn('import_runs', 'finished_at')) {
                $table->timestamp('finished_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });
    }
};
