<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use App\Services\Api\WordPressApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * TestApiConnectionJob
 *
 * This job tests the API connection for a given website.
 *
 * Key Tasks:
 * - Fetches the Website model using the provided website ID.
 * - Determines the appropriate API client based on the platform (WooCommerce or WordPress).
 * - Logs the results of the API connection test.
 */
class TestApiConnectionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public int $uniqueFor = 300; // 5 minutes

    protected int $websiteId;

    /**
     * Create a new job instance.
     *
     * @param int $websiteId The ID of the website to test the API connection for.
     */
    public function __construct(int $websiteId)
    {
        $this->websiteId = $websiteId;
    }

    /**
     * The unique ID for the job.
     */
    public function uniqueId(): string
    {
        return (string)$this->websiteId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $website = Website::find($this->websiteId);
        if (!$website) {
            Log::error("TestApiConnectionJob: Could not find Website with ID #{$this->websiteId}");
            return;
        }

        Log::info("Starting API connection test for Website #{$website->id} ({$website->name}).");

        try {
            $apiClient = ($website->platform === 'woocommerce')
                ? new WooCommerceApiClient($website)
                : new WordPressApiClient($website);
            
            Log::info("Testing with client: " . get_class($apiClient));
            
            // Run test connection first
            if ($website->platform === 'woocommerce') {
                $connectionTest = $apiClient->testConnection();
                
                if (!$connectionTest['success']) {
                    throw new \Exception("WooCommerce API connection failed: " . ($connectionTest['message'] ?? 'Unknown error'));
                }
                
                Log::info("WooCommerce API connection test successful", [
                    'response_time' => $connectionTest['execution_time_ms'] ?? 'N/A',
                    'products_count' => $connectionTest['response']['products_count'] ?? 0
                ]);
                
                // Also check for product statuses if any products exist
                if (($connectionTest['response']['products_count'] ?? 0) > 0) {
                    try {
                        // This get categories call will throw an exception if there's an issue
                        $categories = $apiClient->getCategories();
                        
                        Log::info("Successfully retrieved " . count($categories) . " categories from WooCommerce");
                    } catch (\Exception $e) {
                        Log::warning("Failed to get categories: " . $e->getMessage());
                        // Continue with the test, don't throw an exception here
                    }
                }
            } else {
                // For other platforms, just test categories
                $apiClient->getCategories();
            }

            // This line will only be reached if no exception was thrown.
            $website->connection_status = 'ok';
            Log::info("API test SUCCESS for Website #{$website->id}.");

        } catch (\Exception $e) {
            // The catch block will now receive meaningful error messages.
            $website->connection_status = 'failed';
            Log::error("API test FAILED for Website #{$website->id}: " . $e->getMessage());
        }
        
        $website->last_checked_at = now();
        $website->saveQuietly();
    }
}