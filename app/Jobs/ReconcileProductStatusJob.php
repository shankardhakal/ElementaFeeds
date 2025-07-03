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
            
            // If no specific draft product IDs were provided, fetch all draft products from the import run
            if (empty($this->draftProductIds)) {
                Log::info("Fetching draft products for ImportRun #{$this->importRunId}");
                
                // Fetch draft products from WooCommerce
                // We can filter by specific criteria related to our import run, such as a tag or meta field
                $draftProducts = $this->fetchDraftProducts($apiClient, $importRun);
                
                if (empty($draftProducts)) {
                    Log::info("No draft products found for ImportRun #{$this->importRunId}");
                    return;
                }
                
                $this->draftProductIds = array_column($draftProducts, 'id');
            }
            
            Log::info("Reconciling " . count($this->draftProductIds) . " draft products for ImportRun #{$this->importRunId}");
            
            // Process in batches of 25 for better reliability
            $batches = array_chunk($this->draftProductIds, 25);
            
            $successCount = 0;
            $failureCount = 0;
            
            foreach ($batches as $batchIndex => $batch) {
                Log::info("Processing reconciliation batch #{$batchIndex} with " . count($batch) . " products");
                
                try {
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
                    $successCount += count($response['update'] ?? []);
                    
                    // Check for failed updates
                    if (isset($response['update']) && count($response['update']) < count($batch)) {
                        // Some updates failed - find which ones
                        $updatedIds = array_column($response['update'], 'id');
                        $failedIds = array_diff($batch, $updatedIds);
                        
                        Log::warning("Failed to publish " . count($failedIds) . " products in reconciliation batch #{$batchIndex}");
                        $failureCount += count($failedIds);
                        
                        // For products that consistently fail, consider deleting them
                        if ($this->attempts() >= 2) {
                            $this->cleanupFailedProducts($apiClient, $failedIds, $importRun);
                        }
                    }
                    
                    // Add a delay between batches to reduce server load
                    if ($batchIndex < count($batches) - 1) {
                        sleep(5);
                    }
                    
                } catch (Throwable $e) {
                    Log::error("Error reconciling batch #{$batchIndex}: " . $e->getMessage());
                    $failureCount += count($batch);
                    
                    // For the last retry attempt, clean up products that we can't publish
                    if ($this->attempts() >= $this->tries) {
                        $this->cleanupFailedProducts($apiClient, $batch, $importRun);
                    }
                }
            }
            
            // Update the import run with reconciliation results
            $importRun->update([
                'log_messages' => DB::raw("CONCAT(IFNULL(log_messages, ''), '\nReconciliation completed: {$successCount} products published, {$failureCount} failed.')")
            ]);
            
            Log::info("ReconcileProductStatusJob completed for ImportRun #{$this->importRunId}: {$successCount} products published, {$failureCount} failed");
            
        } catch (Throwable $e) {
            Log::error("ReconcileProductStatusJob failed for ImportRun #{$this->importRunId}: " . $e->getMessage());
            throw $e;
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
            // We can either set them to a special status (like 'private') or delete them
            // Here we'll choose to delete them to keep the catalog clean
            $apiClient->batchProducts(['delete' => $productIds]);
            
            // Update the import run with cleanup information
            $importRun->update([
                'log_messages' => DB::raw("CONCAT(IFNULL(log_messages, ''), '\nRemoved " . count($productIds) . " products that could not be published.')")
            ]);
            
        } catch (Throwable $e) {
            Log::error("Failed to clean up failed products: " . $e->getMessage());
        }
    }
}
