<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupOldCleanupRuns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:old-runs
                          {--days=30 : Number of days to retain cleanup run records}
                          {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old connection cleanup run records to prevent database bloat';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = (int) $this->option('days');
        $isDryRun = $this->option('dry-run');
        
        $this->info("🧹 Connection Cleanup Run Retention");
        $this->info("=====================================");
        $this->line("Retention period: {$retentionDays} days");
        
        if ($isDryRun) {
            $this->warn("🧪 DRY RUN MODE - No changes will be made");
        }
        
        // Get cutoff date
        $cutoffDate = now()->subDays($retentionDays);
        $this->line("Cutoff date: {$cutoffDate->format('Y-m-d H:i:s')}");
        
        try {
            // Find old cleanup runs
            $oldRuns = DB::table('connection_cleanup_runs')
                ->where('created_at', '<', $cutoffDate)
                ->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->get();
            
            if ($oldRuns->isEmpty()) {
                $this->info("✅ No old cleanup runs found to delete.");
                return 0;
            }
            
            $this->info("Found {$oldRuns->count()} old cleanup runs to delete:");
            
            // Show summary by status
            $summary = $oldRuns->groupBy('status')->map(function($runs) {
                return $runs->count();
            });
            
            foreach ($summary as $status => $count) {
                $this->line("  • {$status}: {$count} runs");
            }
            
            // Show breakdown by connection
            $connectionSummary = $oldRuns->groupBy('connection_id')->map(function($runs) {
                return $runs->count();
            });
            
            $this->line("\nBreakdown by connection:");
            foreach ($connectionSummary as $connectionId => $count) {
                $this->line("  • Connection #{$connectionId}: {$count} runs");
            }
            
            if ($isDryRun) {
                $this->info("\n✅ Dry run completed. {$oldRuns->count()} cleanup runs would be deleted.");
                return 0;
            }
            
            if (!$this->confirm('Are you sure you want to delete these old cleanup runs?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
            
            // Delete old runs
            $deletedCount = DB::table('connection_cleanup_runs')
                ->where('created_at', '<', $cutoffDate)
                ->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->delete();
            
            $this->info("✅ Successfully deleted {$deletedCount} old cleanup runs.");
            
            Log::info("Cleanup run retention executed", [
                'retention_days' => $retentionDays,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
                'deleted_count' => $deletedCount
            ]);
            
        } catch (\Exception $e) {
            $this->error("❌ Cleanup failed: " . $e->getMessage());
            Log::error("Cleanup run retention failed", [
                'error' => $e->getMessage(),
                'retention_days' => $retentionDays
            ]);
            return 1;
        }
        
        return 0;
    }
}
