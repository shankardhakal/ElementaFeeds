<?php

namespace App\Services\Api;

interface ApiClientInterface
{
    public function getCategories(): array;
    public function getAttributes(): array;
    public function createProduct(array $data): ?string;
    public function updateProduct(string $destinationId, array $data): void;
    public function findProductBySKU(string $sku): ?array;
    public function getProductIdMapBySkus(array $skus): array;
    public function upsertProducts(array $products): array;
}