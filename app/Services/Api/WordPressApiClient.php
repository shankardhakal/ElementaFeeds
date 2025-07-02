<?php

namespace App\Services\Api;

use App\Models\Website;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressApiClient implements ApiClientInterface
{
    protected string $baseUrl;
    protected array $credentials;
    
    /**
     * Create a new API client instance for a standard WordPress site.
     *
     * @param \App\Models\Website $website
     */
    public function __construct(Website $website)
    {
        $this->baseUrl = rtrim($website->url, '/') . '/wp-json/wp/v2/';
        // Use the correct database column for WordPress credentials
        $this->credentials = json_decode($website->wordpress_credentials, true) ?? [];
    }

    /**
     * Get all post categories from the WordPress site.
     *
     * @return array
     */
    public function getCategories(): array
    {
        return $this->makeRequest('categories', ['per_page' => 100]);
    }

    /**
     * Standard WordPress does not have "attributes" like WooCommerce.
     *
     * @return array
     */
    public function getAttributes(): array
    {
        return [];
    }

    /**
     * Placeholder for creating a new post (or custom post type).
     */
    public function createProduct(array $data): ?string 
    {
        // To be implemented when syndication logic is built for WordPress.
        return null;
    }

    /**
     * Placeholder for updating an existing post.
     */
    public function updateProduct(string $destinationId, array $data): void 
    {
        // To be implemented when syndication logic is built for WordPress.
    }

    /**
     * A robust, reusable method for making API requests to WordPress.
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        try {
            $client = Http::timeout(20);

            // If using an Application Password, apply Basic Authentication.
            if (isset($this->credentials['type']) && $this->credentials['type'] === 'password') {
                $client->withBasicAuth(
                    $this->credentials['username'] ?? '',
                    $this->credentials['password'] ?? ''
                );
            }

            $response = match (strtoupper($method)) {
                'POST' => $client->post($this->baseUrl . $endpoint, $params),
                'PUT'  => $client->post($this->baseUrl . $endpoint, $params),
                default => $client->get($this->baseUrl . $endpoint, $params),
            };

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            throw new \Exception("API request failed. The WordPress REST API might be disabled, blocked, or the URL is incorrect. Status: " . $response->status());

        } catch (ConnectionException $e) {
            throw new \Exception("Could not connect to the website URL. Please check the URL and your server's connectivity.");
        } catch (\Exception $e) {
            Log::error("WordPress API Client Error: " . $e->getMessage());
            throw $e;
        }
    }
}