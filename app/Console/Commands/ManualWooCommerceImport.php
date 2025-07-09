<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FeedWebsite;
use App\Models\ImportRun;
use App\Services\Api\WooCommerceApiClient;
use App\Services\TransformationService;
use Illuminate\Support\Facades\Log;

class ManualWooCommerceImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:manual-import {connection_id : The ID of the feed-website connection} 
                           {--chunk=10 : Number of products to process per chunk} 
                           {--direct : Force using direct creation instead of batch}
                           {--limit=100 : Maximum number of products to import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually import products from a feed to WooCommerce using direct creation method';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connectionId = $this->argument('connection_id');
        $chunkSize = $this->option('chunk');
        $useDirectMethod = $this->option('direct');
        $limit = $this->option('limit');
        
        $connection = FeedWebsite::with(['feed', 'website'])->find($connectionId);
        
        if (!$connection) {
            $this->error("Connection not found with ID: {$connectionId}");
            return 1;
        }
        
        $this->info("Manual import for connection #{$connectionId}");
        $this->info("Feed: {$connection->feed->name}");
        $this->info("Website: {$connection->website->name} ({$connection->website->url})");
        $this->info("Using " . ($useDirectMethod ? "direct" : "batch") . " creation method");
        
        // Check if there's an active import run
        $importRun = ImportRun::where('feed_id', $connection->feed_id)
            ->where('status', 'running')
            ->latest()
            ->first();
        
        if (!$importRun) {
            $this->info("Creating a new import run...");
            $importRun = new ImportRun();
            $importRun->feed_id = $connection->feed_id;
            $importRun->started_at = now();
            $importRun->status = 'running';
            $importRun->save();
        }
        
        $this->info("Import run #{$importRun->id}");
        
        // Get products from the feed
        $this->info("Loading products from feed...");
        $feedProducts = $this->loadFeedProducts($connection->feed->path, $limit);
        
        if (empty($feedProducts)) {
            $this->error("No products found in feed");
            return 1;
        }
        
        $this->info("Loaded " . count($feedProducts) . " products from feed");
        
        // Create WooCommerce API client
        $apiClient = new WooCommerceApiClient($connection->website);
        
        // Test connection
        $this->info("Testing WooCommerce connection...");
        $connectionTest = $apiClient->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error("Connection test failed: " . $connectionTest['message']);
            return 1;
        }
        
        $this->info("Connection test successful!");
        
        // Get current product counts
        $productCounts = $apiClient->getProductCounts();
        $this->info("Current product counts in WooCommerce:");
        foreach ($productCounts as $status => $count) {
            $this->info("   {$status}: {$count}");
        }
        
        // Process products in chunks
        $chunks = array_chunk($feedProducts, $chunkSize);
        $totalChunks = count($chunks);
        
        $this->info("Processing {$totalChunks} chunks of {$chunkSize} products each...");
        
        $transformer = new TransformationService();
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        
        $bar = $this->output->createProgressBar($totalChunks);
        $bar->start();
        
        foreach ($chunks as $chunkIndex => $chunk) {
            $draftsToCreate = [];
            $draftsToUpdate = [];
            
            foreach ($chunk as $rawProduct) {
                try {
                    $payload = $transformer->transform(
                        $rawProduct,
                        $connection->field_mappings,
                        $connection->category_mappings
                    );
                    
                    if (!empty($payload)) {
                        // Generate a unique identifier for the product
                        $uniqueIdentifier = $this->generateUniqueIdentifier($rawProduct, $connection->feed->name);
                        $payload['sku'] = $uniqueIdentifier; // Use the unique identifier as the SKU
                        
                        // Add feed name as metadata
                        $payload['meta_data'] = [
                            ['key' => 'feed_name', 'value' => $connection->feed->name]
                        ];
                        
                        // Ensure product is published
                        $payload['status'] = 'publish';
                        
                        // Check if product already exists in WooCommerce
                        $existingProduct = $apiClient->findProductBySKU($payload['sku']);
                        
                        if ($existingProduct) {
                            // Update only changed fields
                            $draftsToUpdate[] = array_merge(['id' => $existingProduct['id']], $payload);
                        } else {
                            $draftsToCreate[] = $payload;
                        }
                    } else {
                        $skippedCount++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to transform product: " . $e->getMessage(), [
                        'product_id' => $rawProduct['id'] ?? 'unknown'
                    ]);
                    $skippedCount++;
                }
            }
            
            // Process creation
            if (!empty($draftsToCreate)) {
                try {
                    if ($useDirectMethod) {
                        $response = $apiClient->createProductsDirectly($draftsToCreate);
                    } else {
                        $response = $apiClient->createProducts($draftsToCreate);
                        
                        // If batch failed but we're not already using direct method, try it as fallback
                        if (($response['total_created'] ?? 0) === 0 && !$useDirectMethod) {
                            $this->info("\nBatch creation failed, trying direct method as fallback...");
                            $response = $apiClient->createProductsDirectly($draftsToCreate);
                        }
                    }
                    
                    if (($response['total_created'] ?? 0) > 0) {
                        $createdCount += $response['total_created'];
                        $importRun->increment('created_records', $response['total_created']);
                    } else {
                        $failedCount += count($draftsToCreate);
                    }
                } catch (\Throwable $e) {
                    Log::error("Failed to create products: " . $e->getMessage());
                    $failedCount += count($draftsToCreate);
                }
            }
            
            // Process updates
            if (!empty($draftsToUpdate)) {
                try {
                    $response = $apiClient->updateProducts($draftsToUpdate);
                    
                    if (($response['total_updated'] ?? 0) > 0) {
                        $updatedCount += $response['total_updated'];
                        $importRun->increment('updated_records', $response['total_updated']);
                    } else {
                        $failedCount += count($draftsToUpdate);
                    }
                } catch (\Throwable $e) {
                    Log::error("Failed to update products: " . $e->getMessage());
                    $failedCount += count($draftsToUpdate);
                }
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->info("\nImport completed!");
        $this->info("Created: {$createdCount}");
        $this->info("Updated: {$updatedCount}");
        $this->info("Skipped: {$skippedCount}");
        $this->info("Failed: {$failedCount}");
        
        // Finalize import run
        $importRun->completed_at = now();
        $importRun->status = 'completed';
        $importRun->save();
        
        // Get updated product counts
        $newProductCounts = $apiClient->getProductCounts();
        $this->info("\nProduct counts after import:");
        foreach ($newProductCounts as $status => $count) {
            $diff = $count - ($productCounts[$status] ?? 0);
            $diffStr = $diff > 0 ? "+{$diff}" : $diff;
            $this->info("   {$status}: {$count} ({$diffStr})");
        }
        
        return 0;
    }
    
    /**
     * Load products from a feed file
     */
    protected function loadFeedProducts(string $feedPath, int $limit): array
    {
        if (!file_exists($feedPath)) {
            $this->error("Feed file not found: {$feedPath}");
            return [];
        }
        
        $json = file_get_contents($feedPath);
        $data = json_decode($json, true);
        
        if (!$data) {
            $this->error("Failed to decode feed data: " . json_last_error_msg());
            return [];
        }
        
        // Limit the number of products if needed
        return array_slice($data, 0, $limit);
    }
    
    /**
     * Generate a unique identifier for a product
     */
    protected function generateUniqueIdentifier(array $rawProduct, string $feedName): string
    {
        $sourceId = $rawProduct['id'] ?? 'unknown';
        return $feedName . ':' . $sourceId;
    }
}
