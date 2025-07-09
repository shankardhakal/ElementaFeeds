<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TransformationService
{
    /**
     * Transforms a raw product record from the feed into a structured format
     * ready for the WooCommerce API, based on mapping rules.
     *
     * @param array $rawProduct The raw product data from a feed row.
     * @param array|null $fieldMappings The field mapping rules from the connection.
     * @param array|null $categoryMappings The category mapping rules from the connection.
     * @return array The transformed product data payload.
     */
    public function transform(array $rawProduct, ?array $fieldMappings, ?array $categoryMappings): array
    {
        $payload = [];
        $fieldMappings = $fieldMappings ?? [];
        $categoryMappings = $categoryMappings ?? [];

        // --- Standard Field Mapping ---
        $this->mapStandardFields($payload, $rawProduct, $fieldMappings);

        // --- Robust external_url fallback ---
        // Try all possible URL fields in the payload, use the first non-empty one as external_url
        $urlFields = ['external_url', 'product_url', 'url', 'link'];
        foreach ($urlFields as $urlField) {
            if (!empty($payload[$urlField])) {
                $payload['external_url'] = $payload[$urlField];
                break;
            }
        }

        // --- Handle Special Cases & Data Cleaning ---
        $this->handlePrice($payload);
        $this->handleImages($payload);

        // --- Enforce Core Product Attributes ---
        $payload['type'] = 'external';
        $payload['status'] = 'draft';

        // --- Debug log before essential field check ---
        Log::debug('Payload before essential field validation', [
            'payload' => $payload,
            'field_mappings' => $fieldMappings,
            'raw_product' => $rawProduct,
        ]);

        // --- Validate Essential Fields ---
        // An external product is useless without a URL and price.
        if (empty($payload['external_url']) || empty($payload['regular_price']) || empty($payload['name'])) {
            Log::warning('Skipping product due to missing essential fields after transformation.', [
                'reason' => 'Missing external_url, regular_price, or name.',
                'missing_external_url' => empty($payload['external_url']),
                'missing_regular_price' => empty($payload['regular_price']),
                'missing_name' => empty($payload['name']),
                'payload_after_mapping' => $payload,
                'field_mappings' => $fieldMappings,
                'raw_product_keys' => array_keys($rawProduct),
            ]);
            return []; // Return empty to skip this product. It will be logged as an error later.
        }

        // --- Handle Category Mapping ---
        $this->mapCategories($payload, $rawProduct, $fieldMappings, $categoryMappings);
        // Skip any product that isn't mapped to a category
        if (empty($payload['categories'])) {
            Log::debug('Skipping product: no category mapping in connection.', [
                'raw_product' => $rawProduct,
            ]);
            return [];
        }

        // --- Ensure button_text for external products ---
        if (empty($payload['button_text'])) {
            $payload['button_text'] = 'View Product';
        }

        return $payload;
    }

    /**
     * This is a legacy method and will be removed. The new transform method handles all logic.
     * @deprecated
     */
    public function transform_legacy(array $record, FeedWebsite $connection): array
    {
        // This method is no longer in use and is kept for reference during transition.
        // It will be removed in a future cleanup.
        $fieldMappings = $connection->field_mappings ?? [];
        $transformed = [];

        // 1. Basic Field Mapping
        foreach ($fieldMappings as $destinationField => $sourceField) {
            if (!empty($sourceField) && isset($record[$sourceField])) {
                $transformed[$destinationField] = $record[$sourceField];
            }
        }

        // 2. Set Product Type and Status
        // Always create as an external product and start as a draft.
        $transformed['type'] = 'external';
        $transformed['status'] = 'draft';

        // 3. Ensure required fields for an external product are present.
        // If the product URL or button text weren't mapped, we can't create it.
        if (empty($transformed['product_url']) || empty($transformed['button_text'])) {
            // Returning an empty array will cause this product to be skipped.
            return []; 
        }

        // 4. Handle Categories using the robust mapping system
        $this->mapCategories($transformed, $rawProduct, $fieldMappings, $categoryMappings);

        // 5. Handle Images
        // The API expects a specific format for images.
        if (!empty($transformed['images'])) {
            // Assuming 'images' is a comma-separated string of URLs.
            $imageUrls = array_map('trim', explode(',', $transformed['images']));
            $transformed['images'] = array_map(function($url) {
                return ['src' => $url];
            }, $imageUrls);
        }

        return $transformed;
    }

    private function mapStandardFields(array &$payload, array $rawProduct, array $fieldMappings): void
    {
        // This is the definitive fix. The previous implementation was logically flawed.
        // The `$fieldMappings` array has the destination field as the KEY and the source field as the VALUE.
        // Example: ['name' => 'Product Title', 'sku' => 'ProductID']

        // We iterate through all the mappings provided by the user.
        foreach ($fieldMappings as $destinationField => $sourceField) {
            // Check if the source field specified in the mapping exists in the raw product data from the feed.
            if (!empty($sourceField) && isset($rawProduct[$sourceField])) {
                // If it exists, assign the value to the payload, using the correct destination field as the key.
                $payload[$destinationField] = $rawProduct[$sourceField];
            }
        }
    }

    private function handlePrice(array &$payload): void
    {
        // Clean up price fields to remove currency symbols, commas, etc.
        if (isset($payload['regular_price'])) {
            $payload['regular_price'] = preg_replace('/[^0-9.]/', '', $payload['regular_price']);
        }
        if (isset($payload['sale_price'])) {
            $payload['sale_price'] = preg_replace('/[^0-9.]/', '', $payload['sale_price']);
        }
    }

    private function handleImages(array &$payload): void
    {
        // Transform a comma-separated image URL string into the array format the API expects.
        if (!empty($payload['images']) && is_string($payload['images'])) {
            $urls = array_map('trim', explode(',', $payload['images']));
            $payload['images'] = array_map(fn($url) => ['src' => $url], $urls);
        }
    }

    private function mapCategories(array &$payload, array $rawProduct, array $fieldMappings, array $categoryMappings): void
    {
        // If we have a direct categoryId from the ProcessChunkJob, use that
        if (!empty($rawProduct['__mappedCategoryId'])) {
            $payload['categories'] = [['id' => $rawProduct['__mappedCategoryId']]];
            return;
        }
        
        // Otherwise try to map using the legacy approach
        $categoryMappingArray = [];
        foreach ($categoryMappings as $mapping) {
            if (isset($mapping['source']) && isset($mapping['dest'])) {
                $categoryMappingArray[$mapping['source']] = $mapping['dest'];
            }
        }
        
        if (empty($categoryMappingArray)) {
            // No mappings defined
            return;
        }
        
        // Try to find a matching category
        $payload['categories'] = [];
        
        // Try using the category_source_field if it exists in the raw product
        $categorySourceField = $rawProduct['__category_source_field'] ?? null;
        if ($categorySourceField && isset($rawProduct[$categorySourceField])) {
            $productCategoryString = $rawProduct[$categorySourceField];
            
            // First try direct match
            if (isset($categoryMappingArray[$productCategoryString])) {
                $payload['categories'][] = ['id' => $categoryMappingArray[$productCategoryString]];
                return;
            }
            
            // Try fuzzy matching
            foreach ($categoryMappingArray as $sourceCat => $destId) {
                if (stripos($productCategoryString, $sourceCat) !== false) {
                    $payload['categories'][] = ['id' => $destId];
                    // Found a match, we're done
                    return;
                }
            }
        }
        
        // Fallback to using product_type field as a last resort
        $sourceCategoryField = $fieldMappings['product_type'] ?? null;
        if ($sourceCategoryField && isset($rawProduct[$sourceCategoryField])) {
            $productCategoryString = $rawProduct[$sourceCategoryField];
            
            foreach ($categoryMappingArray as $sourceCat => $destId) {
                if (stripos($productCategoryString, $sourceCat) !== false) {
                    $payload['categories'][] = ['id' => $destId];
                    break;
                }
            }
        }
    }

    /**
     * Generate a unique identifier for a product.
     *
     * @param array $rawProduct The raw product data.
     * @param string $feedName The name of the feed.
     * @return string The unique identifier.
     */
    public function generateUniqueIdentifier(array $rawProduct, string $feedName): string
    {
        $sourceId = $rawProduct['id'] ?? 'unknown';
        return $feedName . ':' . $sourceId;
    }
}