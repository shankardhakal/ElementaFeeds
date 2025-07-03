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

    /**
     * Publish multiple products by ID (change status from draft to publish)
     *
     * @param array $productIds Array of WooCommerce product IDs to publish
     * @return array API response
     */
    public function publishProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $batchPayload = [
            'update' => []
        ];

        foreach ($productIds as $id) {
            $batchPayload['update'][] = [
                'id' => $id,
                'status' => 'publish'
            ];
        }

        return $this->batchProducts($batchPayload);
    }

    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $maxRetries = 3;
        $retryDelay = 5;
        $timeout = 180; // Increase timeout to 3 minutes for large batches
        
        // Use rate limiting for batch operations to protect the destination database
        if ($endpoint === 'products/batch' && strtoupper($method) === 'POST') {
            $websiteId = $this->getWebsiteId();
            
            // Check if we're hitting the rate limit
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('woocommerce-api:' . $websiteId, 5)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn('woocommerce-api:' . $websiteId);
                Log::warning("Rate limit reached for website ID {$websiteId}. Must wait {$seconds} seconds before trying again.");
                sleep(min($seconds + 1, 60)); // Sleep up to 60 seconds max
            }
            
            // Mark that we're using the rate limiter
            \Illuminate\Support\Facades\RateLimiter::hit('woocommerce-api:' . $websiteId, 60); // Keeps track for 60 seconds
        }
        
        if (!$this->validateCredentials()) {
            throw new AuthenticationException('WooCommerce API credentials are invalid or missing.');
        }

        $url = $this->baseUrl . $endpoint;
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => 30,
        ];

        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $response = match (strtoupper($method)) {
                    'GET' => Http::withOptions($options)->get($url, array_merge($this->credentials, $params)),
                    'POST' => Http::withOptions($options)->post($url, array_merge($this->credentials, $params)),
                    'PUT' => Http::withOptions($options)->put($url, array_merge($this->credentials, $params)),
                    'DELETE' => Http::withOptions($options)->delete($url, array_merge($this->credentials, $params)),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}")
                };

                if ($response->successful()) {
                    return $response->json() ?: [];
                }

                // Special handling for common API errors
                if ($response->status() === 429) {
                    // Too Many Requests - implement exponential backoff
                    $retryAfter = $response->header('Retry-After') ?: pow(2, $attempt) * $retryDelay;
                    Log::warning("Rate limit exceeded. Waiting {$retryAfter} seconds before retry.", [
                        'endpoint' => $endpoint,
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries
                    ]);
                    sleep((int)$retryAfter);
                    $attempt++;
                    continue;
                }

                if ($response->status() === 503) {
                    // Service Unavailable - implement exponential backoff
                    $waitTime = pow(2, $attempt) * $retryDelay;
                    Log::warning("Service unavailable. Waiting {$waitTime} seconds before retry.", [
                        'endpoint' => $endpoint,
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries
                    ]);
                    sleep($waitTime);
                    $attempt++;
                    continue;
                }

                // For 4xx errors (except 429), don't retry as they are client errors
                if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
                    $errorData = $response->json() ?: [];
                    $message = $errorData['message'] ?? "HTTP Error {$response->status()}";
                    Log::error("WooCommerce API client error: {$message}", [
                        'status' => $response->status(),
                        'response' => $errorData,
                        'endpoint' => $endpoint,
                        'method' => $method
                    ]);
                    throw new \Exception("WooCommerce API error: {$message}");
                }

                // For other errors, retry with exponential backoff
                $waitTime = pow(2, $attempt) * $retryDelay;
                Log::warning("API request failed with status {$response->status()}. Retrying in {$waitTime} seconds.", [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries
                ]);
                sleep($waitTime);

            } catch (ConnectionException $e) {
                $waitTime = pow(2, $attempt) * $retryDelay;
                Log::warning("Connection error to WooCommerce API. Retrying in {$waitTime} seconds: {$e->getMessage()}", [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries
                ]);
                sleep($waitTime);
                $lastException = $e;
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'cURL error 28')) {
                    // Timeout error - retry with longer timeout
                    $options['timeout'] += 60; // Add 1 minute to timeout
                    Log::warning("Timeout occurred. Increasing timeout to {$options['timeout']} seconds and retrying.", [
                        'endpoint' => $endpoint,
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries
                    ]);
                    sleep($retryDelay);
                    $lastException = $e;
                } else {
                    // For other exceptions, log and throw
                    Log::error("WooCommerce API request failed: {$e->getMessage()}", [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'endpoint' => $endpoint,
                        'method' => $method
                    ]);
                    throw $e;
                }
            }

            $attempt++;
        }

        // If we've exhausted all retries
        $errorMessage = $lastException ? $lastException->getMessage() : "Maximum retries exceeded";
        Log::error("Failed to connect to WooCommerce API after {$maxRetries} attempts: {$errorMessage}");
        throw new \Exception("Could not connect to the website URL. Please check the URL and your server's connectivity.");
    }
    
    /**
     * Get the website ID for rate limiting
     */
    protected function getWebsiteId(): string
    {
        // Extract domain from URL to use as identifier
        $domain = parse_url($this->baseUrl, PHP_URL_HOST) ?? 'unknown';
        return md5($domain); // Hash the domain to create a consistent ID
    }
}