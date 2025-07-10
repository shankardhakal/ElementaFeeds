<?php

namespace App\Services\Api;

use App\Models\Website;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WooCommerceApiClient implements ApiClientInterface
{
    protected string $baseUrl;
    protected array $credentials;
    protected int $websiteId;

    // Constants for timeout and retry settings
    private const DEFAULT_TIMEOUT = 240; // 4 minutes (increased from 3)
    private const DEGRADED_TIMEOUT = 120; // 2 minutes (increased from 1.5)
    private const HEALTH_CHECK_TIMEOUT = 10; // 10 seconds
    private const CACHE_TTL = 3600; // 1 hour (increased from 30 minutes)
    private const MAX_RETRIES = 4; // Increased from 3
    private const BASE_RETRY_DELAY = 10; // seconds (increased from 5)
    private const SLOW_RESPONSE_THRESHOLD = 2000; // 2 seconds in milliseconds

    public function __construct(Website $website)
    {
        $this->baseUrl = rtrim($website->url, '/') . '/wp-json/wc/v3/';
        $this->credentials = json_decode($website->woocommerce_credentials, true) ?? [];
        $this->websiteId = $website->id;
        
        // Ensure credentials are properly set
        if (empty($this->credentials['key']) || empty($this->credentials['secret'])) {
            Log::warning("WooCommerce API client initialized with incomplete credentials for website #{$website->id}");
        }
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

    public function batchProducts(array $batchPayload): array
    {
        // --- BEGIN: Simple direct implementation ---
        $start = microtime(true);
        
        // Debug logging for payload
        Log::debug("WooCommerce batchProducts request payload", [
            'create_count' => count($batchPayload['create'] ?? []),
            'update_count' => count($batchPayload['update'] ?? []),
            'delete_count' => count($batchPayload['delete'] ?? []),
            'payload' => json_encode($batchPayload, JSON_PRETTY_PRINT)
        ]);
        
        $response = $this->makeRequest('products/batch', $batchPayload, 'POST');

        // Debug logging for response
        Log::debug("WooCommerce batchProducts response", [
            'response' => json_encode($response, JSON_PRETTY_PRINT)
        ]);

        $result = [
            'success' => false,
            'total_requested' => 0,
            'execution_time_ms' => (int)((microtime(true) - $start) * 1000)
        ];

        // Handle create operations
        if (isset($batchPayload['create'])) {
            $created = $response['create'] ?? [];
            
            // Filter out failed products - only count products that were actually created
            $successfullyCreated = array_filter($created, function($product) {
                return !empty($product['id']) && $product['id'] > 0 && !isset($product['error']);
            });
            
            $failed = array_filter($created, function($product) {
                return empty($product['id']) || $product['id'] <= 0 || isset($product['error']);
            });
            
            $result['create'] = $successfullyCreated;
            $result['total_created'] = count($successfullyCreated);
            $result['created'] = $successfullyCreated;
            $result['failed'] = ($result['failed'] ?? []) + $failed;
            $result['total_requested'] += count($batchPayload['create']);
            $result['success'] = !empty($successfullyCreated);
        }

        // Handle update operations
        if (isset($batchPayload['update'])) {
            $updated = $response['update'] ?? [];
            
            // Filter out failed products - only count products that were actually updated
            $successfullyUpdated = array_filter($updated, function($product) {
                return !empty($product['id']) && $product['id'] > 0 && !isset($product['error']);
            });
            
            $updateFailed = array_filter($updated, function($product) {
                return empty($product['id']) || $product['id'] <= 0 || isset($product['error']);
            });
            
            $result['update'] = $successfullyUpdated;
            $result['total_updated'] = count($successfullyUpdated);
            $result['updated'] = $successfullyUpdated;
            $result['failed'] = ($result['failed'] ?? []) + $updateFailed;
            $result['total_requested'] += count($batchPayload['update']);
            $result['success'] = $result['success'] || !empty($successfullyUpdated);
        }

        // Handle delete operations
        if (isset($batchPayload['delete'])) {
            $deleted = $response['delete'] ?? [];
            
            // Filter out failed deletions
            $successfullyDeleted = array_filter($deleted, function($product) {
                return !empty($product['id']) && !isset($product['error']);
            });
            
            $deleteFailed = array_filter($deleted, function($product) {
                return empty($product['id']) || isset($product['error']);
            });
            
            $result['delete'] = $successfullyDeleted;
            $result['total_deleted'] = count($successfullyDeleted);
            $result['deleted'] = $successfullyDeleted;
            $result['failed'] = ($result['failed'] ?? []) + $deleteFailed;
            $result['total_requested'] += count($batchPayload['delete']);
            $result['success'] = $result['success'] || !empty($successfullyDeleted);
        }

        $result['error'] = $response['error'] ?? null;
        
        return $result;
        // --- END: Simple direct implementation ---
    }
    
    /* Removed code fragments that were causing syntax errors:
                            'error' => $errorMessage,
                            'error_type' => $errorType,
                            'chunk_index' => $chunkIndex,
                            'chunk_size' => count($chunk)
                        ]);
                        
                        $lastError = $errorMessage;
                        $lastErrorType = $errorType;
                        
                        // Update circuit state
                        $this->updateCircuitState($websiteId, false, $errorType);
    */

    /* Additional code from the complex batch logic that needs to be commented out:
                        if ($attempt < $maxRetries) {
                            Log::info("Retrying in {$effectiveDelay} seconds (attempt {$attempt}/{$maxRetries})");
                            sleep(ceil($effectiveDelay));
                            
                            // If it's a timeout, reduce the batch size for future attempts within this chunk
                            if ($errorType === 'timeout' && count($chunk) > 3 && $attempt === 1) {
                                // For 504 errors, be even more aggressive with splitting
                                $divider = ($response->status() === 504) ? 5 : 3;
                                // Split the chunk for the next attempt - more aggressively for 504 errors
                                $reducedChunkSize = max(3, (int)(count($chunk) / $divider));
                                $smallerChunks = array_chunk($chunk, $reducedChunkSize);
                                
                                Log::warning("Timeout detected, splitting chunk into smaller parts", [
                                    'original_size' => count($chunk),
                                    'new_size' => $reducedChunkSize,
                                    'new_chunk_count' => count($smallerChunks)
                                ]);
                                
                                // Process each smaller chunk
                                $subResults = [];
                                $subSuccess = true;
                                
                                foreach ($smallerChunks as $smallerChunkIndex => $smallerChunk) {
                                    // Add a delay between smaller chunks
                                    if ($smallerChunkIndex > 0) {
                                        sleep(3);
                                    }
                                    
                                    try {
                                        $subResponse = Http::withBasicAuth($this->credentials['key'], $this->credentials['secret'])
                                            ->timeout(self::DEFAULT_TIMEOUT)
                                            ->withOptions(['verify' => false])
                                            ->post($url, [$operation => $smallerChunk]);

                                        if ($subResponse->successful()) {
                                            $subData = $subResponse->json();
                                            if (!empty($subData[$operation])) {
                                                // Validate each product has an ID to ensure it was actually created/updated
                                                $validProducts = array_filter($subData[$operation], function($product) {
                                                    return !empty($product['id']);
                                                });
                                                
                                                $subResults = array_merge($subResults, $validProducts);
                                                
                                                // Check if any products were missing or invalid
                                                if (count($validProducts) < count($subData[$operation])) {
                                                    Log::warning("Some products in response were missing IDs", [
                                                        'expected' => count($subData[$operation]),
                                                        'valid' => count($validProducts)
                                                    ]);
                                                }
                                            } else {
                                                Log::warning("Response contained no {$operation} results though status was 200");
                                            }
                                            
                                            // Update circuit state with success and store the successful batch size
                                            $this->updateCircuitState($websiteId, true);
                                            
                                            // Remember this as the minimum batch size that worked
                                            $circuitState = $this->getCircuitState($websiteId);
                                            if (empty($circuitState['min_batch_size']) || $reducedChunkSize < $circuitState['min_batch_size']) {
                                                $circuitState['min_batch_size'] = $reducedChunkSize;
                                                $cacheKey = "api_circuit:website:{$websiteId}";
                                                Cache::put($cacheKey, $circuitState, self::CACHE_TTL);
                                            }
    
                                            Log::info("Successfully processed smaller chunk {$smallerChunkIndex} of operation '{$operation}' with {$reducedChunkSize} items");
                                        } else {
                                            $subSuccess = false;
                                            Log::error("Failed to process smaller chunk {$smallerChunkIndex}", [
                                                'status' => $subResponse->status(),
                                                'body' => $subResponse->body()
                                            ]);
                                            
                                            $this->updateCircuitState($websiteId, false, $this->classifyErrorType("", $subResponse->status()));
                                        }
                                    } catch (\Exception $subException) {
                                        $subSuccess = false;
                                        Log::error("Exception when processing smaller chunk {$smallerChunkIndex}: " . $subException->getMessage());
                                        $this->updateCircuitState($websiteId, false, $this->classifyErrorType($subException->getMessage()));
                                    }
                                }
                                
                                if ($subSuccess) {
                                    // If all smaller chunks succeeded, add results and consider the whole chunk successful
                                    $results[$operation] = array_merge($results[$operation], $subResults);
                                    $chunkSuccess = true;
                                    break; // Exit retry loop as we've handled the split chunks
                                }
                                // If any sub-chunk failed, we'll continue with normal retries
                            }
                        } else {
                            // After max retries, we don't rethrow, but log the failure of this chunk
                            Log::critical("Failed to process batch chunk for operation '{$operation}' after {$maxRetries} attempts: {$lastError}");
                            
                            // Special handling for timeouts - reduce batch size for the entire operation
                            if ($errorType === 'timeout') {
                                // Remember minimum successful batch size to prevent future timeouts
                                $circuitState = $this->getCircuitState($websiteId);
                                $newMinSize = max(1, (int)(count($chunk) * 0.5)); // 50% of the current chunk size
                                
                                if (empty($circuitState['min_batch_size']) || $newMinSize < $circuitState['min_batch_size']) {
                                    $circuitState['min_batch_size'] = $newMinSize;
                                    $cacheKey = "api_circuit:website:{$websiteId}";
                                    Cache::put($cacheKey, $circuitState, self::CACHE_TTL);
                                    
                                    Log::warning("Updated minimum batch size to {$newMinSize} for website #{$websiteId} due to timeout");
                                }
                            }
                        }
                    }
                }
                
                // If chunk completely failed after all retries, add a special "failure" record
                if (!$chunkSuccess) {
                    // For now, we just log this and continue with the next chunk
                    Log::critical("Unable to process chunk {$chunkIndex} for operation '{$operation}' after all attempts");
                }
            }
        }

        return $results;
    */

    /**
     * Create products using batch API.
     *
     * @param array $products List of products to create.
     * @return array Contains 'created' (list of product data) and 'success' (boolean) keys.
     */
    public function createProducts(array $products): array
    {
        // --- BEGIN: Simple direct implementation ---
        return $this->batchProducts(['create' => $products]);
        // --- END: Simple direct implementation ---
    }

    /**
     * Helper method for making POST requests to the WooCommerce API.
     *
     * @param string $endpoint The API endpoint to call.
     * @param array $data The data to send in the POST request.
     * @return array The response data.
     */
    protected function post(string $endpoint, array $data): array
    {
        return $this->makeRequest($endpoint, $data, 'POST');
    }

    /**
     * Update products using batch API.
     *
     * @param array $products List of products to update.
     * @return array Contains 'updated' (list of product data) and 'success' (boolean) keys.
     */
    public function updateProducts(array $products): array
    {
        try {
            Log::info("Updating batch of " . count($products) . " products");
            $batchPayload = ['update' => $products];
            
            // Execute the request and capture the full response
            $startTime = microtime(true);
            $results = $this->batchProducts($batchPayload);
            $executionTime = round((microtime(true) - $startTime) * 1000); // in milliseconds
            
            $updated = $results['update'] ?? [];
            $updatedCount = count($updated);
            $failedCount = count($products) - $updatedCount;
            
            // Log detailed response information
            Log::info("WooCommerce API updateProducts response metrics", [
                'execution_time_ms' => $executionTime,
                'products_requested' => count($products),
                'products_in_response' => $updatedCount,
                'response_has_ids' => !empty($updated) && !empty($updated[0]['id'] ?? null)
            ]);
            
            if ($updatedCount > 0) {
                // Log the first few updated product IDs for debugging
                $sampleIds = array_slice(array_column($updated, 'id'), 0, min(5, count($updated)));
                Log::info("Successfully updated {$updatedCount} products. Sample IDs: " . implode(', ', $sampleIds));
            }
            
            if ($failedCount > 0) {
                Log::warning("Failed to update {$failedCount} products");
            }
            
            // Check if the products array is empty but should have content
            if (empty($updated) && count($products) > 0) {
                Log::error("WooCommerce API returned empty results array for product updates", [
                    'requested_count' => count($products),
                    'first_product' => !empty($products[0]) ? json_encode(array_intersect_key($products[0], array_flip(['id', 'name', 'sku']))) : 'N/A'
                ]);
            }
            
            // Verify updated products have valid IDs to ensure they were actually updated
            $validUpdated = array_filter($updated, function($product) {
                return !empty($product['id']);
            });
            
            // If we have a mismatch in products with IDs, log it
            if (count($validUpdated) < $updatedCount) {
                Log::warning("Some updated products are missing IDs in the response", [
                    'total_in_response' => $updatedCount,
                    'valid_with_ids' => count($validUpdated)
                ]);
            }
            
            // Return structured response with both the updated products and success status
            return [
                'updated' => $validUpdated, 
                'success' => $updatedCount > 0 && $updatedCount === count($products),
                'total_requested' => count($products),
                'total_updated' => count($validUpdated),
                'execution_time_ms' => $executionTime
            ];
        } catch (\Throwable $e) {
            Log::error("Error in updateProducts batch operation: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'updated' => [],
                'success' => false,
                'total_requested' => count($products),
                'total_updated' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Find products by metadata key and value.
     *
     * @param string $metaKey The metadata key to search for.
     * @param string $metaValue The metadata value to match.
     * @return array List of product IDs matching the metadata.
     */
    public function findProductsByMetadata(string $metaKey, string $metaValue): array
    {
        $endpoint = 'products';
        $params = [
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
            'per_page' => 100,
        ];

        try {
            $response = $this->makeRequest($endpoint, $params);

            return array_map(function ($product) {
                return $product['id'];
            }, $response);
        } catch (\Exception $e) {
            Log::error("Failed to find products by metadata: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Find a product by SKU.
     *
     * @param string $sku The SKU to search for.
     * @return array|null The product data if found, or null if not found.
     */
    public function findProductBySKU(string $sku): ?array
    {
        $endpoint = 'products';
        $params = [
            'sku' => $sku,
        ];

        try {
            $response = $this->makeRequest($endpoint, $params);

            return $response[0] ?? null; // Return the first matching product or null
        } catch (\Exception $e) {
            Log::error("Failed to find product by SKU: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find a product by SKU in all statuses (including trash/deleted).
     *
     * @param string $sku The SKU to search for.
     * @return array|null Returns the product data if found, null otherwise.
     */
    public function findProductBySkuAllStatuses(string $sku): ?array
    {
        $statuses = ['publish', 'draft', 'pending', 'private', 'trash'];
        
        foreach ($statuses as $status) {
            $endpoint = 'products';
            $params = [
                'sku' => $sku,
                'status' => $status,
            ];

            try {
                $response = $this->makeRequest($endpoint, $params);
                
                if (!empty($response)) {
                    $product = $response[0];
                    $product['found_in_status'] = $status; // Add status info
                    return $product;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to find product by SKU in status '$status': " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Get a map of product IDs by their SKUs.
     *
     * @param array $skus List of SKUs to look up.
     * @return array A map of [sku => destination_id].
     */
    public function getProductIdMapBySkus(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $skuMap = [];
        // WooCommerce API allows fetching multiple products by comma-separated SKUs.
        // We chunk the SKUs to respect URL length limits and API performance.
        $skuChunks = array_chunk($skus, 50); // Process in chunks of 50.

        foreach ($skuChunks as $chunk) {
            try {
                // Fetch products with 'any' status to find all existing products, including drafts.
                $response = $this->makeRequest('products', [
                    'sku' => implode(',', $chunk),
                    'per_page' => 100, // Max per_page is 100
                    'status' => 'any', 
                ]);

                if (!empty($response)) {
                    foreach ($response as $product) {
                        if (isset($product['sku'], $product['id'])) {
                            $skuMap[$product['sku']] = $product['id'];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch product chunk by SKUs: " . $e->getMessage(), [
                    'skus' => $chunk
                ]);
                // Continue to next chunk even if one fails
            }
        }

        return $skuMap;
    }

    /**
     * Perform a pre-flight health check on the API connection
     * 
     * @return array Status information about the API health
     */
    public function checkApiHealth(): array
    {
        $healthStatus = [
            'status' => 'healthy',
            'issues' => [],
            'response_time' => null,
            'circuit_state' => $this->getCircuitState($this->websiteId)
        ];
        
        try {
            // If circuit breaker is open, immediately return critical status
            if ($healthStatus['circuit_state']['open']) {
                $healthStatus['status'] = 'critical';
                $healthStatus['issues'][] = "Circuit breaker is open due to previous failures";
                
                // Add time since last failure if available
                if (!empty($healthStatus['circuit_state']['last_failure'])) {
                    $timeSinceFailure = now()->diffInMinutes($healthStatus['circuit_state']['last_failure']);
                    $healthStatus['issues'][] = "Last failure occurred {$timeSinceFailure} minutes ago";
                }
                
                Log::warning("API health check skipped - circuit breaker is open for website #{$this->websiteId}");
                return $healthStatus;
            }
            
            // Start timing the request
            $startTime = microtime(true);
            
            // Try to get a simple lightweight endpoint (categories with a limit of 1)
            $response = $this->makeRequest('products/categories', ['per_page' => 1], 'GET', self::HEALTH_CHECK_TIMEOUT);
            
            // Calculate response time
            $endTime = microtime(true);
            $healthStatus['response_time'] = round(($endTime - $startTime) * 1000); // in milliseconds
            
            // More strict slow response threshold - reduced from 2000ms to 1500ms
            if ($healthStatus['response_time'] > 1500) {
                $healthStatus['status'] = 'degraded';
                $healthStatus['issues'][] = "Slow API response time: {$healthStatus['response_time']}ms";
            }
            
            // Check circuit state - be more strict with failure count
            if ($healthStatus['circuit_state']['failure_count'] >= 2) {
                $healthStatus['status'] = 'degraded';
                $healthStatus['issues'][] = "Circuit breaker has detected {$healthStatus['circuit_state']['failure_count']} recent failures";
            }
            
            Log::info("API health check completed for website #{$this->websiteId}", $healthStatus);
            
        } catch (\Throwable $e) {
            $healthStatus['status'] = 'critical';
            $healthStatus['issues'][] = "Health check failed: " . $e->getMessage();
            
            // Update circuit state on health check failure
            $this->updateCircuitState($this->websiteId, false, $this->classifyErrorType($e->getMessage()));
            
            Log::error("API health check failed for website #{$this->websiteId}: " . $e->getMessage());
        }
        
        return $healthStatus;
    }

    /**
     * Test the WooCommerce API connection.
     *
     * @return array Contains 'success' (boolean), 'message' (string), and optionally 'response' and 'error' keys.
     */
    public function testConnection(): array
    {
        try {
            $startTime = microtime(true);
            
            // Try to get a single product to test the connection
            $response = Http::withBasicAuth(
                $this->credentials['key'], 
                $this->credentials['secret']
            )
                ->timeout(self::HEALTH_CHECK_TIMEOUT)
                ->withOptions(['verify' => false])
                ->get($this->baseUrl . 'products', ['per_page' => 1]);
            
            $executionTime = round((microtime(true) - $startTime) * 1000); // in milliseconds
            
            // Check if we got a successful response
            if ($response->successful()) {
                $products = $response->json();
                $hasValidResponse = is_array($products) && (!empty($products) ? !empty($products[0]['id']) : true);
                
                Log::info("WooCommerce API connection test successful", [
                    'status_code' => $response->status(),
                    'execution_time_ms' => $executionTime,
                    'has_valid_response' => $hasValidResponse,
                    'products_returned' => is_array($products) ? count($products) : 'N/A'
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Successfully connected to WooCommerce API',
                    'execution_time_ms' => $executionTime,
                    'response' => [
                        'status' => $response->status(),
                        'has_products' => is_array($products) && !empty($products),
                        'products_count' => is_array($products) ? count($products) : 0
                    ]
                ];
            } else {
                $errorBody = $response->body();
                
                // Try to parse as JSON for more details
                $errorDetails = $response->json() ?: [];
                
                Log::error("WooCommerce API connection test failed", [
                    'status_code' => $response->status(),
                    'execution_time_ms' => $executionTime,
                    'error_body' => $errorBody,
                    'error_details' => $errorDetails
                ]);
                
                return [
                    'success' => false,
                    'message' => 'Failed to connect to WooCommerce API. Status code: ' . $response->status(),
                    'execution_time_ms' => $executionTime,
                    'error' => [
                        'status' => $response->status(),
                        'body' => $errorBody,
                        'details' => $errorDetails
                    ]
                ];
            }
        } catch (\Throwable $e) {
            Log::error("Exception during WooCommerce API connection test: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Exception during WooCommerce API connection test: ' . $e->getMessage(),
                'error' => [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * An enhanced version of makeRequest with timeout parameter and detailed logging
     */
    public function makeRequest(string $endpoint, array $params = [], string $method = 'GET', ?int $customTimeout = null): array
    {
        $requestId = uniqid('req_');
        $startTime = microtime(true);
        $timeout = $customTimeout ?? self::DEFAULT_TIMEOUT;
        
        // This ensures the endpoint always has a trailing slash, which is required by some server configurations.
        $fullUrl = $this->baseUrl . rtrim($endpoint, '/') . '/';
        
        // Enhanced debugging: Set a flag for non-health-check requests
        $isHealthCheck = ($endpoint === 'products/categories' && $method === 'GET' && isset($params['per_page']) && $params['per_page'] === 1);
        
        // Log the request if it's not a health check (to avoid spamming logs)
        if (!$isHealthCheck) {
            // For POST/PUT requests, log the complete payload
            if ($method === 'POST' || $method === 'PUT') {
                Log::debug("WooCommerce API request [{$requestId}]", [
                    'url' => $fullUrl,
                    'method' => $method,
                    'timeout' => $timeout,
                    'params' => json_encode($params, JSON_PRETTY_PRINT)
                ]);
            } else {
                Log::debug("WooCommerce API request [{$requestId}]", [
                    'url' => $fullUrl,
                    'method' => $method,
                    'timeout' => $timeout,
                    'params' => $params
                ]);
            }
        }
        
        try {
            $client = Http::withBasicAuth(
                $this->credentials['key'] ?? '',
                $this->credentials['secret'] ?? ''
            )->timeout($timeout)->withOptions(['verify' => false]);

            $response = match (strtoupper($method)) {
                'POST' => $client->post($fullUrl, $params),
                'PUT' => $client->put($fullUrl, $params),
                default => $client->get($fullUrl, $params),
            };
            
            $executionTime = round((microtime(true) - $startTime) * 1000); // in milliseconds
            
            // Log response details
            if ($response->successful()) {
                // Only log detailed response for non-health check requests
                if (!$isHealthCheck) {
                    $responseData = $response->json() ?? [];
                    
                    // For product creation/update operations, always log the full response 
                    if (($method === 'POST' || $method === 'PUT') && 
                        (strpos($endpoint, 'products') !== false)) {
                        Log::debug("WooCommerce API detailed response [{$requestId}]", [
                            'status' => $response->status(),
                            'execution_time_ms' => $executionTime,
                            'response_data' => json_encode($responseData, JSON_PRETTY_PRINT)
                        ]);
                    } else {
                        // For successful responses, log only a summary to avoid huge log files
                        $responseSize = is_array($responseData) ? count($responseData) : 'N/A';
                        
                        Log::debug("WooCommerce API response summary [{$requestId}]", [
                            'status' => $response->status(),
                            'execution_time_ms' => $executionTime,
                            'response_size' => $responseSize,
                            'sample_data' => is_array($responseData) ? (empty($responseData) ? 'empty array' : 'data present') : 'not an array'
                        ]);
                    }
                    
                    // For large responses with many products, check that they have proper IDs
                    if (is_array($responseData) && count($responseData) > 0) {
                        // If it's a product array response, verify IDs exist
                        if (isset($responseData[0]) && is_array($responseData[0])) {
                            $firstItem = $responseData[0];
                            if (!isset($firstItem['id'])) {
                                Log::warning("WooCommerce API response missing IDs in returned items [{$requestId}]", [
                                    'first_item_keys' => array_keys($firstItem)
                                ]);
                            }
                        }
                    }
                }
                
                return $response->json() ?? [];
            }
            
            // For failed responses, log more details
            $errorBody = $response->body();
            $errorJson = $response->json() ?: null;
            
            Log::error("WooCommerce API request failed [{$requestId}]", [
                'url' => $fullUrl,
                'method' => $method,
                'status' => $response->status(),
                'execution_time_ms' => $executionTime,
                'error_body' => substr($errorBody, 0, 1000), // Limit size to avoid huge logs
                'error_json' => $errorJson
            ]);

            if ($response->status() === 401 || $response->status() === 403) {
                throw new AuthenticationException('Invalid API Key or Secret. Please check permissions.');
            }

            throw new \Exception("API request failed with status code {$response->status()}: " . substr($errorBody, 0, 500));

        } catch (ConnectionException $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            Log::error("WooCommerce API connection error [{$requestId}]", [
                'url' => $fullUrl,
                'method' => $method,
                'execution_time_ms' => $executionTime,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            
            throw new \Exception("Could not connect to the website URL. Please check the URL and your server's connectivity: " . $e->getMessage());
        } catch (\Exception $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000);
            
            Log::error("WooCommerce API Client Error [{$requestId}]", [
                'url' => $fullUrl,
                'method' => $method,
                'execution_time_ms' => $executionTime,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Helper method to determine if we should implement circuit breaker for a website
     * 
     * @param string $websiteId 
     * @return array Circuit state information
     */
    private function getCircuitState(int $websiteId): array
    {
        $cacheKey = "api_circuit:website:{$websiteId}";
        return Cache::get($cacheKey, [
            'open' => false,
            'failure_count' => 0,
            'last_failure' => null,
            'min_batch_size' => null
        ]);
    }
    
    /**
     * Update the circuit breaker state based on API response
     * 
     * @param int $websiteId
     * @param bool $isSuccess
     * @param string|null $errorType
     */
    private function updateCircuitState(int $websiteId, bool $isSuccess, ?string $errorType = null): void
    {
        $cacheKey = "api_circuit:website:{$websiteId}";
        $state = $this->getCircuitState($websiteId);
        
        if ($isSuccess) {
            // On success, reduce failure count gradually
            if ($state['failure_count'] > 0) {
                // Only reduce by 0.5 for gradual recovery
                $state['failure_count'] = max(0, $state['failure_count'] - 0.5);
            }
            
            // Need more consecutive successes to close the circuit
            if ($state['open'] && $state['failure_count'] < 2) {
                $state['open'] = false;
                Log::info("Circuit closed for website #{$websiteId} after consecutive successful requests");
            }
        } else {
            // On failure, increment failure count more aggressively for timeouts
            $increment = ($errorType === 'timeout') ? 2 : 1;
            $state['failure_count'] += $increment;
            $state['last_failure'] = now();
            
            // Open circuit sooner for timeouts
            if ($errorType === 'timeout' && $state['failure_count'] >= 3) {
                $state['open'] = true;
                Log::warning("Circuit opened for website #{$websiteId} after {$state['failure_count']} consecutive failures (timeout detected)");
            } elseif ($state['failure_count'] >= 5) {
                $state['open'] = true;
                Log::warning("Circuit opened for website #{$websiteId} after {$state['failure_count']} consecutive failures");
            }
        }
        
        // Store state for cache TTL period
        Cache::put($cacheKey, $state, self::CACHE_TTL);
    }

    /**
     * Determines error type from error message or response status
     * 
     * @param string $errorMessage
     * @param int|null $statusCode
     * @return string
     */
    private function classifyErrorType(string $errorMessage, ?int $statusCode = null): string
    {
        if ($statusCode === 504 || stripos($errorMessage, 'timeout') !== false || stripos($errorMessage, '504') !== false) {
            return 'timeout';
        } elseif ($statusCode === 401 || $statusCode === 403 || stripos($errorMessage, 'unauthorized') !== false) {
            return 'auth';
        } elseif ($statusCode === 400 || stripos($errorMessage, 'validation') !== false) {
            return 'validation';
        }
        
        return 'other';
    }

    /**
     * Apply rate limiting for API requests
     * 
     * @param string $operation The operation being performed (create, update, delete)
     * @return void
     */
    private function applyRateLimit(string $operation): void
    {
        $rateLimiterKey = "woocommerce-api:{$this->websiteId}:{$operation}";
        
        // Check if we're hitting the rate limit (5 requests per minute per operation)
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateLimiterKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($rateLimiterKey);
            Log::warning("Rate limit reached for website #{$this->websiteId} ({$operation} operation). Must wait {$seconds} seconds before trying again.");
            
            // Sleep for the required time, but cap at 30 seconds to prevent too long delays
            sleep(min($seconds + 1, 30));
        }
        
        // Mark that we\'re using the rate limiter
        \Illuminate\Support\Facades\RateLimiter::hit($rateLimiterKey, 60); // Keeps track for 60 seconds
    }
    
    /**
     * Create products directly one-by-one instead of using batch API.
     * This is a fallback for when batch operations are failing.
     *
     * @param array $products List of products to create.
     * @return array Contains 'created' (list of product data) and success metrics.
     */
    public function createProductsDirectly(array $products): array
    {
        try {
            $startTime = microtime(true);
            $created = [];
            $failed = 0;
            
            Log::info("Creating " . count($products) . " products using direct API (fallback method)");
            
            foreach ($products as $index => $product) {
                try {
                    // Ensure status is set to publish
                    $product['status'] = 'publish';
                    $product['catalog_visibility'] = 'visible';
                    
                    // Create the product using the API
                    $response = $this->makeRequest('products', $product, 'POST');
                    
                    if (!empty($response['id'])) {
                        $created[] = $response;
                        
                        // Log each successful creation
                        Log::info("Successfully created product via direct API", [
                            'product_id' => $response['id'],
                            'product_name' => $response['name'] ?? 'Unknown',
                            'product_status' => $response['status'] ?? 'Unknown'
                        ]);
                    } else {
                        $failed++;
                        Log::warning("Failed to create product via direct API - no ID in response", [
                            'product_name' => $product['name'] ?? 'Unknown',
                            'product_sku' => $product['sku'] ?? 'Unknown'
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error("Exception during direct product creation: " . $e->getMessage(), [
                        'product_name' => $product['name'] ?? 'Unknown',
                        'product_sku' => $product['sku'] ?? 'Unknown'
                    ]);
                }
                
                // Add a short delay between requests to avoid rate limiting
                if ($index < count($products) - 1) {
                    usleep(500000); // 500ms delay
                }
            }
            
            $executionTime = round((microtime(true) - $startTime) * 1000); // in milliseconds
            
            Log::info("Direct product creation completed", [
                'execution_time_ms' => $executionTime,
                'total_requested' => count($products),
                'total_created' => count($created),
                'total_failed' => $failed
            ]);
            
            return [
                'created' => $created,
                'success' => count($created) > 0,
                'total_requested' => count($products),
                'total_created' => count($created),
                'execution_time_ms' => $executionTime
            ];
        } catch (\Throwable $e) {
            Log::error("Error in createProductsDirectly operation: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'created' => [],
                'success' => false,
                'total_requested' => count($products),
                'total_created' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Count products by status in a response array.
     *
     * @param array $products List of products from API response.
     * @return array Counts indexed by status.
     */
    protected function countProductStatuses(array $products): array
    {
        $counts = [
            'publish' => 0,
            'draft' => 0,
            'pending' => 0,
            'private' => 0,
            'unknown' => 0
        ];
        
        foreach ($products as $product) {
            $status = $product['status'] ?? 'unknown';
            if (isset($counts[$status])) {
                $counts[$status]++;
            } else {
                $counts[$status] = 1;
            }
        }
        
        return $counts;
    }

    /**
     * Get current product counts by status in WooCommerce.
     *
     * @return array Counts of products by status.
     */
    public function getProductCounts(): array
    {
        try {
            // Use the WP REST API to get product counts by status
            $response = $this->makeRequest('reports/products/totals', [], 'GET');
            
            // Format the response into a status => count array
            $counts = [];
            foreach ($response as $item) {
                if (isset($item['slug']) && isset($item['total'])) {
                    $counts[$item['slug']] = $item['total'];
                }
            }
            
            return $counts;
        } catch (\Throwable $e) {
            Log::warning("Failed to get product counts: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get product counts by status from WooCommerce.
     *
     * @return array Associative array of product counts by status.
     */
    public function getProductCountsByStatus(): array
    {
        try {
            // First, get the total count to determine how many pages we need
            $totalCountResponse = $this->makeRequest('reports/products/totals');
            $totalProducts = 0;
            
            // Count by product types (simple, variable, etc.)
            foreach ($totalCountResponse as $countItem) {
                if (isset($countItem['total'])) {
                    $totalProducts += $countItem['total'];
                }
            }
            
            // If no products, return early
            if ($totalProducts === 0) {
                return [
                    'total' => 0,
                    'publish' => 0,
                    'draft' => 0,
                    'pending' => 0,
                    'private' => 0
                ];
            }
            
            // Get actual products with a reasonable limit (max 100 per page)
            $perPage = 100;
            $pages = ceil($totalProducts / $perPage);
            $pages = min($pages, 10); // Cap at 10 pages to avoid timeouts (1000 products max)
            
            $allProducts = [];
            
            for ($page = 1; $page <= $pages; $page++) {
                $products = $this->makeRequest('products', [
                    'per_page' => $perPage,
                    'page' => $page
                ]);
                
                if (empty($products)) {
                    break;
                }
                
                $allProducts = array_merge($allProducts, $products);
                
                if (count($products) < $perPage) {
                    break; // No more products
                }
            }
            
            // Count by status
            $counts = [
                'total' => count($allProducts),
                'publish' => 0,
                'draft' => 0,
                'pending' => 0,
                'private' => 0
            ];
            
            foreach ($allProducts as $product) {
                $status = $product['status'] ?? 'unknown';
                
                if (isset($counts[$status])) {
                    $counts[$status]++;
                } else {
                    $counts[$status] = 1;
                }
            }
            
            // Get visibility counts
            $counts['visible'] = count(array_filter($allProducts, function($product) {
                return ($product['catalog_visibility'] ?? '') === 'visible';
            }));
            
            $counts['hidden'] = count(array_filter($allProducts, function($product) {
                return ($product['catalog_visibility'] ?? '') === 'hidden';
            }));
            
            return $counts;
        } catch (\Throwable $e) {
            Log::error("Error getting product counts by status: " . $e->getMessage());
            
            return [
                'error' => $e->getMessage(),
                'total' => 0
            ];
        }
    }

    /**
     * Bulletproof upsert method that handles both create and update scenarios
     * This method addresses the core issue where SKU lookups fail but products exist
     */
    public function upsertProducts(array $products): array
    {
        if (empty($products)) {
            return [
                'success' => true,
                'total_requested' => 0,
                'total_created' => 0,
                'total_updated' => 0,
                'failed' => [],
                'execution_time_ms' => 0
            ];
        }

        $start = microtime(true);
        $totalRequested = count($products);
        $created = [];
        $updated = [];
        $failed = [];

        Log::info("Starting bulletproof upsert for {$totalRequested} products");

        // Step 1: Extract SKUs for lookup
        $skus = array_filter(array_map(function($product) {
            return $product['sku'] ?? null;
        }, $products));

        // Step 2: Try to get existing product map
        $existingProducts = [];
        try {
            $existingProducts = $this->getProductIdMapBySkus($skus);
            Log::debug("Found " . count($existingProducts) . " existing products via SKU lookup");
        } catch (\Exception $e) {
            Log::warning("SKU lookup failed, will handle during batch operation: " . $e->getMessage());
        }

        // Step 3: Separate products into create and update batches
        $toCreate = [];
        $toUpdate = [];

        foreach ($products as $product) {
            $sku = $product['sku'] ?? null;
            if (!$sku) {
                $failed[] = [
                    'error' => 'Missing SKU',
                    'sku' => 'Unknown SKU',
                    'operation' => 'upsert',
                    'product' => $product
                ];
                continue;
            }

            if (isset($existingProducts[$sku])) {
                // Product exists, prepare for update
                $toUpdate[] = array_merge($product, ['id' => $existingProducts[$sku]]);
            } else {
                // Product doesn't exist (or lookup failed), try to create
                $toCreate[] = $product;
            }
        }

        // Steps 4 & 5: Unified batch create+update
        $batchPayload = [];
        if (!empty($toCreate)) {
            $batchPayload['create'] = $toCreate;
        }
        if (!empty($toUpdate)) {
            $batchPayload['update'] = $toUpdate;
        }
        if (!empty($batchPayload)) {
            $batchResp = $this->batchProducts($batchPayload);
            // Collect created and updated
            $created = $batchResp['created'] ?? [];
            $updated = $batchResp['updated'] ?? [];
            // Handle any failures
            foreach ($batchResp['failed'] ?? [] as $failedItem) {
                $this->handleFailedCreate($failedItem, array_merge($toCreate, $toUpdate), $created, $updated, $failed);
            }
            Log::info("Batch upsert: created " . count($created) . ", updated " . count($updated) . ", failed " . count($failed));
        }

        $executionTime = (int)((microtime(true) - $start) * 1000);
        $totalCreated = count($created);
        $totalUpdated = count($updated);
        $totalFailed = count($failed);
        $success = ($totalCreated + $totalUpdated) > 0;

        Log::info("Upsert completed", [
            'total_requested' => $totalRequested,
            'total_created' => $totalCreated,
            'total_updated' => $totalUpdated,
            'total_failed' => $totalFailed,
            'execution_time_ms' => $executionTime
        ]);

        return [
            'success' => $success,
            'total_requested' => $totalRequested,
            'total_created' => $totalCreated,
            'total_updated' => $totalUpdated,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'execution_time_ms' => $executionTime
        ];
    }
    
    /**
     * Handle failed create attempts - convert SKU conflicts to updates
     */
    private function handleFailedCreate($failedProduct, $originalProducts, &$created, &$updated, &$failed): void
    {
        // Normalize raw error into code and message
        $errorRaw = $failedProduct['error'] ?? '';
        if (is_array($errorRaw)) {
            $errorMessage = $errorRaw['message'] ?? json_encode($errorRaw);
            $errorCode = $errorRaw['code'] ?? 'unknown_error';
        } else {
            $errorMessage = (string) $errorRaw;
            $errorCode = 'error';
        }
        // If SKU conflict, treat as update (product exists remotely)
        if ($errorCode === 'woocommerce_rest_product_not_created' || stripos($errorMessage, 'already exists') !== false) {
            $sku = $this->extractSkuFromFailedProduct($failedProduct, $originalProducts) ?: 'Unknown SKU';
            
            // Instead of creating fake entries, try to find the actual product
            try {
                $existingProduct = $this->findProductBySKU($sku);
                if ($existingProduct) {
                    // Add the real existing product to updated array
                    $updated[] = $existingProduct;
                    Log::info("Converted SKU-conflict create to update for SKU {$sku}, found existing product ID: {$existingProduct['id']}");
                } else {
                    // If we can't find it, log as failed instead of creating fake data
                    $failed[] = [
                        'error' => "SKU conflict but could not locate existing product: {$errorCode}: {$errorMessage}",
                        'sku' => $sku,
                        'operation' => 'create_conflict',
                        'product' => $failedProduct,
                    ];
                    Log::warning("SKU conflict for {$sku} but could not locate existing product");
                }
            } catch (\Exception $e) {
                $failed[] = [
                    'error' => "SKU conflict resolution failed: " . $e->getMessage(),
                    'sku' => $sku,
                    'operation' => 'create_conflict',
                    'product' => $failedProduct,
                ];
                Log::error("Failed to resolve SKU conflict for {$sku}: " . $e->getMessage());
            }
            return;
        }
        
        // If we couldn't convert to an update, record as a failure
        $sku = $this->extractSkuFromFailedProduct($failedProduct, $originalProducts)
            ?: array_shift(array_column($originalProducts, 'sku')) 
            ?: 'Unknown SKU';
        // Append normalized failure entry
        $failed[] = [
            'error'     => "{$errorCode}: {$errorMessage}",
            'sku'       => is_string($sku) ? $sku : json_encode($sku),
            'operation' => 'create',
            'product'   => $failedProduct,
        ];
    }

    /**
     * Helper method to extract SKU from failed product response
     */
    private function extractSkuFromFailedProduct($failedProduct, $originalProducts): ?string
    {
        // Try to extract SKU from error message first
        if (isset($failedProduct['error']['message'])) {
            $message = $failedProduct['error']['message'];
            if (preg_match('/SKU-koodia \(([^)]+)\)/', $message, $matches)) {
                return $matches[1];
            }
            // Try other common SKU patterns
            if (preg_match('/SKU[:\s]*([^\s,]+)/', $message, $matches)) {
                return $matches[1];
            }
        }
        
        // If the failed product has an index, try to match with original products
        if (isset($failedProduct['index']) && isset($originalProducts[$failedProduct['index']])) {
            return $originalProducts[$failedProduct['index']]['sku'] ?? null;
        }
        
        // If failed product has SKU directly
        if (isset($failedProduct['sku'])) {
            return $failedProduct['sku'];
        }
        
        // Last resort: try to find in original products by name
        if (isset($failedProduct['name'])) {
            foreach ($originalProducts as $product) {
                if (isset($product['name']) && $product['name'] === $failedProduct['name']) {
                    return $product['sku'] ?? null;
                }
            }
        }
        
        return null;
    }

    /**
     * Helper method to find original product data by SKU
     */
    private function findProductInArray($products, $sku): ?array
    {
        foreach ($products as $product) {
            if (isset($product['sku']) && $product['sku'] === $sku) {
                return $product;
            }
        }
        return null;
    }

    /**
     * Delete multiple products by their WooCommerce IDs
     * Used for feed cleanup operations
     *
     * @param array $productIds Array of WooCommerce product IDs to delete
     * @return array Response with deletion results
     */
    public function deleteProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [
                'success' => true,
                'total_requested' => 0,
                'total_deleted' => 0,
                'failed' => [],
                'execution_time_ms' => 0
            ];
        }

        $start = microtime(true);
        $totalRequested = count($productIds);
        $deleted = [];
        $failed = [];

        Log::info("Starting batch deletion of {$totalRequested} products");

        // Prepare batch payload for deletion
        $batchPayload = [
            'delete' => array_map(function($id) {
                return ['id' => $id];
            }, $productIds)
        ];

        try {
            $response = $this->batchProducts($batchPayload);
            
            // Process deletion results
            $deleted = $response['deleted'] ?? [];
            $failed = $response['failed'] ?? [];
            
            $totalDeleted = count($deleted);
            $totalFailed = count($failed);
            
            Log::info("Batch deletion completed", [
                'total_requested' => $totalRequested,
                'total_deleted' => $totalDeleted,
                'total_failed' => $totalFailed
            ]);

        } catch (\Exception $e) {
            Log::error("Batch deletion failed: " . $e->getMessage());
            
            // Mark all as failed if batch operation fails
            foreach ($productIds as $id) {
                $failed[] = [
                    'id' => $id,
                    'error' => $e->getMessage()
                ];
            }
        }

        $executionTime = (int)((microtime(true) - $start) * 1000);
        $totalDeleted = count($deleted);
        $success = $totalDeleted > 0 || $totalRequested === 0;

        return [
            'success' => $success,
            'total_requested' => $totalRequested,
            'total_deleted' => $totalDeleted,
            'deleted' => $deleted,
            'failed' => $failed,
            'execution_time_ms' => $executionTime
        ];
    }

    /**
     * Find stale products based on timestamp metadata for stateless reconciliation
     *
     * @param int $connectionId The feed connection ID to filter by
     * @param int $cutoffTimestamp Products older than this timestamp are considered stale
     * @param int $perPage Products per page (max 100)
     * @return array Array of stale product IDs or product data
     */
    public function findStaleProducts(int $connectionId, int $cutoffTimestamp, int $perPage = 100): array
    {
        Log::info("Finding stale products for connection #{$connectionId} with cutoff timestamp {$cutoffTimestamp}");

        $staleProducts = [];
        $page = 1;
        $maxPages = 50; // Safety limit to prevent infinite loops

        do {
            try {
                // Get products with ElementaFeeds metadata
                $products = $this->makeRequest('products', [
                    'per_page' => min($perPage, 100),
                    'page' => $page,
                    'status' => 'publish', // Only check published products
                    'meta_key' => '_elementa_feed_connection_id',
                    'meta_value' => $connectionId,
                ]);

                if (empty($products)) {
                    break;
                }

                foreach ($products as $product) {
                    if ($this->isProductStale($product, $cutoffTimestamp)) {
                        $staleProducts[] = [
                            'id' => $product['id'],
                            'sku' => $product['sku'] ?? '',
                            'name' => $product['name'] ?? '',
                            'last_seen' => $this->getProductLastSeen($product)
                        ];
                    }
                }

                $page++;
                
                // Break if we got fewer products than requested (last page)
                if (count($products) < $perPage) {
                    break;
                }

            } catch (\Throwable $e) {
                Log::error("Error finding stale products on page {$page}", [
                    'connection_id' => $connectionId,
                    'page' => $page,
                    'error' => $e->getMessage()
                ]);
                break;
            }

        } while ($page <= $maxPages);

        Log::info("Found {count} stale products for connection #{$connectionId}", [
            'count' => count($staleProducts),
            'connection_id' => $connectionId,
            'pages_scanned' => $page - 1
        ]);

        return $staleProducts;
    }

    /**
     * Check if a product is stale based on its last seen timestamp
     *
     * @param array $product WooCommerce product data
     * @param int $cutoffTimestamp
     * @return bool
     */
    protected function isProductStale(array $product, int $cutoffTimestamp): bool
    {
        $lastSeen = $this->getProductLastSeen($product);
        
        if ($lastSeen === null) {
            // If no timestamp found, consider it stale
            return true;
        }

        return $lastSeen < $cutoffTimestamp;
    }

    /**
     * Get the last seen timestamp from product metadata
     *
     * @param array $product WooCommerce product data
     * @return int|null Timestamp or null if not found
     */
    protected function getProductLastSeen(array $product): ?int
    {
        if (!isset($product['meta_data']) || !is_array($product['meta_data'])) {
            return null;
        }

        foreach ($product['meta_data'] as $meta) {
            if (isset($meta['key']) && $meta['key'] === '_elementa_last_seen_timestamp') {
                return (int) ($meta['value'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Find products by connection ID (utility method for debugging/monitoring)
     *
     * @param int $connectionId The feed connection ID
     * @param int $limit Maximum number of products to return
     * @return array Array of products belonging to the connection
     */
    public function findProductsByConnection(int $connectionId, int $limit = 100): array
    {
        try {
            $products = $this->makeRequest('products', [
                'per_page' => min($limit, 100),
                'meta_key' => '_elementa_feed_connection_id',
                'meta_value' => $connectionId,
            ]);

            return $products ?? [];

        } catch (\Throwable $e) {
            Log::error("Error finding products by connection #{$connectionId}", [
                'connection_id' => $connectionId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get statistics about products managed by ElementaFeeds
     *
     * @return array Statistics about managed products
     */
    public function getElementaProductStats(): array
    {
        try {
            // Get total count of products with ElementaFeeds metadata
            $response = $this->makeRequest('products', [
                'per_page' => 1,
                'meta_key' => '_elementa_feed_connection_id',
            ], 'GET', true); // Return full response to get headers

            $totalProducts = (int) ($response['headers']['X-WP-Total'][0] ?? 0);

            // Get sample of recent products to analyze
            $recentProducts = $this->makeRequest('products', [
                'per_page' => 50,
                'orderby' => 'modified',
                'order' => 'desc',
                'meta_key' => '_elementa_feed_connection_id',
            ]);

            $connectionCounts = [];
            $oldestLastSeen = null;
            $newestLastSeen = null;

            foreach ($recentProducts as $product) {
                // Count by connection
                $connectionId = null;
                $lastSeen = null;

                foreach ($product['meta_data'] ?? [] as $meta) {
                    if ($meta['key'] === '_elementa_feed_connection_id') {
                        $connectionId = (int) $meta['value'];
                    } elseif ($meta['key'] === '_elementa_last_seen_timestamp') {
                        $lastSeen = (int) $meta['value'];
                    }
                }

                if ($connectionId) {
                    $connectionCounts[$connectionId] = ($connectionCounts[$connectionId] ?? 0) + 1;
                }

                if ($lastSeen) {
                    if ($oldestLastSeen === null || $lastSeen < $oldestLastSeen) {
                        $oldestLastSeen = $lastSeen;
                    }
                    if ($newestLastSeen === null || $lastSeen > $newestLastSeen) {
                        $newestLastSeen = $lastSeen;
                    }
                }
            }

            return [
                'total_managed_products' => $totalProducts,
                'products_by_connection' => $connectionCounts,
                'oldest_last_seen' => $oldestLastSeen ? date('Y-m-d H:i:s', $oldestLastSeen) : null,
                'newest_last_seen' => $newestLastSeen ? date('Y-m-d H:i:s', $newestLastSeen) : null,
                'sample_size' => count($recentProducts)
            ];

        } catch (\Throwable $e) {
            Log::error("Error getting ElementaFeeds product statistics", [
                'error' => $e->getMessage()
            ]);
            return ['error' => $e->getMessage()];
        }
    }
}