<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestWooCommerceConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:test-connection {website_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the WooCommerce API connection for a specific website or all websites';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websiteId = $this->argument('website_id');
        
        if ($websiteId) {
            // Test a specific website
            $website = Website::find($websiteId);
            
            if (!$website) {
                $this->error("Website with ID {$websiteId} not found.");
                return 1;
            }
            
            $this->testWebsiteConnection($website);
        } else {
            // Test all websites with WooCommerce credentials
            $websites = Website::whereNotNull('woocommerce_credentials')->get();
            
            if ($websites->isEmpty()) {
                $this->info("No websites with WooCommerce credentials found.");
                return 0;
            }
            
            $this->info("Testing connection for {$websites->count()} websites...");
            
            foreach ($websites as $website) {
                $this->testWebsiteConnection($website);
                $this->newLine();
            }
        }
        
        return 0;
    }
    
    /**
     * Test the connection for a specific website.
     */
    private function testWebsiteConnection(Website $website)
    {
        $this->info("Testing connection for website #{$website->id}: {$website->name} ({$website->url})");
        
        try {
            $apiClient = new WooCommerceApiClient($website);
            
            // Test the connection
            $result = $apiClient->testConnection();
            
            if ($result['success']) {
                $this->info("✅ Connection successful!");
                $this->info("   Response time: {$result['execution_time_ms']}ms");
                
                if (isset($result['response']['products_count'])) {
                    $this->info("   Products found: {$result['response']['products_count']}");
                }
                
                // Also test the health check
                $health = $apiClient->checkApiHealth();
                
                $this->info("   Health status: {$health['status']}");
                
                if (!empty($health['issues'])) {
                    $this->warn("   Health issues found:");
                    foreach ($health['issues'] as $issue) {
                        $this->warn("     - {$issue}");
                    }
                }
                
                if (isset($health['response_time'])) {
                    $this->info("   Health check response time: {$health['response_time']}ms");
                }
                
                // Try to read categories as well
                try {
                    $categories = $apiClient->getCategories();
                    $this->info("   Categories found: " . count($categories));
                } catch (\Throwable $e) {
                    $this->warn("   Could not fetch categories: " . $e->getMessage());
                }
            } else {
                $this->error("❌ Connection failed!");
                $this->error("   Message: {$result['message']}");
                
                if (isset($result['error'])) {
                    if (is_array($result['error'])) {
                        $this->error("   Error details:");
                        foreach ($result['error'] as $key => $value) {
                            if (is_string($value) || is_numeric($value)) {
                                $this->error("     - {$key}: {$value}");
                            } else {
                                $this->error("     - {$key}: " . json_encode($value));
                            }
                        }
                    } else {
                        $this->error("   Error: {$result['error']}");
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->error("❌ Exception occurred during connection test!");
            $this->error("   Exception: " . get_class($e));
            $this->error("   Message: " . $e->getMessage());
            
            // Log full exception details
            Log::error("Exception during WooCommerce connection test for website #{$website->id}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
