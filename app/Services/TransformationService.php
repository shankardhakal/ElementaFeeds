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

        // --- Handle Special Cases & Data Cleaning ---
        $this->handlePrice($payload);
        $this->handleImages($payload);

        // --- Handle Category Mapping ---
        $this->mapCategories($payload, $rawProduct, $fieldMappings, $categoryMappings);

        // --- Ensure button_text for external products ---
        if (
            (isset($payload['type']) && $payload['type'] === 'external') ||
            (!empty($payload['product_url']))
        ) {
            $payload['button_text'] = 'Katso tuotetta';
        }

        return $payload;
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