<?php

namespace App\Services;

use App\Models\FeedWebsite;
use App\Services\Api\ApiClientInterface;
use Illuminate\Support\Facades\Log;

class SyndicationService
{
    /**
     * Generate a Global Unique Product Identifier (GUPID)
     * 
     * @param int $connectionId The feed connection ID
     * @param string $sourceId The source product identifier
     * @return string The generated GUPID (40-character SHA-1 hash)
     */
    protected function generateGUPID(int $connectionId, string $sourceId): string
    {
        return sha1("connection_{$connectionId}_source_{$sourceId}");
    }

    /**
     * Takes a single transformed product and syndicates it to the destination website.
     * Uses GUPID-based approach - no SKU conflicts, relies on Global Unique Product Identifiers.
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
        $sourceIdentifier = $productData['sku'] ?? null;
        if (!$sourceIdentifier) {
            Log::warning("Product skipped because it is missing a mapped 'sku'. Please map a unique ID from your feed to the SKU field in the wizard.", [
                'connection_id' => $connection->id,
                'product_data' => $productData
            ]);
            return;
        }

        // Generate GUPID for unique identification
        $gupid = $this->generateGUPID($connection->id, $sourceIdentifier);

        // Add GUPID-based metadata for reconciliation
        $productData['meta_data'] = array_merge($productData['meta_data'] ?? [], [
            ['key' => 'gupid', 'value' => $gupid],
            ['key' => 'elementa_last_seen_timestamp', 'value' => now()->timestamp],
            ['key' => 'elementa_feed_connection_id', 'value' => $connection->id],
            ['key' => 'elementa_source_identifier', 'value' => $sourceIdentifier]
        ]);

        // Log the final data payload
        Log::info("Syndicating product with GUPID-based approach. Source SKU: {$sourceIdentifier}, GUPID: {$gupid}", [
            'connection_id' => $connection->id,
            'gupid' => $gupid,
            'final_payload' => $productData
        ]);

        // GUPID-based approach: always attempt upsert (create or update)
        // The API client will handle whether to create or update based on GUPID lookup
        try {
            $result = $apiClient->upsertProductsByGUPID([$productData]);
            
            if ($result['success']) {
                Log::info("Successfully syndicated product", [
                    'source_sku' => $sourceIdentifier,
                    'gupid' => $gupid,
                    'total_created' => $result['total_created'] ?? 0,
                    'total_updated' => $result['total_updated'] ?? 0,
                    'connection_id' => $connection->id
                ]);
            } else {
                Log::warning("Failed to syndicate product", [
                    'source_sku' => $sourceIdentifier,
                    'gupid' => $gupid,
                    'connection_id' => $connection->id,
                    'failed' => $result['failed'] ?? []
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Exception during product syndication", [
                'source_sku' => $sourceIdentifier,
                'gupid' => $gupid,
                'connection_id' => $connection->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}