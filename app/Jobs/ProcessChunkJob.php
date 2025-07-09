<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use App\Models\FeedWebsite;
use App\Models\ImportRun;
use App\Models\SyndicatedProduct;
use App\Models\Website;
use App\Services\TransformationService;
use App\Services\FilterService;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Throwable;

/**
 * ProcessChunkJob
 *
 * This job processes a single chunk of feed data.
 *
 * Key Tasks:
 * - Transforms the chunk data using TransformationService.
 * - Sends batch requests to the destination platform's API for product creation or updates.
 * - Handles errors and logs them for reconciliation.
 */
class ProcessChunkJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable, SerializesModels, Batchable;
    
    public int $timeout = 7200; // 2 hours (increased from 60 minutes)
    public int $tries = 12; // Increased from 5 to give more chances for recovery
    public int $backoff = 180; // 3 minutes between retry attempts (increased from 2 minutes)

    /**
     * Check if the circuit breaker is open for the website
     * Used to determine if we should use more aggressive backoff
     */
    protected function isCircuitBreakerOpen(int $websiteId): bool
    {
        $cacheKey = "api_circuit:website:{$websiteId}";
        $circuitState = Cache::get($cacheKey, ['open' => false, 'failure_count' => 0]);
        
        // Consider circuit open if explicitly open or if there are multiple failures
        return ($circuitState['open'] ?? false) || ($circuitState['failure_count'] ?? 0) >= 3;
    }

    /**
     * Dynamically adjust backoff for retries based on the type of failure.
     */
    public function retryUntil(): \DateTime
    {
        // Get website ID for circuit breaker check
        try {
            $connection = FeedWebsite::find($this->connectionId);
            $websiteId = $connection ? $connection->website_id : 0;
            $circuitOpen = $websiteId ? $this->isCircuitBreakerOpen($websiteId) : false;
        } catch (\Throwable $e) {
            $circuitOpen = false;
        }

        // Use longer backoff if circuit breaker is open or we know it's an API timeout
        if ($circuitOpen) {
            // Use exponential backoff with much higher multiplier when circuit is open
            return now()->addSeconds($this->backoff * pow(4, min($this->attempts(), 6)));
        } else if ($this->job && $this->job->payload() && isset($this->job->payload()['exception'])) {
            $exception = $this->job->payload()['exception'];
            if (strpos($exception, 'timeout') !== false || strpos($exception, '504') !== false) {
                // For timeouts, use much longer backoff to allow API to recover
                return now()->addSeconds($this->backoff * pow(3, min($this->attempts(), 6)));
            }
        }
        
        // Default exponential backoff (capped at 6 to avoid excessive wait times)
        return now()->addSeconds($this->backoff * pow(2, min($this->attempts(), 6)));
    }
    
    /**
     * Determine the number of seconds to wait before retrying the job.
     */
    public function backoff()
    {
        // Get website ID for circuit breaker check
        try {
            $connection = FeedWebsite::find($this->connectionId);
            $websiteId = $connection ? $connection->website_id : 0;
            $circuitOpen = $websiteId ? $this->isCircuitBreakerOpen($websiteId) : false;
        } catch (\Throwable $e) {
            $circuitOpen = false;
        }

        // Use longer backoff if circuit breaker is open or we know it's an API timeout
        if ($circuitOpen) {
            // Much more aggressive backoff when circuit is open
            return $this->backoff * pow(4, min($this->attempts() - 1, 6));
        } else if ($this->job && $this->job->payload() && isset($this->job->payload()['exception'])) {
            $exception = $this->job->payload()['exception'];
            if (strpos($exception, 'timeout') !== false || strpos($exception, '504') !== false) {
                // For timeouts, use much longer backoff
                return $this->backoff * pow(3, min($this->attempts() - 1, 6));
            }
        }
        
        // Default exponential backoff (capped to avoid excessive delays)
        return $this->backoff * pow(2, min($this->attempts() - 1, 6));
    }

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

    /**
     * Generate a unique identifier for a product.
     *
     * @param array $rawProduct The raw product data from the source feed.
     * @param string $feedName The name of the feed.
     * @return string The generated unique identifier.
     */
    protected function generateUniqueIdentifier(array $productData, string $feedName): string
    {
        // Use the 'id' from the transformed payload, which is mapped from the source feed's unique ID.
        $sourceId = $productData['id'] ?? $productData['sku'] ?? null;

        if (empty($sourceId)) {
            // Virtual product?
            Log::warning('Cannot generate unique identifier: Transformed payload is missing an ID or SKU.', [
                'product_data' => json_encode($productData)
            ]);
            return ''; // Return an empty string to signify failure
        }

        return $feedName . ':' . $sourceId;
    }

    public function handle()
    {
        try {
            // Global catch for any error, even before main try/catch
            try {
                if ($this->batch() && $this->batch()->cancelled()) {
                    return;
                }

                try {
                    $importRun = ImportRun::findOrFail($this->importRunId);
                    $connection = FeedWebsite::findOrFail($this->connectionId);

                    // Log the raw chunk data for debugging
                    $rawChunkChecking = file_get_contents($this->chunkFilePath);
                    Log::debug("Raw chunk data for {$this->chunkFilePath}: " . $rawChunkChecking);

                    // Perform API health check before proceeding
                    if (!$this->checkApiHealthBeforeProcessing($connection->website)) {
                        Log::warning("Skipping processing for now due to API health issues. Will retry later.");
                        $this->release(180); // Release back to the queue for 3 minutes.
                        return;
                    }

                    if (!file_exists($this->chunkFilePath) || !is_readable($this->chunkFilePath)) {
                        Log::warning("ProcessChunkJob: Chunk file not found or is not readable: {$this->chunkFilePath}. This might be due to cleanup already occurring.");
                        
                        // Check if this is likely due to cleanup after successful processing
                        $importRun = ImportRun::find($this->importRunId);
                        if ($importRun && ($importRun->created_records > 0 || $importRun->updated_records > 0)) {
                            Log::info("ImportRun #{$this->importRunId} has already processed products ({$importRun->created_records} created, {$importRun->updated_records} updated). Chunk file cleanup appears to have occurred. Skipping this job.");
                            return; // Don't fail the job if products were already processed
                        }
                        
                        Log::error("ProcessChunkJob failed: Chunk file not found and no products processed yet: {$this->chunkFilePath}");
                        $importRun->increment('failed_records', 100); // Assuming chunk size is 100
                        $this->fail(new \Exception("Chunk file not found or is not readable: {$this->chunkFilePath}"));
                        return;
                    }

                    $products = $this->loadChunkProducts($this->chunkFilePath);

                    if (empty($products)) {
                        Log::info("No products to process in chunk {$this->chunkFilePath} for ImportRun #{$this->importRunId}.");
                        return;
                    }

                    Log::info("Processing chunk with " . count($products) . " products for ImportRun #{$this->importRunId}");

                    $transformer = new TransformationService();
                    $apiClient = new WooCommerceApiClient($connection->website);
                    $filterService = new FilterService(); // Initialize FilterService

                    // Step 1: Transform all products in the chunk first.
                    $transformedPayloads = [];
                    foreach ($products as $rawProduct) {
                        // Skip any product filtered out by the mapping wizard
                        if (!$filterService->passes($rawProduct, $connection->filtering_rules)) {
                            $importRun->increment('skipped_records');
                            continue;
                        }
                        try {
                            // Normalize the category key using CategoryNormalizer
                            $rawCategory = $rawProduct[$connection->category_source_field] ?? '';
                            
                            // Build the category mapping lookup (source => destination ID)
                            $categoryMapLookup = [];
                            if (!empty($connection->category_mappings)) {
                                foreach ($connection->category_mappings as $mapping) {
                                    if (isset($mapping['source']) && isset($mapping['dest']) && !empty($mapping['dest'])) {
                                        $categoryMapLookup[$mapping['source']] = (int) $mapping['dest'];
                                    }
                                }
                            }
                            
                            // Get the mapped category ID using our robust normalizer
                            $mappedCategoryId = \App\Services\CategoryNormalizer::normalize(
                                $rawCategory, 
                                $connection->category_delimiter, 
                                $categoryMapLookup
                            );
                            
                            // Skip if no category mapping found
                            if (!$mappedCategoryId) {
                                Log::info("Skipping product, no category mapping found for: " . $rawCategory);
                                $importRun->increment('skipped_records');
                                continue;
                            }

                            // Transform the product using the normalized category
                            // Add the mapped category ID to the raw product for the transformer
                            $rawProduct['__mappedCategoryId'] = $mappedCategoryId;
                            $rawProduct['__category_source_field'] = $connection->category_source_field;
                            
                            $payload = $transformer->transform(
                                $rawProduct,
                                $connection->field_mappings,
                                $connection->category_mappings
                            );

                            if (!empty($payload)) {
                                $transformedPayloads[] = $payload;
                            }
                        } catch (\Throwable $e) {
                            Log::warning("Failed to transform product in ImportRun #{$this->importRunId}: " . $e->getMessage(), [
                                'product_id' => $rawProduct['id'] ?? 'unknown',
                                'exception' => $e->getMessage()
                            ]);
                        }
                    }

                    if (empty($transformedPayloads)) {
                        Log::info("No valid, transformed products to process in chunk for ImportRun #{$this->importRunId}.");
                        return;
                    }

                    // Step 2: Generate SKUs from the transformed payloads.
                    $skusToFetch = [];
                    foreach ($transformedPayloads as $key => $payload) {
                        $uniqueIdentifier = $this->generateUniqueIdentifier($payload, $connection->feed->name);
                        if (!empty($uniqueIdentifier)) {
                            // Assign the generated SKU back to the payload.
                            $transformedPayloads[$key]['sku'] = $uniqueIdentifier;
                            $skusToFetch[] = $uniqueIdentifier;
                        } else {
                            // If we couldn't generate a SKU, this product can't be processed.
                            unset($transformedPayloads[$key]);
                            Log::warning('Skipping product because a unique SKU could not be generated.', [
                                'payload' => $payload
                            ]);
                        }
                    }
                    
                    // Re-index the array after unsetting elements
                    $transformedPayloads = array_values($transformedPayloads);

                    // Step 3: Fetch a map of existing product SKUs and their IDs from the destination site.
                    $existingProductMap = $apiClient->getProductIdMapBySkus($skusToFetch);

                    $draftsToCreate = [];
                    $draftsToUpdate = [];
                    
                    // Step 4: Sort payloads into create and update batches.
                    foreach ($transformedPayloads as $payload) {
                        // Enhance payload with additional data.
                        $payload['status'] = 'publish';
                        $payload['catalog_visibility'] = 'visible';
                        $payload['stock_status'] = $payload['stock_status'] ?? 'instock';
                        $payload['meta_data'] = [
                            ['key' => 'feed_name', 'value' => $connection->feed->name],
                            ['key' => 'import_run_id', 'value' => $this->importRunId],
                            ['key' => 'import_date', 'value' => date('Y-m-d H:i:s')]
                        ];

                        // Check if product already exists using the pre-fetched map.
                        if (isset($existingProductMap[$payload['sku']])) {
                            // Product exists, add to update batch.
                            $draftsToUpdate[] = array_merge(['id' => $existingProductMap[$payload['sku']]], $payload);
                        } else {
                            // New product, add to create batch.
                            $draftsToCreate[] = $payload;
                        }
                    }

                    $skippedCount = count($products) - count($transformedPayloads);
                    if ($skippedCount > 0) {
                        Log::info("Skipped {$skippedCount} products in chunk for ImportRun #{$this->importRunId} due to transformation or SKU generation issues.");
                    }

                    if (empty($draftsToCreate) && empty($draftsToUpdate)) {
                        Log::info("No products to create or update for ImportRun #{$this->importRunId}.");
                        return;
                    }

                    // Process creation and updates separately
                    if (!empty($draftsToCreate)) {
                        $this->processProductCreation($draftsToCreate, $connection, $importRun);
                    }

                    if (!empty($draftsToUpdate)) {
                        $this->processProductUpdates($draftsToUpdate, $connection, $importRun);
                    }

                } catch (\Throwable $e) {
                    Log::error("ProcessChunkJob main try/catch error for ImportRun #{$this->importRunId}: " . $e->getMessage(), [
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->fail($e);
                }
            } catch (\Throwable $outer) {
                Log::critical("ProcessChunkJob global catch error for ImportRun #{$this->importRunId}: " . $outer->getMessage(), [
                    'exception' => get_class($outer),
                    'trace' => $outer->getTraceAsString()
                ]);
                $this->fail($outer);
            }
        } catch (\Throwable $failAll) {
            // This should never be reached, but just in case
            Log::critical("ProcessChunkJob failed at the outermost level for ImportRun #{$this->importRunId}: " . $failAll->getMessage(), [
                'exception' => get_class($failAll),
                'trace' => $failAll->getTraceAsString()
            ]);
            $this->fail($failAll);
        }
    }

    // (createBatchWithBackoff handles empty array guard)

    /**
     * Process a batch of product creation requests.
     *
     * @param array $productsToCreate
     * @param FeedWebsite $connection
     * @param ImportRun $importRun
     */
    protected function processProductCreation(array $productsToCreate, FeedWebsite $connection, ImportRun $importRun)
    {
        // Delegate to backoff logic
        $response = $this->createBatchWithBackoff($productsToCreate, $connection, $importRun);

        // Add some extra debug info for the first product
        if (!empty($productsToCreate[0])) {
            $firstProduct = $productsToCreate[0];
            Log::debug("Sample product creation payload", [
                'name' => $firstProduct['name'] ?? 'N/A',
                'sku' => $firstProduct['sku'] ?? 'N/A',
            ]);
        }

        Log::info("WooCommerce product creation response", [
            'success' => $response['success'] ?? false,
            'total_requested' => $response['total_requested'],
            'total_created' => $response['total_created'],
            'execution_time_ms' => $response['execution_time_ms'] ?? 'N/A'
        ]);

        if (($response['total_created'] ?? 0) > 0) {
            \DB::transaction(function() use ($importRun, $response) {
                $importRun->increment('created_records', $response['total_created']);
            });
            Log::info("Successfully created {$response['total_created']} products for ImportRun #{$this->importRunId}");
            
            // Create API client for verification
            $apiClient = new WooCommerceApiClient($connection->website);
            $this->verifyCreatedProducts($response['created'], $apiClient);
        }

        // Handle products that failed validation within the batch
        if (!empty($response['failed'])) {
            \DB::transaction(function() use ($response, $importRun) {
                $this->handleBatchCreationErrors($response['failed'], $importRun);
            });
        }
    }

    protected function processProductUpdates(array $productsToUpdate, FeedWebsite $connection, ImportRun $importRun)
    {
        $apiClient = new WooCommerceApiClient($connection->website);
        $count = count($productsToUpdate);

        if ($count === 0) {
            return;
        }

        Log::info("Attempting to update {$count} products for ImportRun #{$this->importRunId}");

        try {
            if (!empty($productsToUpdate[0])) {
                $firstProduct = $productsToUpdate[0];
                Log::debug("Sample product update payload", [
                    'id' => $firstProduct['id'] ?? 'N/A',
                    'sku' => $firstProduct['sku'] ?? 'N/A'
                ]);
            }

            $response = $apiClient->updateProducts($productsToUpdate);

            Log::info("WooCommerce product update response", [
                'success' => $response['success'],
                'total_requested' => $response['total_requested'],
                'total_updated' => $response['total_updated'],
                'execution_time_ms' => $response['execution_time_ms'] ?? 'N/A'
            ]);

            if (($response['total_updated'] ?? 0) > 0) {
                \DB::transaction(function() use ($importRun, $response) {
                    $importRun->increment('updated_records', $response['total_updated']);
                });
                Log::info("Successfully updated {$response['total_updated']} products for ImportRun #{$this->importRunId}");
            }

            // Handle products that failed validation within the batch
            if (!empty($response['failed'])) {
                \DB::transaction(function() use ($response, $productsToUpdate, $connection, $importRun) {
                    $this->handleBatchErrors($response['failed'], $productsToUpdate, $connection, $importRun);
                });
            }

        } catch (\Throwable $e) {
            // Check for timeout or other server errors that suggest splitting the batch
            if ($count > 1 && (str_contains($e->getMessage(), '504 Gateway Time-out') || str_contains($e->getMessage(), 'cURL error 28'))) {
                Log::warning("Caught a timeout error updating a batch of {$count} products. Splitting batch and retrying.", [
                    'import_run_id' => $this->importRunId,
                    'error' => $e->getMessage()
                ]);

                // Reduce recommended batch size for future jobs
                $this->reduceRecommendedBatchSize($connection->website->id);

                // Split the batch and retry
                $midpoint = (int)ceil($count / 2);
                $batch1 = array_slice($productsToUpdate, 0, $midpoint);
                $batch2 = array_slice($productsToUpdate, $midpoint);

                $this->processProductUpdates($batch1, $connection, $importRun);
                $this->processProductUpdates($batch2, $connection, $importRun);
            } else {
                // For other errors, log them as failed records
                Log::error("Failed to update a batch of {$count} products for ImportRun #{$this->importRunId}: " . $e->getMessage());
                $this->logBatchError($e->getMessage(), $productsToUpdate);
                $importRun->increment('failed_records', $count);
            }
        }
    }

    /**
     * Verify a sample of created products to ensure they exist in WooCommerce.
     *
     * @param array $createdProducts The array of created products from the API response.
     * @param WooCommerceApiClient $apiClient The API client.
     */
    private function verifyCreatedProducts(array $createdProducts, WooCommerceApiClient $apiClient): void
    {
        if (empty($createdProducts)) {
            return;
        }

        $samplesToVerify = min(3, count($createdProducts));
        $verificationSuccesses = 0;
        $verificationFailures = 0;

        for ($i = 0; $i < $samplesToVerify; $i++) {
            $product = $createdProducts[$i];
            if (empty($product['id']) || empty($product['sku'])) {
                continue;
            }

            try {
                $verifyResult = $apiClient->findProductBySKU($product['sku']);
                if ($verifyResult) {
                    Log::info("Verified product exists: ID {$product['id']}, SKU {$product['sku']}, Status: {$verifyResult['status']}");
                    $verificationSuccesses++;
                } else {
                    Log::warning("Could not verify product exists despite successful creation: ID {$product['id']}, SKU {$product['sku']}");
                    $verificationFailures++;
                }
            } catch (\Throwable $e) {
                Log::warning("Exception during product verification: " . $e->getMessage());
                $verificationFailures++;
            }
        }

        Log::info("Product verification summary", [
            'successes' => $verificationSuccesses,
            'failures' => $verificationFailures,
            'samples_checked' => $samplesToVerify
        ]);

        if ($verificationFailures === $samplesToVerify && $samplesToVerify > 0) {
            Log::error("All product verifications failed! Products may not be properly saved in WooCommerce.");
        }
    }

    protected function handleBatchErrors(array $errors, array $batch, FeedWebsite $connection, ImportRun $importRun)
    {
        foreach ($errors as $error) {
            $this->logBatchError($error['message'], [$batch[$error['index']]]);
        }
        $importRun->increment('failed_records', count($errors));
    }
    
    /**
     * Handle failed product creations from WooCommerce API response
     */
    protected function handleBatchCreationErrors(array $failedProducts, ImportRun $importRun)
    {
        $errorMessages = [];
        
        foreach ($failedProducts as $index => $failedProduct) {
            if (isset($failedProduct['error'])) {
                $error = $failedProduct['error'];
                $errorCode = $error['code'] ?? 'unknown';
                $errorMessage = $error['message'] ?? 'Unknown error';
                
                // Extract SKU from error message if available
                $sku = 'Unknown SKU';
                if (preg_match('/SKU-koodia \(([^)]+)\)/', $errorMessage, $matches)) {
                    $sku = $matches[1];
                }
                
                $errorMessages[] = [
                    'time' => now()->format('H:i:s'),
                    'error' => "Product creation failed: {$errorCode} - {$errorMessage}",
                    'count' => 1,
                    'samples' => [$sku]
                ];
                
                Log::warning("Product creation failed", [
                    'sku' => $sku,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage
                ]);
            }
        }
        
        // Update failed count and error records
        $importRun->increment('failed_records', count($failedProducts));
        
        if (!empty($errorMessages)) {
            $existingErrors = $importRun->error_records ?? [];
            if (is_string($existingErrors)) {
                $existingErrors = json_decode($existingErrors, true) ?? [];
            }
            
            $updatedErrors = array_merge($existingErrors, $errorMessages);
            $importRun->update(['error_records' => $updatedErrors]);
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
        return Cache::get("batch_size:website:{$websiteId}", 30); // Default to 30
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
     * Only stores summary information to prevent database column size issues.
     */
    private function logBatchError(string $errorMessage, array $failedProducts): void
    {
        try {
            // Log complete details to the application log for debugging
            $skus = collect($failedProducts)->pluck('sku')->filter()->values()->all();
            Log::error("Batch error: {$errorMessage}", [
                'import_run_id' => $this->importRunId,
                'failed_products_count' => count($failedProducts),
                'failed_skus' => $skus,
            ]);
            
            // Store minimal information in the database to avoid column size issues
            DB::transaction(function () use ($errorMessage, $failedProducts) {
                // Lock the import run row to prevent race conditions
                $importRun = ImportRun::where('id', $this->importRunId)->lockForUpdate()->firstOrFail();

                // Highly simplified error record - just the error type and count
                $errorRecord = [
                    'time' => now()->format('H:i:s'),
                    'error' => substr($errorMessage, 0, 100), // Truncate long error messages
                    'count' => count($failedProducts),
                    // Only include first 5 SKUs as examples, not all of them
                    'samples' => array_slice(collect($failedProducts)->pluck('sku')->filter()->values()->all(), 0, 5)
                ];

                // Safely append the new error to the existing JSON array
                $errors = $importRun->error_records ?? [];
                $errors[] = $errorRecord;
                
                // Limit the total number of stored errors to prevent the column from growing too large
                if (count($errors) > 50) {
                    $errors = array_slice($errors, -50); // Keep only the 50 most recent errors
                }
                
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

    /**
     * Check the API health before processing
     * This can help prevent unnecessary job failures if the API is already known to be problematic
     */
    protected function checkApiHealthBeforeProcessing(Website $website): bool
    {
        try {
            $apiClient = new WooCommerceApiClient($website);
            
            // Check health status - this is a lightweight check
            $healthStatus = $apiClient->checkApiHealth();
            
            // If circuit is open or status is critical, we should back off immediately
            if ($healthStatus['status'] === 'critical') {
                Log::warning("API health check failed for website #{$website->id}: " . implode(', ', $healthStatus['issues']));
                
                // Add exponential backoff based on attempt number - more aggressive than before
                $backoffSeconds = min(1800, $this->backoff * pow(3, $this->attempts())); // Cap at 30 minutes
                
                // Don't delay the queue for too long, just release back to it
                // This will allow other jobs to be processed while this one waits
                $this->release($backoffSeconds);
                
                Log::info("Released job back to queue with {$backoffSeconds} seconds delay due to critical API health status");
                return false;
            }
            
            // If degraded, check if we've had too many consecutive degraded states for this job
            if ($healthStatus['status'] === 'degraded') {
                $cacheKey = "job_degraded:{$this->uniqueId()}";
                $degradedCount = Cache::get($cacheKey, 0) + 1;
                Cache::put($cacheKey, $degradedCount, 3600); // Remember for 1 hour
                
                // If we've seen degraded status multiple times, back off more aggressively
                if ($degradedCount >= 3) {
                    Log::warning("API health has been degraded for {$degradedCount} consecutive checks for website #{$website->id}");
                    
                    $backoffSeconds = min(900, $this->backoff * pow(2, $this->attempts())); // Cap at 15 minutes
                    $this->release($backoffSeconds);
                    
                    Log::info("Released job back to queue with {$backoffSeconds} seconds delay due to persistently degraded API health");
                    return false;
                }
                
                Log::info("API health is degraded for website #{$website->id}, but proceeding with caution: " . 
                          implode(', ', $healthStatus['issues']));
            } else {
                // Reset degraded count if health check passes
                Cache::forget("job_degraded:{$this->uniqueId()}");
            }
            
            // Log successful connection details to help with debugging
            Log::info("API health check passed for website #{$website->id}", [
                'health_status' => [
                    'status' => $healthStatus['status'],
                    'response_time' => $healthStatus['response_time'] ?? 'N/A'
                ]
            ]);
            
            return true;
        } catch (\Throwable $e) {
            Log::error("Error checking API health for website #{$website->id}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Release the job back to the queue with a delay - more aggressive
            $backoffSeconds = min(1200, $this->backoff * pow(3, $this->attempts())); // Cap at 20 minutes
            $this->release($backoffSeconds);
            
            Log::info("Released job back to queue with {$backoffSeconds} seconds delay due to health check error");
            return false;
        }
    }

    /**
     * Create a product batch with exponential backoff and error handling.
     *
     * @param array $products List of payloads to create.
     * @param FeedWebsite $connection Connection details for API client.
     * @param ImportRun $importRun Current import run model.
     * @param int $maxAttempts Max number of retries.
     * @param int $initialDelay Delay seconds for first retry.
     * @return array API response structure.
     */
    private function createBatchWithBackoff(array $products, FeedWebsite $connection, ImportRun $importRun, int $maxAttempts = 3, int $initialDelay = 1): array
    {
        if (empty($products)) {
            Log::warning("Skipping empty create payload for ImportRun #{$this->importRunId}");
            return ['success' => false, 'total_requested' => 0, 'total_created' => 0, 'created' => [], 'error' => 'empty_payload'];
        }

        $attempt = 0;
        $delay = $initialDelay;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $apiClient = new WooCommerceApiClient($connection->website);
                return $apiClient->createProducts($products);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                Log::warning("Attempt {$attempt} failed for create batch of " . count($products) . " items for ImportRun #{$this->importRunId}: {$message}");
                // If timeout and multiple items, split batch and retry smaller payloads
                if (count($products) > 1 && (str_contains($message, '504 Gateway Time-out') || str_contains($message, 'cURL error 28'))) {
                    $mid = (int) ceil(count($products) / 2);
                    $first = array_slice($products, 0, $mid);
                    $second = array_slice($products, $mid);
                    $res1 = $this->createBatchWithBackoff($first, $connection, $importRun, $maxAttempts, $initialDelay);
                    $res2 = $this->createBatchWithBackoff($second, $connection, $importRun, $maxAttempts, $initialDelay);
                    $created = array_merge($res1['created'] ?? [], $res2['created'] ?? []);
                    $totalCreated = count($created);
                    return [
                        'success' => $totalCreated > 0,
                        'total_requested' => count($products),
                        'total_created' => $totalCreated,
                        'created' => $created,
                        'error' => $message
                    ];
                }
                // Exponential backoff retry
                if ($attempt >= $maxAttempts) {
                    // Final failure: log detailed errors and increment failed count
                    $this->logBatchError($message, $products);
                    $importRun->increment('failed_records', count($products));
                    return ['success' => false, 'total_requested' => count($products), 'total_created' => 0, 'created' => [], 'error' => $message];
                }
                sleep($delay);
                $delay *= 2;
            }
        }

        // Should not reach here
        return ['success' => false, 'total_requested' => count($products), 'total_created' => 0, 'created' => [], 'error' => 'unknown_error'];
    }
}