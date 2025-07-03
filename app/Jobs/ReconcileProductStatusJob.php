<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\FeedWebsite;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReconcileProductStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes timeout for this long-running job
    public int $tries = 3;
    public int $backoff = 300; // 5 minute backoff between retries

    protected int $importRunId;
    protected int $connectionId;
    protected array $draftProductIds = [];

    /**
     * Create a new job instance.
     *
     * @param int $importRunId
     * @param int $connectionId
     * @param array $draftProductIds Optional array of draft product IDs to reconcile
     */
    public function __construct(int $importRunId, int $connectionId, array $draftProductIds = [])
    {
        $this->importRunId = $importRunId;
        $this->connectionId = $connectionId;
        $this->draftProductIds = $draftProductIds;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info("Starting ReconcileProductStatusJob for ImportRun #{$this->importRunId}");
        
        try {
            $importRun = ImportRun::findOrFail($this->importRunId);
            $connection = FeedWebsite::findOrFail($this->connectionId);
            $apiClient = new WooCommerceApiClient($connection->website);
            
            // Check if we have cached draft product IDs for this import run
            $cacheKey = "draft_products:{$this->importRunId}";
            $cachedDraftIds = Cache::get($cacheKey, []);
            
            if (!empty($cachedDraftIds)) {
                Log::info("Found " . count($cachedDraftIds) . " cached draft products to reconcile for ImportRun #{$this->importRunId}");
                $this->draftProductIds = array_merge($this->draftProductIds, $cachedDraftIds);
                
                // Clear the cache since we're processing these now
                Cache::forget($cacheKey);
            }
            
            // If no specific draft product IDs were provided or found in cache, fetch from WooCommerce
            if (empty($this->draftProductIds)) {
                Log::info("Fetching draft products for ImportRun #{$this->importRunId}");
                
                // Fetch draft products from WooCommerce
                $draftProducts = $this->fetchDraftProducts($apiClient, $importRun);
                
                if (empty($draftProducts)) {
                    Log::info("No draft products found for ImportRun #{$this->importRunId}");
                    
                    // Update the import run with reconciliation results
                    $importRun->update([
                        'reconciled_at' => now(),
                        'reconciled_records' => 0
                    ]);
                    
                    return;
                }
                
                $this->draftProductIds = array_column($draftProducts, 'id');
            }
            
            $draftCount = count($this->draftProductIds);
            Log::info("Reconciling {$draftCount} draft products for ImportRun #{$this->importRunId}");
            
            // Get the recommended batch size - use a more conservative size for reconciliation
            $batchSize = $this->getRecommendedBatchSize($connection->website_id);
            
            // Process in small batches for better reliability
            $batches = array_chunk($this->draftProductIds, $batchSize);
            
            $successCount = 0;
            $failureCount = 0;
            
            foreach ($batches as $batchIndex => $batch) {
                Log::info("Processing reconciliation batch #{$batchIndex} with " . count($batch) . " products");
                
                try {
                    // Add a longer delay between batches for reconciliation to be extra cautious
                    if ($batchIndex > 0) {
                        $delaySeconds = min(30, 10 + $batchIndex * 5); // Progressive delay up to 30 seconds
                        Log::info("Waiting {$delaySeconds} seconds before processing next reconciliation batch");
                        sleep($delaySeconds);
                    }
                    
                    // Create the batch update payload
                    $updateBatch = [];
                    foreach ($batch as $productId) {
                        $updateBatch[] = [
                            'id' => $productId,
                            'status' => 'publish'
                        ];
                    }
                    
                    // Try to publish the products
                    $response = $apiClient->batchProducts(['update' => $updateBatch]);
                    $updatedProducts = $response['update'] ?? [];
                    $successCount += count($updatedProducts);
                    
                    // Check for failed updates
                    if (count($updatedProducts) < count($batch)) {
                        // Some updates failed - find which ones
                        $updatedIds = array_column($updatedProducts, 'id');
                        $failedIds = array_diff($batch, $updatedIds);
                        
                        Log::warning("Failed to publish " . count($failedIds) . " products in reconciliation batch #{$batchIndex}");
                        $failureCount += count($failedIds);
                        
                        // Reduce batch size for future reconciliation attempts
                        if (count($failedIds) > 0) {
                            $this->reduceRecommendedBatchSize($connection->website_id);
                        }
                        
                        // For products that consistently fail, consider deleting them
                        if ($this->attempts() >= 2) {
                            $this->cleanupFailedProducts($apiClient, $failedIds, $importRun);
                        }
                    }
                    
                } catch (Throwable $e) {
                    Log::error("Error reconciling batch #{$batchIndex}: " . $e->getMessage());
                    $failureCount += count($batch);
                    
                    // Reduce batch size due to error
                    $this->reduceRecommendedBatchSize($connection->website_id);
                    
                    // For the last retry attempt, clean up products that we can't publish
                    if ($this->attempts() >= $this->tries) {
                        $this->cleanupFailedProducts($apiClient, $batch, $importRun);
                    }
                }
            }
            
            // Update the import run with reconciliation results
            $importRun->update([
                'reconciled_at' => now(),
                'reconciled_records' => $successCount,
                'removed_records' => DB::raw('removed_records + ' . $failureCount),
                'log_messages' => DB::raw("CONCAT(IFNULL(log_messages, ''), '\nReconciliation completed: {$successCount} products published, {$failureCount} failed/removed.')")
            ]);
            
            Log::info("ReconcileProductStatusJob completed for ImportRun #{$this->importRunId}: {$successCount} products published, {$failureCount} failed");
            
        } catch (Throwable $e) {
            Log::error("ReconcileProductStatusJob failed for ImportRun #{$this->importRunId}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get the recommended batch size for a specific website
     */
    protected function getRecommendedBatchSize(int $websiteId): int
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        // Use a more conservative batch size for reconciliation (about 40% of regular batch size)
        $regularBatchSize = Cache::get($cacheKey, 25);
        $reconciliationBatchSize = max(5, (int)($regularBatchSize * 0.4));
        
        return $reconciliationBatchSize;
    }
    
    /**
     * Reduce the recommended batch size for a website due to errors
     */
    protected function reduceRecommendedBatchSize(int $websiteId): void
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        $currentSize = Cache::get($cacheKey, 25);
        
        // Reduce by 30% but never below 5
        $newSize = max(5, (int)($currentSize * 0.7));
        
        if ($newSize < $currentSize) {
            Cache::put($cacheKey, $newSize, 60 * 24); // Store for 24 hours
            Log::warning("Reduced recommended batch size for website #{$websiteId} from {$currentSize} to {$newSize} due to reconciliation errors");
        }
    }
    
    /**
     * Fetch draft products from WooCommerce that are related to this import run
     */
    protected function fetchDraftProducts(WooCommerceApiClient $apiClient, ImportRun $importRun): array
    {
        // We can use a tag or meta field to identify products from this specific import run
        // For example, we could add a meta field in ProcessChunkJob like 'import_run_id'
        
        // For now, we'll fetch all draft products (limit 100) created around the time of the import run
        // In a production system, you'd want to use more precise filtering
        $startDate = $importRun->created_at->subMinutes(10)->toIso8601String();
        $endDate = now()->toIso8601String();
        
        // Get all recent draft products
        $products = $apiClient->makeRequest('products', [
            'status' => 'draft',
            'after' => $startDate,
            'before' => $endDate,
            'per_page' => 100
        ]);
        
        return $products;
    }
    
    /**
     * Clean up products that consistently fail to be published
     */
    protected function cleanupFailedProducts(WooCommerceApiClient $apiClient, array $productIds, ImportRun $importRun): void
    {
        if (empty($productIds)) {
            return;
        }
        
        Log::warning("Cleaning up " . count($productIds) . " failed products that couldn't be published");
        
        try {
            // Process deletion in small batches to avoid overwhelming the server
            $batches = array_chunk($productIds, 10);
            
            $totalRemoved = 0;
            
            foreach ($batches as $index => $batch) {
                try {
                    // Add delay between batches
                    if ($index > 0) {
                        sleep(5);
                    }
                    
                    // Delete the products
                    $response = $apiClient->batchProducts(['delete' => $batch]);
                    $deletedCount = count($response['delete'] ?? []);
                    $totalRemoved += $deletedCount;
                    
                    Log::info("Deleted {$deletedCount} failed products in batch #{$index}");
                    
                } catch (Throwable $e) {
                    Log::error("Error deleting batch #{$index} of failed products: " . $e->getMessage());
                }
            }
            
            // Update the import run with cleanup information
            $importRun->update([
                'removed_records' => DB::raw('removed_records + ' . $totalRemoved),
                'log_messages' => DB::raw("CONCAT(IFNULL(log_messages, ''), '\nRemoved {$totalRemoved} products that could not be published.')")
            ]);
            
        } catch (Throwable $e) {
            Log::error("Failed to clean up failed products: " . $e->getMessage());
        }
    }
}
