<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use App\Models\FeedWebsite;
use App\Models\ImportRun;
use App\Services\TransformationService;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessChunkJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable, SerializesModels, Batchable;
    
    public int $timeout = 1200; // 20 minutes (increased from 15)
    public int $tries = 5;
    public int $backoff = 120; // Wait 2 minutes between retry attempts (doubled from 60)

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public int $uniqueFor = 3600; // 1 hour

    public int $importRunId;
    public int $connectionId;
    public string $chunkFilePath;

    /**
     * @param int $importRunId
     * @param int $connectionId
     * @param string $chunkFilePath
     */
    public function __construct(int $importRunId, int $connectionId, string $chunkFilePath)
    {
        $this->importRunId = $importRunId;
        $this->connectionId = $connectionId;
        $this->chunkFilePath = $chunkFilePath;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // This applies the 'import-connection' rate limiter defined in AppServiceProvider.
        return [new RateLimited('import-connection')];
    }

    /**
     * The unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->importRunId . '-' . $this->chunkFilePath;
    }

    public function handle()
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        try {
            $importRun = ImportRun::findOrFail($this->importRunId);
            $connection = FeedWebsite::findOrFail($this->connectionId);
    
            // Check if chunk file exists and is readable before proceeding
            if (!file_exists($this->chunkFilePath) || !is_readable($this->chunkFilePath)) {
                Log::error("ProcessChunkJob failed: Chunk file not found or is not readable: {$this->chunkFilePath}");
                $importRun->increment('failed_records', 100); // Assuming ~100 records per chunk
                $this->fail(new \Exception("Chunk file not found or is not readable: {$this->chunkFilePath}"));
                return;
            }
            
            $products = $this->loadChunkProducts($this->chunkFilePath);
    
            if (empty($products)) {
                Log::info("No products to process in chunk {$this->chunkFilePath} for ImportRun #{$this->importRunId}.");
                return; // Nothing to process
            }
    
            Log::info("Processing chunk with " . count($products) . " products for ImportRun #{$this->importRunId}");
    
            $transformer = new TransformationService();
            $apiClient = new WooCommerceApiClient($connection->website);
    
            $draftsToCreate = [];
            $skippedCount = 0;
    
            // 1. Transform all products and collect valid ones to be created as drafts.
            foreach ($products as $rawProduct) {
                try {
                    $payload = $transformer->transform(
                        $rawProduct,
                        $connection->field_mappings,
                        $connection->category_mappings
                    );
        
                    if (!empty($payload)) {
                        $draftsToCreate[] = $payload;
                    } else {
                        $skippedCount++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to transform product in ImportRun #{$this->importRunId}: " . $e->getMessage(), [
                        'product' => $rawProduct,
                        'exception' => $e->getMessage()
                    ]);
                    $skippedCount++;
                }
            }
    
            if ($skippedCount > 0) {
                $importRun->increment('skipped_records', $skippedCount);
                Log::info("Skipped {$skippedCount} products in chunk for ImportRun #{$this->importRunId}");
            }
    
            if (empty($draftsToCreate)) {
                Log::info("No valid products to import in chunk for ImportRun #{$this->importRunId}");
                return; // No valid products to import
            }
    
            // Get the recommended batch size for this website
            $batchSize = $this->getRecommendedBatchSize($connection->website_id);
            Log::info("Using batch size of {$batchSize} for website ID {$connection->website_id}");
            
            // 2. Process in batches to respect API limits and reduce server load.
            $batches = array_chunk($draftsToCreate, $batchSize);
    
            foreach ($batches as $batchIndex => $batch) {
                try {
                    Log::info("Sending batch #{$batchIndex} with " . count($batch) . " products to WooCommerce API for ImportRun #{$this->importRunId}");
                    
                    // Add a delay between batches
                    if ($batchIndex > 0) {
                        $delaySeconds = min(10, 2 + $batchIndex); // Progressive delay up to 10 seconds
                        Log::info("Waiting {$delaySeconds} seconds before processing next batch");
                        sleep($delaySeconds);
                    }
                    
                    // PHASE 1: Create products as drafts
                    $draftBatch = $batch;
                    foreach ($draftBatch as &$product) {
                        $product['status'] = 'draft'; // Force draft status for initial creation
                    }
                    
                    $createdResponse = $apiClient->batchProducts(['create' => $draftBatch]);
                    $createdProducts = $createdResponse['create'] ?? [];
                    
                    if (empty($createdProducts)) {
                        Log::error("Failed to create any products in batch #{$batchIndex} for ImportRun #{$this->importRunId}");
                        $importRun->increment('failed_records', count($batch));
                        
                        // If this is a total failure, reduce the batch size for future jobs
                        $this->reduceRecommendedBatchSize($connection->website_id);
                        continue; // Skip to the next batch
                    }
                    
                    // Count successful creations
                    $createdCount = count($createdProducts);
                    $importRun->increment('created_records', $createdCount);
                    
                    // If partial success (some products failed), reduce batch size
                    if ($createdCount < count($batch)) {
                        $this->reduceRecommendedBatchSize($connection->website_id);
                    }
                    
                    Log::info("Successfully created {$createdCount} draft products for ImportRun #{$this->importRunId}");
                    
                    // PHASE 2: Update products to publish status (with smaller batch size and delay)
                    $productUpdates = [];
                    foreach ($createdProducts as $product) {
                        if (!isset($product['id'])) continue;
                        
                        $productUpdates[] = [
                            'id' => $product['id'],
                            'status' => 'publish'
                        ];
                    }
                    
                    // Split updates into smaller batches to reduce server load
                    $publishBatchSize = max(5, (int)($batchSize * 0.5)); // Half the create batch size, but minimum 5
                    $updateBatches = array_chunk($productUpdates, $publishBatchSize);
                    
                    foreach ($updateBatches as $updateIndex => $updateBatch) {
                        try {
                            // Add a short delay between update batches to give the server breathing room
                            if ($updateIndex > 0) {
                                $delaySeconds = min(15, 5 + $updateIndex); // Progressive delay up to 15 seconds
                                Log::info("Waiting {$delaySeconds} seconds before publishing next batch");
                                sleep($delaySeconds);
                            }
                            
                            $updateResponse = $apiClient->batchProducts(['update' => $updateBatch]);
                            $updatedProducts = $updateResponse['update'] ?? [];
                            
                            $updatedCount = count($updatedProducts);
                            
                            // If we couldn't publish some products, record them for later reconciliation
                            if ($updatedCount < count($updateBatch)) {
                                $publishedIds = array_column($updatedProducts, 'id');
                                $notPublishedIds = array_diff(array_column($updateBatch, 'id'), $publishedIds);
                                
                                if (!empty($notPublishedIds)) {
                                    $this->recordDraftProductsForReconciliation($importRun->id, $notPublishedIds);
                                }
                            }
                            
                            Log::info("Successfully published {$updatedCount} products in update batch #{$updateIndex} for ImportRun #{$this->importRunId}");
                            
                        } catch (\Throwable $updateError) {
                            // Log but don't fail the job - we've already created the products as drafts
                            Log::error("Failed to publish products in update batch #{$updateIndex} for ImportRun #{$this->importRunId}: " . $updateError->getMessage(), [
                                'exception' => $updateError->getMessage(),
                                'batch_index' => $updateIndex,
                                'batch_size' => count($updateBatch)
                            ]);
                            
                            // Record these products for later reconciliation
                            $this->recordDraftProductsForReconciliation($importRun->id, array_column($updateBatch, 'id'));
                        }
                    }
                    
                } catch (\Throwable $e) {
                    Log::error("Failed to process batch for ImportRun #{$this->importRunId}: " . $e->getMessage(), [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'batch_size' => count($batch)
                    ]);
                    $importRun->increment('failed_records', count($batch));
                    
                    // If we get consistent failures, reduce batch size for future attempts
                    $this->reduceRecommendedBatchSize($connection->website_id);
                    
                    // If we get a connection or auth error, we should fail the entire job.
                    if ($e instanceof \Illuminate\Http\Client\ConnectionException || $e instanceof \Illuminate\Auth\AuthenticationException) {
                        Log::critical("Fatal API error for ImportRun #{$this->importRunId}. Cancelling batch.", ['exception' => $e]);
                        if ($this->batch()) {
                            $this->batch()->cancel();
                        }
                        $this->fail($e); // Fail the job and stop processing
                        return; 
                    }
                    
                    // For other errors, we log, increment failure count, and continue to the next batch.
                    continue;
                }
            }
    
            $importRun->increment('processed_records', count($products));
            Log::info("Completed processing chunk for ImportRun #{$this->importRunId} with " . count($products) . " products");
    
            // The directory cleanup is now handled by StartImportRunJob's batch callbacks.
            // No need to call cleanup here anymore.
        } catch (\Throwable $e) {
            Log::error("ProcessChunkJob failed for ImportRun #{$this->importRunId}: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'chunk_file' => $this->chunkFilePath
            ]);
            
            // Re-throw the exception to allow Laravel's retry mechanism to work
            throw $e;
        }
    }

    /**
     * Record product IDs that couldn't be published for later reconciliation
     */
    protected function recordDraftProductsForReconciliation(int $importRunId, array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }
        
        try {
            // Use a cache key that includes the import run ID
            $cacheKey = "draft_products:{$importRunId}";
            
            // Get existing draft products, if any
            $existingDrafts = Cache::get($cacheKey, []);
            
            // Add the new draft products
            $allDrafts = array_unique(array_merge($existingDrafts, $productIds));
            
            // Store back in cache with a 24-hour expiration
            Cache::put($cacheKey, $allDrafts, 60 * 24);
            
            // Update the import run record
            ImportRun::where('id', $importRunId)->increment('draft_records', count($productIds));
            
            Log::info("Recorded " . count($productIds) . " draft products for later reconciliation (Import Run #{$importRunId})");
        } catch (\Throwable $e) {
            Log::error("Failed to record draft products for reconciliation: " . $e->getMessage());
        }
    }
    
    /**
     * Get the recommended batch size for a specific website
     */
    protected function getRecommendedBatchSize(int $websiteId): int
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        $batchSize = Cache::get($cacheKey, 30); // Default to 30 (up from 25)
        
        return $batchSize;
    }
    
    /**
     * Reduce the recommended batch size for a website due to errors
     */
    protected function reduceRecommendedBatchSize(int $websiteId): void
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        $currentSize = Cache::get($cacheKey, 30);
        
        // Reduce by 25% but never below 5
        $newSize = max(5, (int)($currentSize * 0.75));
        
        if ($newSize < $currentSize) {
            Cache::put($cacheKey, $newSize, 60 * 24); // Store for 24 hours
            Log::warning("Reduced recommended batch size for website #{$websiteId} from {$currentSize} to {$newSize} due to errors");
        }
    }

    /**
     * Atomically logs an error for a failed batch to the import run.
     */
    private function logBatchError(string $errorMessage, array $failedProducts): void
    {
        try {
            DB::transaction(function () use ($errorMessage, $failedProducts) {
                // Lock the import run row to prevent race conditions
                $importRun = ImportRun::where('id', $this->importRunId)->lockForUpdate()->firstOrFail();

                $errorRecord = [
                    'timestamp' => now()->toIso8601String(),
                    'error' => $errorMessage,
                    'failed_products_count' => count($failedProducts),
                    'failed_skus' => collect($failedProducts)->pluck('sku')->filter()->values()->all(),
                ];

                // Safely append the new error to the existing JSON array
                $errors = $importRun->error_records ?? [];
                $errors[] = $errorRecord;
                $importRun->error_records = $errors;

                $importRun->save();
            });
        } catch (Throwable $e) {
            // If logging fails, record it to the standard log to avoid crashing the job.
            Log::critical("Failed to log batch error for ImportRun #{$this->importRunId}. Error: " . $e->getMessage());
        }
    }

    protected function loadChunkProducts(string $filePath): array
    {
        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        if (!$data) {
            $this->logError(null, "Failed to decode products from chunk file: {$filePath}. Error: " . json_last_error_msg());
            return [];
        }

        return $data;
    }

    /**
     * Logs an error message to the import run's log.
     */
    protected function logError(?array $productData, string $message): void
    {
        $timestamp = now()->toIso8601String();
        $logEntry = compact('timestamp', 'message');

        if ($productData) {
            $logEntry['product_data'] = $productData;
        }

        // Atomically update the import run's log
        DB::transaction(function () use ($logEntry) {
            $importRun = ImportRun::where('id', $this->importRunId)->lockForUpdate()->firstOrFail();

            $log = $importRun->import_log ?? [];
            $log[] = $logEntry;

            $importRun->import_log = $log;
            $importRun->save();
        });
    }
}