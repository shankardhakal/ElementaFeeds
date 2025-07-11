<?php

namespace App\Jobs;

use App\Services\Api\WooCommerceApiClient;
use App\Models\FeedWebsite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ProcessStaleProductCleanup Job
 *
 * This job implements the stateless cleanup of stale products by:
 * - Querying WooCommerce directly for products with stale timestamps
 * - Processing them in batches according to the configured action (draft, delete, etc.)
 * - Operating independently without relying on local tracking tables
 *
 * Designed to run during off-peak hours to minimize impact on live operations.
 */
class ProcessStaleProductCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // 1 minute backoff between retries
    public int $timeout = 1800; // 30 minutes timeout

    public int $connectionId;
    public int $cutoffTimestamp;
    public string $action;

    /**
     * Create a new job instance.
     *
     * @param int $connectionId The feed connection ID
     * @param int $cutoffTimestamp Products with last_seen older than this are considered stale
     * @param string $action The action to take (delete)
     */
    public function __construct(int $connectionId, int $cutoffTimestamp, string $action)
    {
        $this->connectionId = $connectionId;
        $this->cutoffTimestamp = $cutoffTimestamp;
        $this->action = $action;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $connection = FeedWebsite::with(['website', 'feed'])->findOrFail($this->connectionId);
            
            Log::info("Starting stale product cleanup", [
                'connection_id' => $this->connectionId,
                'feed_name' => $connection->feed->name,
                'website_name' => $connection->website->name,
                'cutoff_timestamp' => $this->cutoffTimestamp,
                'cutoff_date' => date('Y-m-d H:i:s', $this->cutoffTimestamp),
                'action' => $this->action
            ]);

            $apiClient = new WooCommerceApiClient($connection->website);

            // Find stale products using the new API method
            $staleProducts = $apiClient->findStaleProducts($this->connectionId, $this->cutoffTimestamp);

            if (empty($staleProducts)) {
                Log::info("No stale products found for connection #{$this->connectionId}");
                return;
            }

            Log::info("Found {count} stale products for connection #{$this->connectionId}", [
                'count' => count($staleProducts),
                'connection_id' => $this->connectionId,
                'action' => $this->action
            ]);

            // Process products in batches
            $batchSize = 50; // Conservative batch size for cleanup operations
            $batches = array_chunk($staleProducts, $batchSize);
            $totalProcessed = 0;
            $totalErrors = 0;

            foreach ($batches as $batchIndex => $batch) {
                try {
                    Log::info("Processing stale product batch #{$batchIndex} with {count} products", [
                        'batch_index' => $batchIndex,
                        'count' => count($batch),
                        'connection_id' => $this->connectionId
                    ]);

                    $result = $this->processBatch($batch, $apiClient);
                    
                    $totalProcessed += $result['processed'];
                    $totalErrors += $result['errors'];

                    Log::info("Batch #{$batchIndex} completed: {processed} processed, {errors} errors", [
                        'batch_index' => $batchIndex,
                        'processed' => $result['processed'],
                        'errors' => $result['errors']
                    ]);

                } catch (Throwable $e) {
                    Log::error("Failed to process stale product batch #{$batchIndex}", [
                        'batch_index' => $batchIndex,
                        'connection_id' => $this->connectionId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $totalErrors += count($batch);
                }
            }

            Log::info("Stale product cleanup completed for connection #{$this->connectionId}", [
                'connection_id' => $this->connectionId,
                'total_found' => count($staleProducts),
                'total_processed' => $totalProcessed,
                'total_errors' => $totalErrors,
                'action' => $this->action
            ]);

        } catch (Throwable $e) {
            Log::error("Stale product cleanup failed for connection #{$this->connectionId}", [
                'connection_id' => $this->connectionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process a batch of stale products according to the configured action
     *
     * @param array $products Array of product IDs or product data
     * @param WooCommerceApiClient $apiClient
     * @return array ['processed' => int, 'errors' => int]
     */
    protected function processBatch(array $products, WooCommerceApiClient $apiClient): array
    {
        $processed = 0;
        $errors = 0;

        try {
            switch ($this->action) {
                case 'delete':
                    $result = $this->deleteProducts($products, $apiClient);
                    break;
                default:
                    Log::warning("Unknown stale product action: {$this->action}");
                    return ['processed' => 0, 'errors' => count($products)];
            }

            $processed = $result['processed'] ?? 0;
            $errors = $result['errors'] ?? 0;

        } catch (Throwable $e) {
            Log::error("Batch processing failed for action '{$this->action}'", [
                'action' => $this->action,
                'products_count' => count($products),
                'error' => $e->getMessage()
            ]);
            $errors = count($products);
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Delete stale products
     */
    protected function deleteProducts(array $products, WooCommerceApiClient $apiClient): array
    {
        $productIds = collect($products)->map(function ($product) {
            return is_array($product) ? $product['id'] : $product;
        })->all();

        $deleteData = ['delete' => $productIds];
        $result = $apiClient->batchProducts($deleteData);
        
        return $this->processBatchResult($result, 'delete');
    }

    /**
     * Process the result from a batch operation
     */
    protected function processBatchResult(array $result, string $operation): array
    {
        $processed = 0;
        $errors = 0;

        // Check results for different operations
        $resultKey = $operation === 'delete' ? 'delete' : 'update';
        
        if (isset($result[$resultKey]) && is_array($result[$resultKey])) {
            foreach ($result[$resultKey] as $item) {
                if (isset($item['error'])) {
                    $errors++;
                    Log::warning("Failed to {$operation} product", [
                        'product_id' => $item['id'] ?? 'unknown',
                        'error' => $item['error']['message'] ?? 'Unknown error'
                    ]);
                } else {
                    $processed++;
                }
            }
        } else {
            Log::warning("Unexpected batch result format for {$operation}", [
                'result' => $result
            ]);
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error("ProcessStaleProductCleanup job failed permanently", [
            'connection_id' => $this->connectionId,
            'cutoff_timestamp' => $this->cutoffTimestamp,
            'action' => $this->action,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
