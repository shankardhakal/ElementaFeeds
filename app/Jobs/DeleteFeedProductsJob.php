<?php

namespace App\Jobs;

use App\Models\Feed;
use App\Models\FeedWebsite;
use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DeleteFeedProductsJob
 *
 * This job is responsible for deleting products from the destination platform (e.g., WooCommerce) that are no longer part of the feed.
 *
 * Key Tasks:
 * - Identifies products to be deleted based on the feed data.
 * - Sends delete requests to the destination platform's API.
 */
class DeleteFeedProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $connectionId;
    public ?int $cleanupRunId = null;

    /**
     * Create a new job instance.
     *
     * @param int $connectionId The feed_website connection ID
     * @param int|null $cleanupRunId Optional cleanup run ID for tracking
     */
    public function __construct(int $connectionId, ?int $cleanupRunId = null)
    {
        $this->connectionId = $connectionId;
        $this->cleanupRunId = $cleanupRunId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Update cleanup run status
        $this->updateCleanupRun('running', ['started_at' => now()]);

        // Check if this is a dry run
        $isDryRun = $this->isDryRun();

        try {
            $connection = FeedWebsite::with(['feed', 'website'])
                ->findOrFail($this->connectionId);

            $apiClient = new WooCommerceApiClient($connection->website);

            // Find all products with this connection ID using the new stateless approach
            $productsToDelete = $apiClient->findProductsByConnectionId($this->connectionId);

            if (empty($productsToDelete)) {
                Log::info("No products found for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}) to delete.");
                $this->updateCleanupRun('completed', [
                    'completed_at' => now(),
                    'products_found' => 0,
                    'products_processed' => 0
                ]);
                return;
            }

            $this->updateCleanupRun('running', ['products_found' => count($productsToDelete)]);

            if ($isDryRun) {
                Log::info("🧪 DRY RUN: Would delete " . count($productsToDelete) . " products for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");
            } else {
                Log::info("Found " . count($productsToDelete) . " products to delete for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");
            }

            // Delete products in batches
            $batchSize = $this->getRecommendedBatchSize($connection->website_id);
            $batches = array_chunk($productsToDelete, $batchSize);
            $totalProcessed = 0;
            $totalErrors = 0;

            foreach ($batches as $batchIndex => $batch) {
                // Check for cancellation
                if ($this->isCancelled()) {
                    Log::info("Cleanup job cancelled", [
                        'cleanup_run_id' => $this->cleanupRunId,
                        'processed' => $totalProcessed
                    ]);
                    $this->updateCleanupRun('cancelled', [
                        'completed_at' => now(),
                        'products_processed' => $totalProcessed
                    ]);
                    return;
                }

                try {
                    if ($isDryRun) {
                        Log::info("🧪 DRY RUN: Would delete batch #" . ($batchIndex + 1) . " with " . count($batch) . " products for connection #{$this->connectionId}");
                        // Simulate processing time for dry run
                        sleep(1);
                    } else {
                        Log::info("Deleting batch #" . ($batchIndex + 1) . " with " . count($batch) . " products for connection #{$this->connectionId}");
                        // The API expects an array of IDs for deletion.
                        $apiClient->batchProducts(['delete' => $batch]);
                    }

                    $totalProcessed += count($batch);
                    
                    if ($isDryRun) {
                        Log::info("🧪 DRY RUN: Would have deleted batch #" . ($batchIndex + 1) . " for connection #{$this->connectionId}");
                    } else {
                        Log::info("Successfully deleted batch #" . ($batchIndex + 1) . " for connection #{$this->connectionId}");
                    }
                    
                    // Update progress
                    $this->updateCleanupRun('running', [
                        'products_processed' => $totalProcessed,
                        'products_failed' => $totalErrors
                    ]);

                } catch (\Throwable $e) {
                    $totalErrors += count($batch);
                    Log::error("Failed to delete batch #" . ($batchIndex + 1) . " for connection #{$this->connectionId}: " . $e->getMessage());
                    // Continue with other batches instead of failing completely
                }
            }

            // Mark as completed
            if ($isDryRun) {
                Log::info("🧪 DRY RUN COMPLETED: Would have processed {$totalProcessed} products for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");
            } else {
                Log::info("✅ CLEANUP COMPLETED: Successfully processed {$totalProcessed} products for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");
            }

            $this->updateCleanupRun('completed', [
                'completed_at' => now(),
                'products_processed' => $totalProcessed,
                'products_failed' => $totalErrors
            ]);

        } catch (\Throwable $e) {
            Log::error("Failed to delete products for connection ID {$this->connectionId}: " . $e->getMessage());
            $this->updateCleanupRun('failed', [
                'completed_at' => now(),
                'error_summary' => $e->getMessage()
            ]);
            $this->fail($e);
        }
    }

    /**
     * Update cleanup run status
     */
    private function updateCleanupRun(string $status, array $data = []): void
    {
        if (!$this->cleanupRunId) {
            return;
        }

        $updateData = array_merge(['status' => $status, 'updated_at' => now()], $data);
        
        DB::table('connection_cleanup_runs')
            ->where('id', $this->cleanupRunId)
            ->update($updateData);
    }

    /**
     * Check if cleanup has been cancelled
     */
    private function isCancelled(): bool
    {
        if (!$this->cleanupRunId) {
            return false;
        }

        $run = DB::table('connection_cleanup_runs')
            ->where('id', $this->cleanupRunId)
            ->value('status');

        return $run === 'cancelled';
    }

    /**
     * Check if this is a dry run operation
     */
    private function isDryRun(): bool
    {
        if (!$this->cleanupRunId) {
            return false;
        }

        $isDryRun = DB::table('connection_cleanup_runs')
            ->where('id', $this->cleanupRunId)
            ->value('dry_run');

        return (bool) $isDryRun;
    }
    
    /**
     * Get the recommended batch size for a specific website
     */
    protected function getRecommendedBatchSize(int $websiteId): int
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        // A lower batch size for deletion might be safer.
        return Cache::get($cacheKey, 25);
    }
}
