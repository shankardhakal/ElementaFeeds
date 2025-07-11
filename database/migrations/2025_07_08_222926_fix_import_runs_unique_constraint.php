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
        // Check if we're using SQLite or MySQL and handle appropriately
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // For SQLite, we need to recreate the table without the constraint
            // First, check if the constraint exists
            $hasConstraint = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name='import_runs_conn_status_unique'");
            
            if (!empty($hasConstraint)) {
                // Drop the index in SQLite
                DB::statement('DROP INDEX import_runs_conn_status_unique');
            }
        } else {
            // For MySQL, drop the constraint
            try {
                DB::statement('ALTER TABLE import_runs DROP INDEX import_runs_conn_status_unique');
            } catch (\Exception $e) {
                // Index might not exist, that's fine
            }
        }
        
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
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // For SQLite, create the index
            DB::statement('CREATE UNIQUE INDEX import_runs_conn_status_unique ON import_runs (feed_website_id, status)');
        } else {
            // For MySQL, add the constraint
            DB::statement('ALTER TABLE import_runs ADD CONSTRAINT import_runs_conn_status_unique UNIQUE (feed_website_id, status)');
        }
    }
};
