<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Support\Facades\Log;

class WooCommerceFixProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:fix-products 
                            {website_id : The ID of the website to fix products for}
                            {--status=publish : Status to set products to}
                            {--limit=100 : Maximum number of products to fix}
                            {--delete : Delete all products instead of fixing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix product visibility issues in WooCommerce';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websiteId = $this->argument('website_id');
        $status = $this->option('status');
        $limit = $this->option('limit');
        $delete = $this->option('delete');
        
        $website = Website::find($websiteId);
        
        if (!$website) {
            $this->error("Website with ID {$websiteId} not found");
            return 1;
        }
        
        if ($website->platform !== 'woocommerce') {
            $this->error("Website is not a WooCommerce website");
            return 1;
        }
        
        $this->info("Working with website #{$websiteId}: {$website->name} ({$website->url})");
        
        // Create API client
        $apiClient = new WooCommerceApiClient($website);
        
        // Test connection
        $connectionTest = $apiClient->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error("Connection failed: " . ($connectionTest['message'] ?? 'Unknown error'));
            return 1;
        }
        
        $this->info("✅ Connection successful!");
        $this->info("   Response time: " . ($connectionTest['execution_time_ms'] ?? 'N/A') . "ms");
        $this->info("   Products found: " . ($connectionTest['response']['products_count'] ?? 0));
        
        // If no products found, try to create a test product
        if (($connectionTest['response']['products_count'] ?? 0) < 1) {
            $this->info("\nNo products found. Creating a test product...");
            
            $testResult = $apiClient->createTestProduct();
            
            if (!$testResult['success']) {
                $this->error("❌ Failed to create test product: " . ($testResult['error'] ?? 'Unknown error'));
                return 1;
            }
            
            $this->info("✅ Test product created successfully!");
            $this->info("   Product ID: " . $testResult['product']['id']);
            $this->info("   Product Name: " . $testResult['product']['name']);
            $this->info("   Product Status: " . $testResult['product']['status']);
            $this->info("   Creation Time: " . ($testResult['execution_time_ms'] ?? 'N/A') . "ms");
            
            // Try to verify the product immediately
            try {
                $verifyResult = $apiClient->findProductBySKU($testResult['product']['sku']);
                
                if ($verifyResult) {
                    $this->info("✅ Product verification successful!");
                    $this->info("   Status: " . $verifyResult['status']);
                } else {
                    $this->warn("⚠️ Product verification failed - product not found by SKU");
                }
            } catch (\Throwable $e) {
                $this->error("❌ Exception during product verification: " . $e->getMessage());
            }
            
            return 0;
        }
        
        // Get all products
        $this->info("\nFetching product information...");
        
        try {
            // Fetch 100 products per page
            $perPage = min(100, $limit);
            $page = 1;
            $totalProducts = 0;
            $allProducts = [];
            
            while (true) {
                $products = $apiClient->makeRequest('products', [
                    'per_page' => $perPage,
                    'page' => $page
                ]);
                
                if (empty($products)) {
                    break;
                }
                
                $allProducts = array_merge($allProducts, $products);
                $totalProducts += count($products);
                
                $this->info("   Fetched " . count($products) . " products (page {$page})");
                
                if (count($products) < $perPage || $totalProducts >= $limit) {
                    break;
                }
                
                $page++;
            }
            
            // Count statuses
            $statusCounts = [];
            
            foreach ($allProducts as $product) {
                $productStatus = $product['status'] ?? 'unknown';
                $statusCounts[$productStatus] = ($statusCounts[$productStatus] ?? 0) + 1;
            }
            
            $this->info("\nProduct counts by status:");
            foreach ($statusCounts as $statusName => $count) {
                $this->info("   {$statusName}: {$count}");
            }
            $this->info("   Total: " . count($allProducts));
            
            // If delete option is set
            if ($delete) {
                if ($this->confirm("Are you sure you want to delete all " . count($allProducts) . " products?", false)) {
                    $this->info("\nDeleting products...");
                    $deleted = 0;
                    
                    foreach ($allProducts as $product) {
                        try {
                            $apiClient->makeRequest("products/{$product['id']}", [], 'DELETE');
                            $deleted++;
                            
                            $this->info("   Deleted product #{$product['id']} ({$product['name']})");
                        } catch (\Throwable $e) {
                            $this->error("   Failed to delete product #{$product['id']}: " . $e->getMessage());
                        }
                    }
                    
                    $this->info("\nDeleted {$deleted} out of " . count($allProducts) . " products");
                    return 0;
                } else {
                    $this->info("Operation cancelled.");
                    return 0;
                }
            }
            
            // Find products that need fixing
            $productsToFix = array_filter($allProducts, function($product) use ($status) {
                return ($product['status'] ?? '') !== $status || ($product['catalog_visibility'] ?? '') !== 'visible';
            });
            
            if (empty($productsToFix)) {
                $this->info("\n✅ All products have correct status and visibility settings!");
                return 0;
            }
            
            $this->info("\nFound " . count($productsToFix) . " products that need fixing");
            
            if ($this->confirm("Would you like to fix these products?", true)) {
                $fixed = 0;
                $failed = 0;
                
                foreach ($productsToFix as $product) {
                    $this->info("   Fixing product #{$product['id']} ({$product['name']})...");
                    
                    try {
                        $apiClient->updateProduct($product['id'], [
                            'status' => $status,
                            'catalog_visibility' => 'visible'
                        ]);
                        
                        $fixed++;
                        $this->info("   ✅ Fixed product #{$product['id']}");
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("   ❌ Failed to fix product #{$product['id']}: " . $e->getMessage());
                    }
                }
                
                $this->info("\n✅ Fixed {$fixed} products");
                
                if ($failed > 0) {
                    $this->warn("⚠️ Failed to fix {$failed} products");
                }
            } else {
                $this->info("Operation cancelled.");
            }
            
            return 0;
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }
}
