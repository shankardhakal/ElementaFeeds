<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStaleProductCleanup;
use App\Models\FeedWebsite;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupFeedProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feed:cleanup 
                            {feed_website_id : The ID of the feed website connection to clean up}
                            {--force : Force cleanup without confirmation}
                            {--dry-run : Show what would be cleaned without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale products from a specific feed connection using stateless approach';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $feedWebsiteId = (int) $this->argument('feed_website_id');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        // Validate feed website exists
        $feedWebsite = FeedWebsite::with(['feed', 'website'])->find($feedWebsiteId);
        if (!$feedWebsite) {
            $this->error("Feed website connection #{$feedWebsiteId} not found.");
            return 1;
        }

        $this->info("🔄 Stateless Feed Cleanup");
        $this->info("========================");
        
        // Show connection details
        $feedName = $feedWebsite->feed->name ?? 'Unknown Feed';
        $websiteUrl = $feedWebsite->website->url ?? 'Unknown Website';
        
        $this->line("Connection: {$feedWebsite->name}");
        $this->line("Feed: {$feedName}");
        $this->line("Website: {$websiteUrl}");

        // Check stale product settings
        $updateSettings = $feedWebsite->update_settings ?? [];
        $staleAction = $updateSettings['stale_action'] ?? null;
        $staleDays = $updateSettings['stale_days'] ?? null;

        if (!$staleAction || !$staleDays) {
            $this->error("❌ No stale product settings configured for this connection.");
            $this->line("Please configure stale product settings in the admin panel first.");
            return 1;
        }

        $this->line("Action: {$staleAction}");
        $this->line("Threshold: {$staleDays} days");
        
        if ($dryRun) {
            $this->warn("🧪 DRY RUN MODE - No changes will be made");
        }

        if (!$force && !$dryRun && !$this->confirm('Are you sure you want to proceed with this cleanup?')) {
            $this->info('Cleanup cancelled.');
            return 0;
        }

        try {
            $this->info('🚀 Dispatching stateless cleanup job...');
            
            // Use the existing stateless cleanup job
            ProcessStaleProductCleanup::dispatch($feedWebsiteId, $dryRun);
            
            $this->info('✅ Cleanup job dispatched successfully!');
            $this->line('Monitor the job progress in the logs.');

        } catch (\Exception $e) {
            $this->error('❌ Cleanup failed: ' . $e->getMessage());
            Log::error('Stateless feed cleanup command failed', [
                'feed_website_id' => $feedWebsiteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }
}
