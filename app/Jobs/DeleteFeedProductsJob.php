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

            // Validate API connection before proceeding (especially important for dry runs)
            if (!$this->validateApiConnection($apiClient, $isDryRun)) {
                $this->updateCleanupRun('failed', [
                    'completed_at' => now(),
                    'error_summary' => 'API connection validation failed'
                ]);
                return;
            }

            // Use streaming approach to avoid memory issues
            $totalProcessed = 0;
            $totalErrors = 0;
            $totalFound = 0;
            $batchSize = $this->getRecommendedBatchSize($connection->website_id);

            // Process products in pages to avoid memory issues
            $this->processProductsInPages($apiClient, $connection, $isDryRun, $batchSize, $totalProcessed, $totalErrors, $totalFound);

            // Mark as completed
            if ($isDryRun) {
                Log::info("🧪 DRY RUN COMPLETED: Would have processed {$totalProcessed} products for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");
            } else {
                Log::info("✅ CLEANUP COMPLETED: Successfully processed {$totalProcessed} products for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");
            }

            $this->updateCleanupRun('completed', [
                'completed_at' => now(),
                'products_found' => $totalFound,
                'products_processed' => $totalProcessed,
                'products_failed' => $totalErrors
            ]);

        } catch (\Throwable $e) {
            Log::error("Failed to delete products for connection ID {$this->connectionId}: " . $e->getMessage());
            $this->updateCleanupRun('failed', [
                'completed_at' => now(),
                'error_summary' => substr($e->getMessage(), 0, 1000) // Limit error message length
            ]);
            $this->fail($e);
        }
    }

    /**
     * Process products in paginated chunks to avoid memory issues
     */
    private function processProductsInPages(WooCommerceApiClient $apiClient, $connection, bool $isDryRun, int $batchSize, int &$totalProcessed, int &$totalErrors, int &$totalFound): void
    {
        $page = 1;
        $perPage = min($batchSize, 100); // Limit page size
        $consecutiveEmptyPages = 0;
        
        do {
            // Check for cancellation at the start of each page
            if ($this->isCancelled()) {
                Log::info("Cleanup job cancelled during page processing", [
                    'cleanup_run_id' => $this->cleanupRunId,
                    'page' => $page,
                    'processed' => $totalProcessed
                ]);
                $this->updateCleanupRun('cancelled', [
                    'completed_at' => now(),
                    'products_found' => $totalFound,
                    'products_processed' => $totalProcessed,
                    'products_failed' => $totalErrors
                ]);
                return;
            }

            try {
                // Get products for this page using the paginated method
                $productsPage = $apiClient->findProductsByConnectionIdPaginated($this->connectionId, $page, $perPage);
                
                if (empty($productsPage)) {
                    $consecutiveEmptyPages++;
                    if ($consecutiveEmptyPages >= 3) {
                        Log::info("No more products found after {$consecutiveEmptyPages} empty pages, stopping pagination");
                        break;
                    }
                    $page++;
                    continue;
                }
                
                $consecutiveEmptyPages = 0;
                $totalFound += count($productsPage);
                
                // Update progress
                $this->updateCleanupRun('running', [
                    'products_found' => $totalFound
                ]);

                if ($isDryRun) {
                    Log::info("🧪 DRY RUN: Would delete " . count($productsPage) . " products on page {$page} for connection #{$this->connectionId}");
                    $totalProcessed += count($productsPage);
                } else {
                    Log::info("Processing " . count($productsPage) . " products on page {$page} for connection #{$this->connectionId}");
                    
                    // Process this page in smaller batches
                    $batches = array_chunk($productsPage, $batchSize);
                    
                    foreach ($batches as $batchIndex => $batch) {
                        // Check for cancellation within batch processing
                        if ($this->isCancelled()) {
                            Log::info("Cleanup job cancelled during batch processing", [
                                'cleanup_run_id' => $this->cleanupRunId,
                                'page' => $page,
                                'batch' => $batchIndex,
                                'processed' => $totalProcessed
                            ]);
                            $this->updateCleanupRun('cancelled', [
                                'completed_at' => now(),
                                'products_found' => $totalFound,
                                'products_processed' => $totalProcessed,
                                'products_failed' => $totalErrors
                            ]);
                            return;
                        }

                        try {
                            // Add rate limiting between batches
                            if ($batchIndex > 0) {
                                usleep(250000); // 250ms delay between batches
                            }
                            
                            $startTime = microtime(true);
                            
                            Log::info("Deleting batch " . ($batchIndex + 1) . " of " . count($batches) . " with " . count($batch) . " products for connection #{$this->connectionId}");
                            
                            // The API expects an array of IDs for deletion
                            $apiClient->batchProducts(['delete' => $batch]);
                            
                            $processingTime = microtime(true) - $startTime;
                            $totalProcessed += count($batch);
                            
                            Log::info("Successfully deleted batch " . ($batchIndex + 1) . " for connection #{$this->connectionId} in {$processingTime}s");
                            
                            // Update batch size based on performance
                            $this->updateBatchSize($connection->website_id, count($batch), true, $processingTime);
                            
                            // Update progress more frequently
                            $this->updateCleanupRun('running', [
                                'products_processed' => $totalProcessed,
                                'products_failed' => $totalErrors
                            ]);

                        } catch (\Throwable $e) {
                            $processingTime = microtime(true) - ($startTime ?? microtime(true));
                            $totalErrors += count($batch);
                            Log::error("Failed to delete batch " . ($batchIndex + 1) . " on page {$page} for connection #{$this->connectionId}: " . $e->getMessage());
                            
                            // Update batch size based on failure
                            $this->updateBatchSize($connection->website_id, count($batch), false, $processingTime);
                            
                            // Continue with other batches, but implement circuit breaker
                            if ($totalErrors > ($totalFound * 0.5)) {
                                Log::error("Too many failures ({$totalErrors}/{$totalFound}), stopping cleanup for connection #{$this->connectionId}");
                                throw new \Exception("Cleanup stopped due to high failure rate: {$totalErrors}/{$totalFound} products failed");
                            }
                        }
                    }
                }
                
                $page++;
                
                // Add delay between pages to prevent overwhelming the API
                if ($page % 5 == 0) {
                    sleep(1); // 1 second delay every 5 pages
                }
                
            } catch (\Throwable $e) {
                Log::error("Failed to process page {$page} for connection #{$this->connectionId}: " . $e->getMessage());
                
                // If we can't get the page, increment error count and try next page
                $totalErrors += $perPage; // Estimate error count
                $page++;
                
                // Circuit breaker - stop if too many page failures
                if ($page > 10 && $totalErrors > ($totalFound * 0.3)) {
                    throw new \Exception("Too many page processing failures, stopping cleanup");
                }
            }
            
        } while ($page <= 500); // Safety limit to prevent infinite loops
    }

    /**
     * Update cleanup run status with transaction safety
     */
    private function updateCleanupRun(string $status, array $data = []): void
    {
        if (!$this->cleanupRunId) {
            return;
        }

        $updateData = array_merge(['status' => $status, 'updated_at' => now()], $data);
        
        try {
            DB::beginTransaction();
            
            $affected = DB::table('connection_cleanup_runs')
                ->where('id', $this->cleanupRunId)
                ->update($updateData);
                
            if ($affected === 0) {
                Log::warning("No cleanup run updated", [
                    'cleanup_run_id' => $this->cleanupRunId,
                    'status' => $status,
                    'data' => $data
                ]);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update cleanup run status", [
                'cleanup_run_id' => $this->cleanupRunId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            // Don't throw here as it would stop the main job
        }
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
     * Validate API connection before proceeding with cleanup
     */
    private function validateApiConnection(WooCommerceApiClient $apiClient, bool $isDryRun): bool
    {
        try {
            Log::info("Validating API connection for connection #{$this->connectionId}" . ($isDryRun ? " (dry run)" : ""));
            
            // Try to fetch products to validate connection using the correct method name
            $testProducts = $apiClient->findProductsByConnectionId($this->connectionId);
            
            if ($isDryRun) {
                Log::info("🧪 DRY RUN: API connection validated successfully for connection #{$this->connectionId}");
            } else {
                Log::info("API connection validated successfully for connection #{$this->connectionId}");
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("API connection validation failed for connection #{$this->connectionId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the recommended batch size for a specific website with adaptive sizing
     */
    protected function getRecommendedBatchSize(int $websiteId): int
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        $defaultBatchSize = 20; // Conservative default for deletion
        
        $cachedSize = Cache::get($cacheKey, $defaultBatchSize);
        
        // Ensure batch size is within reasonable bounds
        return max(5, min(50, $cachedSize));
    }

    /**
     * Update batch size based on performance metrics
     */
    private function updateBatchSize(int $websiteId, int $currentBatchSize, bool $success, float $processingTime): void
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        
        // If successful and fast, we can increase batch size
        if ($success && $processingTime < 5.0) {
            $newBatchSize = min(50, $currentBatchSize + 2);
        }
        // If failed or slow, decrease batch size
        elseif (!$success || $processingTime > 15.0) {
            $newBatchSize = max(5, $currentBatchSize - 3);
        }
        // Otherwise keep current size
        else {
            $newBatchSize = $currentBatchSize;
        }
        
        if ($newBatchSize !== $currentBatchSize) {
            Cache::put($cacheKey, $newBatchSize, now()->addHours(24));
            Log::info("Updated batch size for website {$websiteId} from {$currentBatchSize} to {$newBatchSize}");
        }
    }
}
