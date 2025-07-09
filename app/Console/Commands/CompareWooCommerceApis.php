<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CompareWooCommerceApis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce:compare-apis {website_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compare direct vs batch API product creation in WooCommerce';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $websiteId = $this->argument('website_id');
        
        $website = Website::find($websiteId);
        if (!$website) {
            $this->error("Website with ID {$websiteId} not found");
            return 1;
        }
        
        $this->info("Comparing WooCommerce APIs for website #{$websiteId}: {$website->name} ({$website->url})");
        
        $apiClient = new WooCommerceApiClient($website);
        
        // First test connection
        $this->info("Testing connection...");
        $connectionTest = $apiClient->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error("❌ Connection failed: " . $connectionTest['message']);
            return 1;
        }
        
        $this->info("✅ Connection successful!");
        $this->info("   Response time: " . ($connectionTest['execution_time_ms'] ?? 'N/A') . "ms");
        $this->info("   Products found: " . ($connectionTest['response']['products_count'] ?? 0));
        
        // Run the comparison test
        $this->info("\nComparing direct API vs batch API...");
        $results = $apiClient->diagnoseBatchVsDirectCreation();
        
        if (isset($results['error'])) {
            $this->error("❌ Comparison failed: " . $results['error']);
            return 1;
        }
        
        // Direct API results
        if ($results['direct']['success']) {
            $this->info("✅ Direct API: Product created successfully");
            $this->info("   Product ID: " . $results['direct']['product_id']);
            $this->info("   Status: " . $results['direct']['status']);
            $this->info("   Execution time: " . $results['direct']['execution_time_ms'] . "ms");
        } else {
            $this->error("❌ Direct API: Failed to create product");
        }
        
        // Batch API results
        if ($results['batch']['success']) {
            $this->info("\n✅ Batch API: Product created successfully");
            $this->info("   Product ID: " . $results['batch']['product_id']);
            $this->info("   Status: " . $results['batch']['status']);
            $this->info("   Execution time: " . $results['batch']['execution_time_ms'] . "ms");
        } else {
            $this->error("\n❌ Batch API: Failed to create product");
        }
        
        // Differences
        if (!empty($results['differences'])) {
            $this->info("\nDifferences found between direct and batch API responses:");
            foreach ($results['differences'] as $diff) {
                $this->line("   - {$diff['field']}: Direct=\"{$diff['direct_value']}\" vs Batch=\"{$diff['batch_value']}\"");
            }
        } else {
            $this->info("\nNo significant differences found between the API responses.");
        }
        
        $this->info("\nComparison complete. Check the logs for more details.");
        return 0;
    }
}
