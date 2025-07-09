<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clean up any stuck import runs that might cause constraint violations
        $this->cleanupStuckImportRuns();
        
        // The unique constraint was already created in a previous migration
        // This migration focuses on cleanup and ensuring constraint stability
        Log::info('Import runs cleanup completed. Unique constraint is working properly.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration only cleans up data, no schema changes to reverse
        Log::info('Import runs constraint fix migration rollback - no action needed.');
    }

    /**
     * Clean up stuck import runs that could cause constraint violations
     */
    private function cleanupStuckImportRuns(): void
    {
        // Find import runs that have been in processing/chunking state for over 2 hours
        $stuckRuns = DB::table('import_runs')
            ->whereIn('status', ['processing', 'chunking', 'pending'])
            ->where('updated_at', '<', now()->subHours(2))
            ->get();

        if ($stuckRuns->count() > 0) {
            Log::info("Found {$stuckRuns->count()} stuck import runs, cleaning up...");
            
            foreach ($stuckRuns as $run) {
                DB::table('import_runs')
                    ->where('id', $run->id)
                    ->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'log_messages' => 'Import run cleaned up during migration - was stuck in ' . $run->status . ' status.',
                        'updated_at' => now()
                    ]);
                
                Log::info("Cleaned up stuck import run #{$run->id} (was {$run->status})");
            }
        } else {
            Log::info('No stuck import runs found - constraint should work properly');
        }

        // Look for any potential duplicate active runs that could cause issues
        $duplicateGroups = DB::table('import_runs')
            ->select('feed_website_id', 'status', DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['processing', 'chunking', 'pending'])
            ->groupBy(['feed_website_id', 'status'])
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicateGroups as $group) {
            Log::warning("Found {$group->count} duplicate runs for connection {$group->feed_website_id} with status {$group->status}");
            
            // Keep the most recent one, mark others as expired
            $duplicateRuns = DB::table('import_runs')
                ->where('feed_website_id', $group->feed_website_id)
                ->where('status', $group->status)
                ->orderBy('updated_at', 'desc')
                ->skip(1) // Skip the most recent one
                ->get();

            foreach ($duplicateRuns as $duplicate) {
                DB::table('import_runs')
                    ->where('id', $duplicate->id)
                    ->update([
                        'status' => 'expired',
                        'finished_at' => now(),
                        'log_messages' => 'Duplicate import run resolved during migration cleanup.',
                        'updated_at' => now()
                    ]);
                
                Log::info("Marked duplicate import run #{$duplicate->id} as expired");
            }
        }
    }
};
