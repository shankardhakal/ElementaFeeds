<?php

namespace App\Services\Api;

use App\Models\Website;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceApiClient implements ApiClientInterface
{
    protected string $baseUrl;
    protected array $credentials;

    public function __construct(Website $website)
    {
        $this->baseUrl = rtrim($website->url, '/') . '/wp-json/wc/v3/';
        $this->credentials = json_decode($website->woocommerce_credentials, true) ?? [];
    }

    public function getCategories(): array
    {
        return $this->makeRequest('products/categories', ['per_page' => 100]);
    }

    public function getAttributes(): array
    {
        return $this->makeRequest('products/attributes');
    }

    public function createProduct(array $data): ?string
    {
        $payload = array_merge(['type' => 'simple', 'status' => 'publish'], $data);
        $response = $this->makeRequest('products', $payload, 'POST');
        return $response['id'] ?? null;
    }

    public function updateProduct(string $destinationId, array $data): void
    {
        $this->makeRequest("products/{$destinationId}", $data, 'PUT');
    }

    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        try {
            // This ensures the endpoint always has a trailing slash, which is required by some server configurations.
            $fullUrl = $this->baseUrl . rtrim($endpoint, '/') . '/';

            $client = Http::withBasicAuth(
                $this->credentials['key'] ?? '',
                $this->credentials['secret'] ?? ''
            )->timeout(20);

            $response = match (strtoupper($method)) {
                'POST' => $client->post($fullUrl, $params),
                'PUT' => $client->put($fullUrl, $params),
                default => $client->get($fullUrl, $params),
            };

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            if ($response->status() === 401 || $response->status() === 403) {
                throw new AuthenticationException('Invalid API Key or Secret. Please check permissions.');
            }

            throw new \Exception("API request failed with status code {$response->status()}");

        } catch (ConnectionException $e) {
            throw new \Exception("Could not connect to the website URL. Please check the URL and your server's connectivity.");
        } catch (\Exception $e) {
            Log::error("WooCommerce API Client Error: " . $e->getMessage());
            throw $e;
        }
    }
}