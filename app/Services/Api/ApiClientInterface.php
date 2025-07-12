<?php

namespace App\Services\Api;

interface ApiClientInterface
{
    public function getCategories(): array;
    public function getAttributes(): array;
    public function createProduct(array $data): ?string;
    public function updateProduct(string $destinationId, array $data): void;
    public function upsertProductsByGUPID(array $products): array;
    public function findProductsByGUPID(array $gupids): array;
}