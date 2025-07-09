<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestWooCommerceProductCreation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:test-product-creation {website_id : The ID of the website to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test WooCommerce product creation by creating a test product and verifying it exists';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websiteId = $this->argument('website_id');
        
        try {
            $website = Website::findOrFail($websiteId);
            
            $this->info("Testing product creation for website #{$website->id}: {$website->name} ({$website->url})");
            
            // Check API credentials
            if (empty($website->woocommerce_credentials)) {
                $this->error("❌ Website has no WooCommerce credentials configured");
                return 1;
            }
            
            // Create API client
            $apiClient = new WooCommerceApiClient($website);
            
            // First run the connection test
            $connectionTest = $apiClient->testConnection();
            
            if (!$connectionTest['success']) {
                $this->error("❌ Connection test failed: {$connectionTest['message']}");
                $this->line("Error details: " . json_encode($connectionTest['error'] ?? [], JSON_PRETTY_PRINT));
                return 1;
            }
            
            $this->info("✅ Connection successful!");
            $this->line("   Response time: {$connectionTest['execution_time_ms']}ms");
            $this->line("   Products found: {$connectionTest['response']['products_count']}");
            
            // Now test product creation
            $this->info("\nAttempting to create a test product...");
            
            $result = $apiClient->testCreateSingleProduct();
            
            if ($result['success']) {
                $this->info("✅ Test product created successfully!");
                $this->line("   Product ID: {$result['product']['id']}");
                $this->line("   Product Name: {$result['product']['name']}");
                $this->line("   Product SKU: {$result['product']['sku']}");
                $this->line("   Product Status: {$result['product']['status']}");
                $this->line("   Creation Time: {$result['execution_time_ms']}ms");
                
                // Verification results
                if ($result['verification']['success']) {
                    $this->info("✅ Product verification successful!");
                    $this->line("   Status: {$result['verification']['status']}");
                } else {
                    $this->error("❌ Product verification failed!");
                    $this->line("   Message: {$result['verification']['message']}");
                }
            } else {
                $this->error("❌ Failed to create test product: {$result['error']}");
                return 1;
            }
            
            return 0;
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error("Error in test product creation command: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
