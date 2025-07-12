<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use App\Models\FeedWebsite;
use Illuminate\Support\Facades\Log;

class DiagnoseWooCommerceProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:diagnose {website_id : The ID of the website to diagnose}
                            {--fix : Attempt to fix products with incorrect status}
                            {--limit=100 : Limit the number of products to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose issues with WooCommerce products and fix status problems';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websiteId = $this->argument('website_id');
        $attemptFix = $this->option('fix');
        $limit = $this->option('limit');
        
        // Find the website
        $website = Website::find($websiteId);
        
        if (!$website) {
            $this->error("Website not found with ID: {$websiteId}");
            return 1;
        }
        
        $this->info("Diagnosing WooCommerce products for website #{$websiteId}: {$website->name} ({$website->url})");
        
        // Create API client
        $apiClient = new WooCommerceApiClient($website);
        
        // Test connection
        $this->info("Testing WooCommerce connection...");
        $connectionTest = $apiClient->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error("Connection test failed: " . $connectionTest['message']);
            return 1;
        }
        
        $this->info("✅ Connection successful!");
        $this->info("   Response time: {$connectionTest['execution_time_ms']}ms");
        $this->info("   Products found: " . ($connectionTest['response']['products_count'] ?? 0));
        
        // Get product counts by status
        $this->info("\nGetting product counts by status...");
        $productCounts = $apiClient->getProductCounts();
        
        if (isset($productCounts['error'])) {
            $this->error("Failed to get product counts: " . $productCounts['error']);
        } else {
            $this->info("Product counts by status:");
            $total = 0;
            foreach ($productCounts as $status => $count) {
                $this->info("   {$status}: {$count}");
                $total += $count;
            }
            $this->info("   Total: {$total}");
        }
        
        // Check for draft products that should be published
        $this->info("\nChecking for products with incorrect status...");
        
        try {
            // Get draft products
            $draftParams = [
                'status' => 'draft',
                'per_page' => $limit
            ];
            
            $draftProducts = $apiClient->makeRequest('products', $draftParams);
            
            if (empty($draftProducts)) {
                $this->info("No draft products found.");
            } else {
                $this->info("Found " . count($draftProducts) . " draft products.");
                
                // Show sample of draft products
                $this->info("\nSample draft products:");
                $sampleSize = min(5, count($draftProducts));
                
                for ($i = 0; $i < $sampleSize; $i++) {
                    $product = $draftProducts[$i];
                    $this->info("   ID: {$product['id']}, Name: {$product['name']}, SKU: {$product['sku']}");
                }
                
                // If fix option is enabled, publish the draft products
                if ($attemptFix) {
                    $this->info("\nAttempting to publish draft products...");
                    $bar = $this->output->createProgressBar(count($draftProducts));
                    $bar->start();
                    
                    $successCount = 0;
                    $failCount = 0;
                    
                    foreach ($draftProducts as $product) {
                        try {
                            // Update product status to publish
                            $apiClient->makeRequest("products/{$product['id']}", ['status' => 'publish'], 'PUT');
                            $successCount++;
                        } catch (\Throwable $e) {
                            Log::error("Failed to publish product #{$product['id']}: " . $e->getMessage());
                            $failCount++;
                        }
                        
                        $bar->advance();
                    }
                    
                    $bar->finish();
                    $this->info("\nPublished {$successCount} products, failed to publish {$failCount} products.");
                    
                    // Get updated product counts
                    $this->info("\nUpdated product counts by status:");
                    $updatedCounts = $apiClient->getProductCounts();
                    
                    foreach ($updatedCounts as $status => $count) {
                        $diff = $count - ($productCounts[$status] ?? 0);
                        $diffStr = $diff > 0 ? "+{$diff}" : $diff;
                        $this->info("   {$status}: {$count} ({$diffStr})");
                    }
                }
            }
            
            // Create a test product to verify direct creation works
            $this->info("\nCreating a test product...");
            $testResult = $apiClient->createTestProduct();
            
            if ($testResult['success']) {
                $this->info("✅ Test product created successfully!");
                $this->info("   Product ID: {$testResult['product']['id']}");
                $this->info("   Product Name: {$testResult['product']['name']}");
                $this->info("   Product Status: {$testResult['product']['status']}");
                
                // Verify the product exists using direct ID lookup
                $this->info("\nVerifying product exists...");
                try {
                    $verification = $apiClient->makeRequest("products/{$testResult['product']['id']}");
                    
                    if ($verification && isset($verification['id'])) {
                        $this->info("✅ Product verification successful!");
                        $this->info("   Status: {$verification['status']}");
                        $this->info("   ID: {$verification['id']}");
                    } else {
                        $this->error("❌ Product verification failed! Product not found by ID.");
                    }
                } catch (\Exception $e) {
                    $this->error("❌ Product verification failed with error: " . $e->getMessage());
                }
            } else {
                $this->error("❌ Failed to create test product: " . ($testResult['error'] ?? 'Unknown error'));
            }
            
            return 0;
        } catch (\Throwable $e) {
            $this->error("Error during diagnosis: " . $e->getMessage());
            return 1;
        }
    }
}
