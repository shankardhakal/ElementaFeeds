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

    public function __construct(public Website $website)
    {
    }

    /**
     * The unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->website->id;
    }

    public function handle(): void
    {
        Log::info("Starting API connection test for Website #{$this->website->id} ({$this->website->name}).");

        try {
            $apiClient = ($this->website->platform === 'woocommerce')
                ? new WooCommerceApiClient($this->website)
                : new WordPressApiClient($this->website);
            
            Log::info("Testing with client: " . get_class($apiClient));
            
            // This test call will now throw a specific exception on failure.
            $apiClient->getCategories();

            // This line will only be reached if no exception was thrown.
            $this->website->connection_status = 'ok';
            Log::info("API test SUCCESS for Website #{$this->website->id}.");

        } catch (\Exception $e) {
            // The catch block will now receive meaningful error messages.
            $this->website->connection_status = 'failed';
            Log::error("API test FAILED for Website #{$this->website->id}: " . $e->getMessage());
        }
        
        $this->website->last_checked_at = now();
        $this->website->saveQuietly();
    }
}