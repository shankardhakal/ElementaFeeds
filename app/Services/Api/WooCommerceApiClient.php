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
        $defaults = ['type' => 'simple', 'status' => 'publish'];
        $payload = array_merge($defaults, $data);

        // Rename our internal 'product_url' to the API-expected 'external_url'.
        if (isset($payload['product_url'])) {
            $payload['external_url'] = $payload['product_url'];
            unset($payload['product_url']);
        }
        if (isset($payload['button_text'])) {
            $payload['button_text'] = $payload['button_text'];
        }

        $response = $this->makeRequest('products', $payload, 'POST');
        return $response['id'] ?? null;
    }

    public function updateProduct(string $destinationId, array $data): void
    {
        if (isset($data['product_url'])) {
            $data['external_url'] = $data['product_url'];
            unset($data['product_url']);
        }
        if (isset($data['button_text'])) {
            $data['button_text'] = $data['button_text'];
        }
        $this->makeRequest("products/{$destinationId}", $data, 'PUT');
    }

    /**
     * Batch create/update/delete products (up to 100 per request)
     * @param array $batchPayload ['create' => [...], 'update' => [...], 'delete' => [...]]
     * @return array
     */
    public function batchProducts(array $batchPayload): array
    {
        // Ensure all external products have correct field names
        foreach (['create', 'update'] as $action) {
            if (!empty($batchPayload[$action])) {
                foreach ($batchPayload[$action] as &$product) {
                    if (isset($product['product_url'])) {
                        $product['external_url'] = $product['product_url'];
                        unset($product['product_url']);
                    }
                    if (isset($product['button_text'])) {
                        $product['button_text'] = $product['button_text'];
                    }
                }
            }
        }
        $endpoint = 'products/batch';
        $response = $this->makeRequest($endpoint, $batchPayload, 'POST');
        return $response;
    }

    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $maxRetries = 3;
        $retryDelay = 5;
        $timeout = 180; // Increase timeout to 3 minutes for large batches
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $fullUrl = $this->baseUrl . rtrim($endpoint, '/') . '/';
                
                Log::debug("Making {$method} request to {$fullUrl} (attempt {$attempt}/{$maxRetries})");

                $client = Http::withBasicAuth(
                    $this->credentials['key'] ?? '',
                    $this->credentials['secret'] ?? ''
                )->timeout($timeout);

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
                
                // Handle 500-level errors (server errors) with more retries
                if ($response->status() >= 500) {
                    if ($attempt < $maxRetries) {
                        $backoffTime = $retryDelay * $attempt; // Progressive backoff
                        Log::warning("Server error {$response->status()} on attempt {$attempt}/{$maxRetries}. Retrying in {$backoffTime} seconds...");
                        sleep($backoffTime);
                        continue;
                    }
                }

                throw new \Exception("API request failed with status code {$response->status()} | Body: " . $response->body());

            } catch (ConnectionException $e) {
                // Only retry on connection errors with progressive backoff
                if ($attempt < $maxRetries) {
                    $backoffTime = $retryDelay * $attempt; // Progressive backoff
                    Log::warning("Connection failed on attempt {$attempt}/{$maxRetries}. Retrying in {$backoffTime} seconds...");
                    sleep($backoffTime);
                    continue;
                }
                throw new \Exception("Could not connect to the website URL. Please check the URL and your server's connectivity.");
            } catch (\Exception $e) {
                // Check for MySQL-related errors in the message
                if (stripos($e->getMessage(), 'mysql') !== false || 
                    stripos($e->getMessage(), 'database') !== false ||
                    stripos($e->getMessage(), 'deadlock') !== false ||
                    stripos($e->getMessage(), 'timeout') !== false) {
                    
                    if ($attempt < $maxRetries) {
                        $backoffTime = $retryDelay * $attempt * 2; // Even longer backoff for DB issues
                        Log::warning("Database error detected on attempt {$attempt}/{$maxRetries}. Retrying in {$backoffTime} seconds...");
                        sleep($backoffTime);
                        continue;
                    }
                }
                
                Log::error("WooCommerce API Client Error: " . $e->getMessage());
                throw $e;
            }
        }
        
        // This line should never be reached because the last retry will either return or throw
        throw new \Exception("Maximum retry attempts reached without success");
    }
}