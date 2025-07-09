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

/**
 * ReconcileProductStatusJob
 *
 * This job reconciles the status of products between the feed and the destination platform.
 *
 * Key Tasks:
 * - Identifies discrepancies in product statuses.
 * - Updates the destination platform to match the feed data.
 * - Logs reconciliation results.
 */
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
            
            // Set the total number of draft records to be processed
            $importRun->update(['draft_records' => $draftCount]);

            // Get the recommended batch size - use a more conservative size for reconciliation
            $batchSize = $this->getRecommendedBatchSize($connection->website_id);
            
            // Process in small batches for better reliability
            $batches = array_chunk($this->draftProductIds, $batchSize);
            
            $successCount = 0;
            $failureCount = 0;
            
            foreach ($batches as $batchIndex => $batch) {
                Log::info("Processing reconciliation batch #{$batchIndex} with " . count($batch) . " products");
                
                try {
                    // Prepare the data for the batch update
                    $updateData = [
                        'update' => collect($batch)->map(function ($productId) {
                            return ['id' => $productId, 'status' => 'publish'];
                        })->all()
                    ];

                    // Send the batch update request to WooCommerce
                    $result = $apiClient->batchProducts($updateData);

                    // Check for errors in the response
                    if (isset($result['update']) && !empty($result['update'])) {
                        $successfulUpdates = 0;
                        $batchFailedIds = [];

                        foreach ($result['update'] as $productResult) {
                            if (isset($productResult['error'])) {
                                $batchFailedIds[] = $productResult['id'];
                                Log::error("Failed to publish product #{$productResult['id']}: " . $productResult['error']['message']);
                            } else {
                                $successfulUpdates++;
                            }
                        }
                        
                        $successCount += $successfulUpdates;
                        $failureCount += count($batchFailedIds);

                        if (!empty($batchFailedIds)) {
                            Log::warning("Batch #{$batchIndex} had " . count($batchFailedIds) . " failures during reconciliation.");
                        }
                    } else {
                        // If the entire batch request failed somehow
                        $failureCount += count($batch);
                        Log::error("Batch update failed for batch #{$batchIndex}. Response: " . json_encode($result));
                    }
                    
                } catch (Throwable $e) {
                    Log::error("Exception during reconciliation batch #{$batchIndex}: " . $e->getMessage());
                    $failureCount += count($batch);
                    $this->reduceRecommendedBatchSize($connection->website_id);
                }
            }
            
            // Update the import run with reconciliation results
            $importRun->update([
                'reconciled_at' => now(),
                'reconciled_records' => $successCount,
                'failed_records' => DB::raw('failed_records + ' . $failureCount),
                'log_messages' => DB::raw("CONCAT(IFNULL(log_messages, ''), '\nReconciliation completed: {$successCount} products published, {$failureCount} failed.')")
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
        $cacheKey = "reconciliation_batch_size:website:{$websiteId}";
        // Start with a conservative batch size for reconciliation.
        $reconciliationBatchSize = Cache::get($cacheKey, 10);
        
        return $reconciliationBatchSize;
    }
    
    /**
     * Reduce the recommended batch size for a website due to errors
     */
    protected function reduceRecommendedBatchSize(int $websiteId): void
    {
        $cacheKey = "reconciliation_batch_size:website:{$websiteId}";
        $currentSize = Cache::get($cacheKey, 10);
        
        // Reduce by 50% but never below 1
        $newSize = max(1, (int)($currentSize * 0.5));
        
        if ($newSize < $currentSize) {
            Cache::put($cacheKey, $newSize, 60 * 24); // Store for 24 hours
            Log::warning("Reduced recommended reconciliation batch size for website #{$websiteId} from {$currentSize} to {$newSize} due to reconciliation errors");
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
    
}
