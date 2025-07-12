<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;

class CheckWooCommerceProductStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:check-product-status {website_id : The ID of the website to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the status of products in a WooCommerce website';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websiteId = $this->argument('website_id');
        
        $website = Website::find($websiteId);
        
        if (!$website) {
            $this->error("Website not found with ID: {$websiteId}");
            return 1;
        }
        
        $this->info("Checking WooCommerce product status for website #{$websiteId}: {$website->name} ({$website->url})");
        
        $apiClient = new WooCommerceApiClient($website);
        
        // First, check connection
        $this->info("Testing connection...");
        $connectionTest = $apiClient->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error("❌ Connection failed: " . $connectionTest['message']);
            $this->error("Error details: " . json_encode($connectionTest['error'] ?? 'Unknown error'));
            return 1;
        }
        
        $this->info("✅ Connection successful!");
        $this->info("   Response time: " . ($connectionTest['execution_time_ms'] ?? 'N/A') . "ms");
        $this->info("   Products found: " . ($connectionTest['response']['products_count'] ?? 'N/A'));
        
        // Get product counts
        $this->info("\nProduct counts by status:");
        $productCounts = $apiClient->getProductCounts();
        
        foreach ($productCounts as $status => $count) {
            $this->info("   {$status}: {$count}");
        }
        
        // If there are draft products, offer to publish them
        if (($productCounts['draft'] ?? 0) > 0) {
            if ($this->confirm("Do you want to publish {$productCounts['draft']} draft products?", false)) {
                $this->info("Publishing draft products...");
                
                // This would require implementing a method to update product statuses
                // For now, just show a message
                $this->info("Feature not yet implemented. Please use the WooCommerce admin interface to publish draft products.");
            }
        }
        
        // Create a test product
        if ($this->confirm("Do you want to create a test product?", false)) {
            $this->info("Creating a test product...");
            
            $testResult = $apiClient->createTestProduct();
            
            if ($testResult['success']) {
                $this->info("✅ Test product created successfully!");
                $this->info("   Product ID: " . $testResult['product']['id']);
                $this->info("   Product Name: " . $testResult['product']['name']);
                $this->info("   Product SKU: " . $testResult['product']['sku']);
                $this->info("   Product Status: " . $testResult['product']['status']);
                $this->info("   Creation Time: " . $testResult['execution_time_ms'] . "ms");
                
                // Verify product using GUPID-based system
                $this->info("\nVerifying test product...");
                sleep(1); // Wait a moment for indexing
                
                // Since we need connection ID for GUPID lookup, we'll use a generic approach
                // Try to find the product by ID instead
                try {
                    $verifyResult = $apiClient->makeRequest("products/{$testResult['product']['id']}");
                    
                    if ($verifyResult && isset($verifyResult['id'])) {
                        $this->info("✅ Product verification successful!");
                        $this->info("   Status: " . $verifyResult['status']);
                        $this->info("   ID: " . $verifyResult['id']);
                    } else {
                        $this->error("❌ Product verification failed! The product could not be found by ID.");
                    }
                } catch (\Exception $e) {
                    $this->error("❌ Product verification failed with error: " . $e->getMessage());
                }
            } else {
                $this->error("❌ Failed to create test product: " . ($testResult['error'] ?? 'Unknown error'));
            }
        }
        
        return 0;
    }
}
