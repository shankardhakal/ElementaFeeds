<?php

namespace App\Services;

use App\Models\FeedWebsite;
use App\Models\SyndicatedProduct;
use App\Services\Api\ApiClientInterface;
use Illuminate\Support\Facades\Log;

class SyndicationService
{
    /**
     * Takes a single transformed product and decides whether to create or update it
     * on the destination website.
     */
    public function syndicate(array $productData, FeedWebsite $connection, ApiClientInterface $apiClient): void
    {
        // If a product_url is mapped, we automatically set the product type to 'external'.
        // The 'button_text' can also be mapped by the user in the wizard.
        if (!empty($productData['product_url'])) {
            $productData['type'] = 'external';
        }

        // Handle image galleries. If the 'images' field is a comma-separated string,
        // convert it into the array structure WooCommerce expects.
        if (!empty($productData['images']) && is_string($productData['images'])) {
            $imageUrls = array_map('trim', explode(',', $productData['images']));
            $productData['images'] = array_map(function ($url) {
                return ['src' => $url];
            }, $imageUrls);
        }

        // The 'sku' is the most reliable unique identifier for WooCommerce.
        // We will now explicitly log if it's missing from the transformed data.
        $sourceIdentifier = $productData['sku'] ?? null;
        if (!$sourceIdentifier) {
            Log::warning("Product skipped because it is missing a mapped 'sku'. Please map a unique ID from your feed to the SKU field in the wizard.", [
                'connection_id' => $connection->id,
                'product_data' => $productData
            ]);
            return;
        }

        // Log the final data payload before we do anything else.
        Log::info("Syndicating product. Source SKU: {$sourceIdentifier}", [
            'connection_id' => $connection->id,
            'final_payload' => $productData
        ]);

        $trackedProduct = SyndicatedProduct::where('feed_website_id', $connection->id)
            ->where('source_product_identifier', $sourceIdentifier)
            ->first();

        $productHash = md5(json_encode($productData));

        if ($trackedProduct) {
            // --- UPDATE LOGIC ---
            if ($trackedProduct->last_updated_hash !== $productHash) {
                Log::info("Attempting to UPDATE product on destination.", [
                    'source_sku' => $sourceIdentifier,
                    'destination_id' => $trackedProduct->destination_product_id
                ]);
                $apiClient->updateProduct($trackedProduct->destination_product_id, $productData);
                $trackedProduct->update(['last_updated_hash' => $productHash]);
            } else {
                Log::info("Product is already up-to-date. Skipping update.", ['source_sku' => $sourceIdentifier]);
            }
        } else {
            // --- CREATE LOGIC ---
            Log::info("Attempting to CREATE product on destination.", ['source_sku' => $sourceIdentifier]);
            $newDestinationId = $apiClient->createProduct($productData);

            if ($newDestinationId) {
                Log::info("Successfully created product on destination.", [
                    'source_sku' => $sourceIdentifier,
                    'new_destination_id' => $newDestinationId
                ]);
                SyndicatedProduct::create([
                    'feed_website_id' => $connection->id,
                    'source_product_identifier' => $sourceIdentifier,
                    'destination_product_id' => $newDestinationId,
                    'last_updated_hash' => $productHash,
                ]);
            } else {
                 Log::error("Failed to create product on destination. The API did not return a new ID.", [
                    'source_sku' => $sourceIdentifier,
                    'connection_id' => $connection->id
                ]);
            }
        }
    }

    protected function getClient(Website $website): Client
    {
        $clientOptions = [
            'base_uri' => $website->url,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($website->api_key . ':' . $website->api_secret),
            ],
            'timeout' => 300,
        ];

        if ($website->uses_staging_environment) {
            $clientOptions['verify'] = false; // Disable SSL verification for staging
        }

        return new Client($clientOptions);
    }
}