<?php

namespace App\Services;

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

        // --- Enforce Core Product Attributes ---
        // Always create as an external product and start as a draft.
        $payload['type'] = 'external';
        $payload['status'] = 'draft';

        // --- Validate Essential Fields ---
        // An external product is useless without a URL and price.
        if (empty($payload['product_url']) || empty($payload['regular_price'])) {
            return []; // Return empty to skip this product. It will be logged as an error later.
        }

        // --- Handle Special Cases & Data Cleaning ---
        $this->handlePrice($payload);
        $this->handleImages($payload);

        // --- Handle Category Mapping ---
        $this->mapCategories($payload, $rawProduct, $fieldMappings, $categoryMappings);

        // --- Ensure button_text for external products ---
        // If not mapped, provide a sensible default.
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

        // 4. Handle Categories
        $categoryMappings = $connection->category_mappings ?? [];
        if (!empty($categoryMappings)) {
            // This logic needs to be robust based on how source categories are provided.
            // Assuming a simple 1-to-1 mapping for now.
            // The structure for the API is an array of objects, e.g., [['id' => 123], ['id' => 456]]
            $transformed['categories'] = array_map(function($id) {
                return ['id' => $id];
            }, array_values($categoryMappings));
        }

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
        // Map source fields to WooCommerce API fields
        $apiMap = [
            'name'              => 'name',            // Product Title
            'description'       => 'description',      // Product Description (long)
            'short_description' => 'short_description',// Product Short Description
            'sku'               => 'sku',
            'regular_price'     => 'regular_price',
            'sale_price'        => 'sale_price',
            'stock_quantity'    => 'stock_quantity',
            'images'            => 'images',
            'product_url'       => 'product_url',      // External/Affiliate URL
            'button_text'       => 'button_text',      // Buy button text
            // This is the source feed column that contains the category string
            'source_category'   => 'product_type',
        ];

        foreach ($apiMap as $apiField => $wizardField) {
            $sourceField = $fieldMappings[$wizardField] ?? null;
            if ($sourceField && isset($rawProduct[$sourceField])) {
                $payload[$apiField] = $rawProduct[$sourceField];
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
        $sourceCategoryField = $fieldMappings['product_type'] ?? null;
        if (!$sourceCategoryField || !isset($rawProduct[$sourceCategoryField])) {
            return;
        }

        $productCategoryString = $rawProduct[$sourceCategoryField];
        $payload['categories'] = [];

        foreach ($categoryMappings as $mapping) {
            $sourceCat = $mapping['source'] ?? null;
            $destId = $mapping['dest'] ?? null;

            // Check if the product's category string from the feed contains the source category defined in our mapping
            if ($sourceCat && $destId && str_contains($productCategoryString, $sourceCat)) {
                $payload['categories'][] = ['id' => $destId];
                // We break after the first match to prevent mapping to multiple parent categories.
                // This can be adjusted if multi-mapping is needed.
                break;
            }
        }
    }
}