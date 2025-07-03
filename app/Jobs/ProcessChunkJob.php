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
use Throwable;

class ProcessChunkJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable, SerializesModels, Batchable;
    
    public int $timeout = 900; // 15 minutes
    public int $tries = 5;
    public int $backoff = 60; // Wait 60 seconds between retry attempts

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
    
            // 2. Process in batches to respect API limits and reduce server load.
            $batches = array_chunk($draftsToCreate, 50); // Reduce batch size from 100 to 50 for more reliability
    
            foreach ($batches as $batchIndex => $batch) {
                try {
                    Log::info("Sending batch #{$batchIndex} with " . count($batch) . " products to WooCommerce API for ImportRun #{$this->importRunId}");
                    
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
                        continue; // Skip to the next batch
                    }
                    
                    // Count successful creations
                    $createdCount = count($createdProducts);
                    $importRun->increment('created_records', $createdCount);
                    
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
                    $updateBatches = array_chunk($productUpdates, 25); // Smaller batches for publishing
                    
                    foreach ($updateBatches as $updateIndex => $updateBatch) {
                        try {
                            // Add a short delay between update batches to give the server breathing room
                            if ($updateIndex > 0) {
                                sleep(2); // 2-second delay between update batches
                            }
                            
                            $updateResponse = $apiClient->batchProducts(['update' => $updateBatch]);
                            $updatedProducts = $updateResponse['update'] ?? [];
                            
                            $updatedCount = count($updatedProducts);
                            Log::info("Successfully published {$updatedCount} products in update batch #{$updateIndex} for ImportRun #{$this->importRunId}");
                            
                        } catch (\Throwable $updateError) {
                            // Log but don't fail the job - we've already created the products as drafts
                            Log::error("Failed to publish products in update batch #{$updateIndex} for ImportRun #{$this->importRunId}: " . $updateError->getMessage(), [
                                'exception' => $updateError->getMessage(),
                                'batch_index' => $updateIndex,
                                'batch_size' => count($updateBatch)
                            ]);
                        }
                    }
                    
                } catch (\Throwable $e) {
                    Log::error("Failed to process batch for ImportRun #{$this->importRunId}: " . $e->getMessage(), [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'batch_size' => count($batch)
                    ]);
                    $importRun->increment('failed_records', count($batch));
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
            $this->logError(null, "Failed to decode products from chunk file: {$chunkFilePath}. Error: " . json_last_error_msg());
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