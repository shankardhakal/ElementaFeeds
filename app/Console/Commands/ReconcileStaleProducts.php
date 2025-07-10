<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStaleProductCleanup;
use App\Models\FeedWebsite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ReconcileStaleProducts Command
 *
 * This command implements the stateless reconciliation architecture by:
 * - Finding products that haven't been "seen" (tagged with _elementa_last_seen_timestamp) 
 *   within the configured stale threshold for each feed connection
 * - Dispatching cleanup jobs to handle stale products according to user-defined rules
 * - Operating completely independently of the live import pipeline
 *
 * Designed to run during off-peak hours via cron to minimize impact on live operations.
 */
class ReconcileStaleProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elementa:reconcile-stale-products 
                           {--connection-id= : Process only a specific feed connection ID}
                           {--dry-run : Show what would be processed without making changes}
                           {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and clean up stale products using stateless timestamp-based reconciliation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Starting stateless product reconciliation...');

        $connectionId = $this->option('connection-id');
        $isDryRun = $this->option('dry-run');
        $isForced = $this->option('force');

        // Get active feed connections with stale product handling enabled
        $query = FeedWebsite::with(['website', 'feed'])
            ->where('is_active', true)
            ->whereNotNull('update_settings');

        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        $connections = $query->get()->filter(function ($connection) {
            $settings = $connection->update_settings ?? [];
            $action = $settings['stale_action'] ?? null;
            $days = (int)($settings['stale_days'] ?? 0);
            
            // Only include connections with valid stale product settings
            return in_array($action, ['delete', 'set_stock_zero']) && $days > 0;
        });

        if ($connections->isEmpty()) {
            $this->warn('No active feed connections found with stale product handling enabled.');
            return 0;
        }

        $this->info("Found {$connections->count()} feed connection(s) to process:");
        
        foreach ($connections as $connection) {
            $settings = $connection->update_settings ?? [];
            $action = $settings['stale_action'] ?? 'none';
            $days = (int)($settings['stale_days'] ?? 0);
            
            $this->line("  • Connection #{$connection->id}: {$connection->feed->name} → {$connection->website->name}");
            $this->line("    Action: {$action}, Threshold: {$days} days");
        }

        if (!$isForced && !$isDryRun) {
            if (!$this->confirm('Do you want to proceed with reconciliation?')) {
                $this->info('Reconciliation cancelled.');
                return 0;
            }
        }

        $totalProcessed = 0;
        $totalJobsDispatched = 0;

        foreach ($connections as $connection) {
            try {
                $this->info("\n🔍 Processing connection #{$connection->id}: {$connection->feed->name}");

                $settings = $connection->update_settings ?? [];
                $action = $settings['stale_action'] ?? 'none';
                $staleDays = (int)($settings['stale_days'] ?? 7); // Default to 7 days
                $cutoffTimestamp = now()->subDays($staleDays)->timestamp;

                $this->line("  Cutoff timestamp: " . date('Y-m-d H:i:s', $cutoffTimestamp));
                $this->line("  Action: {$action}");

                if ($isDryRun) {
                    $this->line("  🔍 DRY RUN: Would dispatch stale product cleanup job");
                    $totalProcessed++;
                } else {
                    // Dispatch the cleanup job
                    ProcessStaleProductCleanup::dispatch(
                        $connection->id,
                        $cutoffTimestamp,
                        $action
                    );

                    $this->line("  ✅ Dispatched cleanup job for connection #{$connection->id}");
                    $totalJobsDispatched++;
                    $totalProcessed++;
                }

                Log::info("Reconciliation job dispatched for connection #{$connection->id}", [
                    'connection_id' => $connection->id,
                    'feed_name' => $connection->feed->name,
                    'website_name' => $connection->website->name,
                    'cutoff_timestamp' => $cutoffTimestamp,
                    'action' => $action,
                    'dry_run' => $isDryRun
                ]);

            } catch (\Throwable $e) {
                $this->error("  ❌ Failed to process connection #{$connection->id}: {$e->getMessage()}");
                Log::error("Failed to process stale products for connection #{$connection->id}", [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        if ($isDryRun) {
            $this->info("\n✅ Dry run completed. {$totalProcessed} connection(s) would be processed.");
        } else {
            $this->info("\n✅ Reconciliation completed. {$totalJobsDispatched} cleanup job(s) dispatched for {$totalProcessed} connection(s).");
        }

        return 0;
    }
}
