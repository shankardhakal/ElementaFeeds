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
            
            // This test call will now throw a specific exception on failure.
            $apiClient->getCategories();

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