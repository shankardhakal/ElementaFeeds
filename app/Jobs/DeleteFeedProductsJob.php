<?php

namespace App\Jobs;

use App\Models\Feed;
use App\Models\FeedWebsite;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * DeleteFeedProductsJob
 *
 * This job is responsible for deleting products from the destination platform (e.g., WooCommerce) that are no longer part of the feed.
 *
 * Key Tasks:
 * - Identifies products to be deleted based on the feed data.
 * - Sends delete requests to the destination platform's API.
 */
class DeleteFeedProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $connectionId;

    /**
     * Create a new job instance.
     *
     * @param int $connectionId The feed_website connection ID
     */
    public function __construct(int $connectionId)
    {
        $this->connectionId = $connectionId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $connection = FeedWebsite::with(['feed', 'website'])
                ->findOrFail($this->connectionId);

            $apiClient = new WooCommerceApiClient($connection->website);

            // Find all products with this connection ID using the new stateless approach
            $productsToDelete = $apiClient->findProductsByConnectionId($this->connectionId);

            if (empty($productsToDelete)) {
                Log::info("No products found for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}) to delete.");
                return;
            }

            Log::info("Found " . count($productsToDelete) . " products to delete for connection #{$this->connectionId} ({$connection->feed->name} → {$connection->website->name}).");

            // Delete products in batches
            $batchSize = $this->getRecommendedBatchSize($connection->website_id);
            $batches = array_chunk($productsToDelete, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                try {
                    Log::info("Deleting batch #" . ($batchIndex + 1) . " with " . count($batch) . " products for connection #{$this->connectionId}");

                    // The API expects an array of IDs for deletion.
                    $apiClient->batchProducts(['delete' => $batch]);

                    Log::info("Successfully deleted batch #" . ($batchIndex + 1) . " for connection #{$this->connectionId}");
                } catch (\Throwable $e) {
                    Log::error("Failed to delete batch #" . ($batchIndex + 1) . " for connection #{$this->connectionId}: " . $e->getMessage());
                    // Continue with other batches instead of failing completely
                }
            }
        } catch (\Throwable $e) {
            Log::error("Failed to delete products for connection ID {$this->connectionId}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Get the recommended batch size for a specific website
     */
    protected function getRecommendedBatchSize(int $websiteId): int
    {
        $cacheKey = "batch_size:website:{$websiteId}";
        // A lower batch size for deletion might be safer.
        return Cache::get($cacheKey, 25);
    }
}
