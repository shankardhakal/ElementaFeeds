<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, drop the existing overly restrictive constraint using raw SQL
        DB::statement('ALTER TABLE import_runs DROP INDEX import_runs_conn_status_unique');
        
        // Since MySQL doesn't support partial unique indexes with WHERE clauses,
        // we'll create a regular unique index on (feed_website_id, status) but
        // handle the logic in the application code to only enforce it for "processing" status
        
        // For now, let's not add any constraint and rely on application-level locking
        // The Cache::lock() in StartImportRunJob is sufficient for preventing concurrent runs
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the old constraint
        DB::statement('ALTER TABLE import_runs ADD CONSTRAINT import_runs_conn_status_unique UNIQUE (feed_website_id, status)');
    }
};
