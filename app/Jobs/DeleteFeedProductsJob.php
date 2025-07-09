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

    public int $feedId;
    public int $websiteId;

    /**
     * Create a new job instance.
     *
     * @param int $feedId
     * @param int $websiteId
     */
    public function __construct(int $feedId, int $websiteId)
    {
        $this->feedId = $feedId;
        $this->websiteId = $websiteId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $feed = Feed::findOrFail($this->feedId);
            $feedWebsite = FeedWebsite::where('feed_id', $this->feedId)
                ->where('website_id', $this->websiteId)
                ->firstOrFail();

            $apiClient = new WooCommerceApiClient($feedWebsite->website);

            // Find all products with the feed_name metadata
            $productsToDelete = $apiClient->findProductsByMetadata('feed_name', $feed->name);

            if (empty($productsToDelete)) {
                Log::info("No products found for feed '{$feed->name}' (ID: {$this->feedId}) on website ID {$this->websiteId} to delete.");
                return;
            }

            $productIdsToDelete = array_column($productsToDelete, 'id');
            Log::info("Found " . count($productIdsToDelete) . " products to delete for feed '{$feed->name}' (ID: {$this->feedId}) on website ID {$this->websiteId}.");

            // Delete products in batches
            $batchSize = $this->getRecommendedBatchSize($this->websiteId);
            $batches = array_chunk($productIdsToDelete, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                try {
                    Log::info("Deleting batch #" . ($batchIndex + 1) . " with " . count($batch) . " products for feed '{$feed->name}'");

                    // The API expects an array of IDs for deletion.
                    $apiClient->batchProducts(['delete' => $batch]);

                    Log::info("Successfully deleted batch #" . ($batchIndex + 1) . " for feed '{$feed->name}'");
                } catch (\Throwable $e) {
                    Log::error("Failed to delete batch #" . ($batchIndex + 1) . " for feed '{$feed->name}': " . $e->getMessage());
                    // Optional: Decide if you want to retry the job or skip the batch
                }
            }
        } catch (\Throwable $e) {
            Log::error("Failed to delete products for feed ID {$this->feedId} on website ID {$this->websiteId}: " . $e->getMessage());
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
