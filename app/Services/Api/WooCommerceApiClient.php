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

    // Constants for timeout and retry settings - reduced to prevent 502 errors
    private const DEFAULT_TIMEOUT = 120; // 2 minutes (reduced from 4)
    private const DEGRADED_TIMEOUT = 60;  // 1 minute (reduced from 2)
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
     * Find products by metadata key and value with battle-tested validation.
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

            // Battle-tested validation: Double-verify each product's meta_data
            $validatedProducts = [];
            foreach ($response as $product) {
                $isValid = false;
                
                // Check if product has meta_data
                if (isset($product['meta_data']) && is_array($product['meta_data'])) {
                    foreach ($product['meta_data'] as $meta) {
                        if (isset($meta['key']) && isset($meta['value']) && 
                            $meta['key'] === $metaKey && 
                            (string)$meta['value'] === (string)$metaValue) {
                            $isValid = true;
                            break;
                        }
                    }
                }
                
                if ($isValid) {
                    $validatedProducts[] = $product['id'];
                } else {
                    Log::warning("WooCommerce API returned invalid product", [
                        'product_id' => $product['id'],
                        'expected_meta_key' => $metaKey,
                        'expected_meta_value' => $metaValue,
                        'actual_meta_data' => $product['meta_data'] ?? 'null'
                    ]);
                }
            }

            return $validatedProducts;
        } catch (\Exception $e) {
            Log::error("Failed to find products by metadata: " . $e->getMessage());
            return [];
        }
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
                
                // Update circuit breaker state for successful requests
                $this->updateCircuitState($this->websiteId, true);
                
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

            // Update circuit breaker state for failed requests
            $errorType = $this->classifyErrorType($errorBody, $response->status());
            $this->updateCircuitState($this->websiteId, false, $errorType);

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
            
            // For timeout errors, reduce batch size
            if ($errorType === 'timeout') {
                $this->reduceBatchSizeForTimeout($websiteId);
            }
            
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
        // The key is a combination of website ID and the operation type.
        $rateLimiterKey = "{$this->websiteId}:{$operation}";
        $limiterName = 'woocommerce-api';

        // The RateLimiter is configured in AppServiceProvider.
        // We check if we have exceeded the limit for our key.
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limiterName, $rateLimiterKey)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($limiterName, $rateLimiterKey);
            Log::warning("Rate limit reached for website #{$this->websiteId} ({$operation} operation). Must wait {$seconds} seconds before trying again.");
            
            // Sleep for the required time, plus one second to be safe.
            // Capped at 60 seconds to avoid excessively long waits.
            sleep(min($seconds + 1, 60));
        }
        
        // Record a "hit" for this key to count against the rate limit.
        \Illuminate\Support\Facades\RateLimiter::hit($limiterName, $rateLimiterKey);
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





    /**
     * GUPID-based upsert method that eliminates SKU conflicts
     * This method uses Global Unique Product Identifiers stored in meta fields
     * 
     * @param array $products Products with GUPID in meta_data
     * @return array Upsert results
     */
    public function upsertProductsByGUPID(array $products): array
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

        Log::info("Starting GUPID-based upsert for {$totalRequested} products");

        // Step 1: Extract GUPIDs for lookup
        $gupids = [];
        $productsByGupid = [];
        
        foreach ($products as $product) {
            $gupid = null;
            
            // Extract GUPID from meta_data
            if (isset($product['meta_data']) && is_array($product['meta_data'])) {
                foreach ($product['meta_data'] as $meta) {
                    if (isset($meta['key']) && $meta['key'] === 'gupid') {
                        $gupid = $meta['value'];
                        break;
                    }
                }
            }
            
            if (!$gupid) {
                $failed[] = [
                    'error' => 'Missing GUPID in meta_data',
                    'sku' => $product['sku'] ?? 'Unknown SKU',
                    'operation' => 'gupid_upsert',
                    'product' => $product
                ];
                continue;
            }
            
            $gupids[] = $gupid;
            $productsByGupid[$gupid] = $product;
        }

        // Step 2: Get existing product map by GUPID
        $existingProducts = [];
        try {
            Log::debug("Looking up GUPIDs", ['gupids' => array_slice($gupids, 0, 3), 'total_count' => count($gupids)]);
            $existingProducts = $this->getProductIdMapByGUPIDs($gupids);
            Log::debug("Found " . count($existingProducts) . " existing products via GUPID lookup", ['found_gupids' => array_keys($existingProducts)]);
        } catch (\Exception $e) {
            Log::error("GUPID lookup failed due to server errors: " . $e->getMessage());
            
            // For server errors, we should not proceed as we can't determine existing products
            // Mark all products as failed and return
            foreach ($productsByGupid as $gupid => $product) {
                $failed[] = [
                    'error' => 'GUPID lookup failed due to server errors: ' . $e->getMessage(),
                    'sku' => $product['sku'] ?? 'Unknown SKU',
                    'operation' => 'gupid_lookup_failed',
                    'gupid' => $gupid,
                    'product' => $product
                ];
            }
            
            return [
                'success' => false,
                'total_requested' => $totalRequested,
                'total_created' => 0,
                'total_updated' => 0,
                'created' => [],
                'updated' => [],
                'failed' => $failed,
                'execution_time_ms' => (int)((microtime(true) - $start) * 1000),
                'error' => 'GUPID lookup failed due to server errors. Import will be retried.'
            ];
        }

        // Step 3: Separate products into create and update batches
        $toCreate = [];
        $toUpdate = [];

        foreach ($productsByGupid as $gupid => $product) {
            if (isset($existingProducts[$gupid])) {
                // Product exists, prepare for update
                $updateProduct = $product;
                $updateProduct['id'] = $existingProducts[$gupid];
                
                // CRITICAL: Remove SKU from update operations to prevent duplicate SKU errors
                // WooCommerce doesn't allow changing SKU to an existing SKU, even if it's the same product
                if (isset($updateProduct['sku'])) {
                    unset($updateProduct['sku']);
                }
                
                $toUpdate[] = $updateProduct;
            } else {
                // Product doesn't exist, prepare for creation (keep SKU for new products)
                $toCreate[] = $product;
            }
        }

        Log::debug("Batch separation results", [
            'to_create' => count($toCreate),
            'to_update' => count($toUpdate),
            'existing_products_found' => count($existingProducts),
            'sample_create_gupid' => !empty($toCreate) ? $this->extractGUPIDFromProduct($toCreate[0]) : null,
            'sample_update_gupid' => !empty($toUpdate) ? $this->extractGUPIDFromProduct($toUpdate[0]) : null,
            'sample_update_id' => !empty($toUpdate) ? ($toUpdate[0]['id'] ?? 'missing') : null
        ]);

        // Step 4: Execute batch operations
        $batchPayload = [];
        if (!empty($toCreate)) {
            $batchPayload['create'] = $toCreate;
        }
        if (!empty($toUpdate)) {
            $batchPayload['update'] = $toUpdate;
        }
        
        if (!empty($batchPayload)) {
            try {
                $batchResp = $this->batchProducts($batchPayload);
                
                // Collect results
                $created = $batchResp['created'] ?? [];
                $updated = $batchResp['updated'] ?? [];
                
                // Handle any failures - should be rare with GUPID system
                foreach ($batchResp['failed'] ?? [] as $failedItem) {
                    $gupid = $this->extractGUPIDFromFailedProduct($failedItem, array_merge($toCreate, $toUpdate));
                    $failed[] = [
                        'error' => $failedItem['error'] ?? 'Unknown error',
                        'sku' => $failedItem['sku'] ?? 'Unknown SKU', 
                        'operation' => 'gupid_upsert',
                        'gupid' => $gupid,
                        'product' => $failedItem
                    ];
                }
                
                Log::info("GUPID-based batch upsert: created " . count($created) . ", updated " . count($updated) . ", failed " . count($failed));
            } catch (\Exception $e) {
                Log::error("GUPID-based batch operation failed: " . $e->getMessage());
                
                // For timeout errors, return the error to the calling method for batch splitting
                $isTimeout = str_contains($e->getMessage(), '504') || str_contains($e->getMessage(), 'Gateway Time-out') || str_contains($e->getMessage(), 'cURL error 28');
                Log::info("GUPID upsert error condition check", [
                    'error_message' => $e->getMessage(),
                    'is_timeout' => $isTimeout,
                    'contains_504' => str_contains($e->getMessage(), '504'),
                    'contains_gateway_timeout' => str_contains($e->getMessage(), 'Gateway Time-out'),
                    'contains_curl_error' => str_contains($e->getMessage(), 'cURL error 28')
                ]);
                
                if ($isTimeout) {
                    Log::info("Returning timeout error for batch splitting");
                    return [
                        'success' => false,
                        'total_requested' => $totalRequested,
                        'total_created' => 0,
                        'total_updated' => 0,
                        'created' => [],
                        'updated' => [],
                        'failed' => [],
                        'execution_time_ms' => (int)((microtime(true) - $start) * 1000),
                        'error' => $e->getMessage()
                    ];
                }
                
                // For other errors, mark all products as failed
                foreach ($productsByGupid as $gupid => $product) {
                    $failed[] = [
                        'error' => $e->getMessage(),
                        'sku' => $product['sku'] ?? 'Unknown SKU',
                        'operation' => 'gupid_upsert',
                        'gupid' => $gupid,
                        'product' => $product
                    ];
                }
            }
        }

        $executionTime = (int)((microtime(true) - $start) * 1000);
        $totalCreated = count($created);
        $totalUpdated = count($updated);
        $totalFailed = count($failed);
        $success = ($totalCreated + $totalUpdated) > 0;

        Log::info("GUPID-based upsert completed", [
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
            'execution_time_ms' => $executionTime,
            'error' => $totalFailed > 0 ? 'Some products failed' : null
        ];
    }

    /**
     * Get product ID map by GUPIDs with optimized caching and lookup
     * 
     * @param array $gupids Array of GUPIDs to lookup
     * @return array Map of GUPID => product_id
     */
    protected function getProductIdMapByGUPIDs(array $gupids): array
    {
        if (empty($gupids)) {
            return [];
        }

        $map = [];
        $cacheKey = 'gupid_lookup_' . $this->websiteId . '_' . md5(implode(',', $gupids));
        
        // Try to get from cache first
        $cachedMap = Cache::get($cacheKey);
        if ($cachedMap !== null) {
            Log::debug("GUPID lookup cache hit", ['cache_key' => $cacheKey, 'found_count' => count($cachedMap)]);
            return $cachedMap;
        }

        $batchSize = 20; // Reduced batch size for better performance
        $batches = array_chunk($gupids, $batchSize);
        $hasServerErrors = false;

        foreach ($batches as $batchIndex => $batch) {
            $retryCount = 0;
            $maxRetries = 3;
            
            while ($retryCount < $maxRetries) {
                try {
                    // Optimized approach: Use meta_value_like for better performance
                    $page = 1;
                    $perPage = 100;
                    $foundInBatch = [];
                    
                    do {
                        $params = [
                            'per_page' => $perPage,
                            'page' => $page,
                            'meta_key' => 'gupid',
                            'orderby' => 'id',
                            'order' => 'desc',
                            'status' => 'any' // Include all statuses
                        ];

                        Log::debug("GUPID lookup batch {$batchIndex} page {$page}", [
                            'batch_size' => count($batch),
                            'sample_gupids' => array_slice($batch, 0, 2)
                        ]);

                        $response = $this->makeRequest('products', $params);
                        
                        if (!is_array($response) || empty($response)) {
                            break;
                        }
                        
                        // Process products and match GUPIDs
                        foreach ($response as $product) {
                            $productId = $product['id'] ?? null;
                            $metaData = $product['meta_data'] ?? [];
                            
                            if ($productId) {
                                foreach ($metaData as $meta) {
                                    if (isset($meta['key']) && $meta['key'] === 'gupid') {
                                        $gupid = $meta['value'];
                                        if (in_array($gupid, $batch)) {
                                            $map[$gupid] = $productId;
                                            $foundInBatch[] = $gupid;
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                        
                        // Early exit if we found all GUPIDs in this batch
                        if (count($foundInBatch) === count($batch)) {
                            break;
                        }
                        
                        // Stop if we got fewer results than requested
                        if (count($response) < $perPage) {
                            break;
                        }
                        
                        $page++;
                        
                        // Rate limiting between pages
                        if ($page > 1) {
                            usleep(50000); // 50ms delay
                        }
                        
                    } while ($page <= 20); // Reasonable limit
                    
                    // Success - exit retry loop
                    break;
                    
                } catch (\Exception $e) {
                    $retryCount++;
                    
                    if (str_contains($e->getMessage(), '502') || 
                        str_contains($e->getMessage(), '503') || 
                        str_contains($e->getMessage(), '504')) {
                        if ($retryCount < $maxRetries) {
                            Log::warning("GUPID lookup server error, retrying ({$retryCount}/{$maxRetries}): " . $e->getMessage());
                            sleep(2);
                            continue;
                        } else {
                            Log::error("GUPID lookup failed after {$maxRetries} retries: " . $e->getMessage());
                            $hasServerErrors = true;
                            break;
                        }
                    } else {
                        Log::warning("GUPID lookup failed: " . $e->getMessage());
                        break;
                    }
                }
            }
            
            // Rate limiting between batches
            if (count($batches) > 1 && $batchIndex < count($batches) - 1) {
                usleep(100000); // 100ms delay
            }
        }

        if ($hasServerErrors) {
            throw new \Exception("Server errors prevent GUPID lookup. Please try again later.");
        }

        // Cache results for 15 minutes
        Cache::put($cacheKey, $map, 900);
        
        Log::debug("GUPID lookup completed", [
            'requested_count' => count($gupids),
            'found_count' => count($map),
            'cache_key' => $cacheKey
        ]);

        return $map;
    }

    /**
     * Extract GUPID from a failed product response
     * 
     * @param array $failedProduct The failed product data
     * @param array $originalProducts Original product array to search (fallback by index)
     * @return string|null The GUPID if found
     */
    protected function extractGUPIDFromFailedProduct(array $failedProduct, array $originalProducts): ?string
    {
        // Try to extract from the failed product itself
        if (isset($failedProduct['meta_data']) && is_array($failedProduct['meta_data'])) {
            foreach ($failedProduct['meta_data'] as $meta) {
                if (isset($meta['key']) && $meta['key'] === 'gupid') {
                    return $meta['value'];
                }
            }
        }

        // Fallback: search in original products by array index if available
        if (isset($failedProduct['index']) && isset($originalProducts[$failedProduct['index']])) {
            $originalProduct = $originalProducts[$failedProduct['index']];
            if (isset($originalProduct['meta_data']) && is_array($originalProduct['meta_data'])) {
                foreach ($originalProduct['meta_data'] as $meta) {
                    if (isset($meta['key']) && $meta['key'] === 'gupid') {
                        return $meta['value'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract GUPID from a product's meta_data
     * 
     * @param array $product The product data
     * @return string|null The GUPID if found
     */
    protected function extractGUPIDFromProduct(array $product): ?string
    {
        if (isset($product['meta_data'])) {
            foreach ($product['meta_data'] as $meta) {
                if (isset($meta['key']) && $meta['key'] === 'gupid') {
                    return $meta['value'];
                }
            }
        }
        return null;
    }

    /**
     * Find a product by its source identifier using GUPID system
     * This replaces the old SKU-based lookup with GUPID-based lookup
     * 
     * @param string $sourceIdentifier The source identifier (SKU)
     * @param int $connectionId The connection ID to generate GUPID
     * @return array|null Product data if found, null otherwise
     */
    public function findProductBySourceIdentifier(string $sourceIdentifier, int $connectionId): ?array
    {
        // Generate GUPID from connection ID and source identifier
        $gupid = sha1("connection_{$connectionId}_source_{$sourceIdentifier}");
        
        // Use the existing GUPID-based method
        $products = $this->findProductsByGUPID([$gupid]);
        
        return !empty($products) ? $products[0] : null;
    }

    /**
     * Create a test product for connection testing
     * 
     * @return array Test result with success status and product data
     */
    public function createTestProduct(): array
    {
        $testSku = 'test-' . time() . '-' . rand(1000, 9999);
        $testProduct = [
            'name' => 'Test Product ' . date('Y-m-d H:i:s'),
            'sku' => $testSku,
            'type' => 'simple',
            'regular_price' => '10.00',
            'status' => 'publish',
            'description' => 'This is a test product created by ElementaFeeds',
            'short_description' => 'Test product',
            'meta_data' => [
                ['key' => 'test_product', 'value' => 'true'],
                ['key' => 'created_by', 'value' => 'ElementaFeeds']
            ]
        ];

        try {
            $start = microtime(true);
            $productId = $this->createProduct($testProduct);
            $executionTime = (int)((microtime(true) - $start) * 1000);
            
            if ($productId) {
                // Fetch the created product to return full data
                $createdProduct = $this->makeRequest("products/{$productId}");
                
                return [
                    'success' => true,
                    'product' => $createdProduct,
                    'execution_time_ms' => $executionTime
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to create test product',
                    'execution_time_ms' => $executionTime
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => 0
            ];
        }
    }

    /**
     * Find products by connection ID using the stateless meta_data approach.
     * This method is battle-tested and fail-proof for meta value comparison.
     *
     * @param int $connectionId The feed_website connection ID
     * @return array List of product IDs matching the connection ID
     */
    public function findProductsByConnectionId(int $connectionId): array
    {
        $productIds = [];
        $page = 1;
        $perPage = 100;
        
        // Ensure connection ID is always a string for consistent API comparison
        $connectionIdString = (string)$connectionId;
        
        do {
            $params = [
                'meta_key' => 'elementa_feed_connection_id',
                'meta_value' => $connectionIdString,
                'per_page' => $perPage,
                'page' => $page,
                'status' => 'any' // Include all product statuses
            ];

            try {
                Log::debug("Searching for products with connection ID {$connectionId}, page {$page}");
                $response = $this->makeRequest('products', $params);
                
                if (!is_array($response) || empty($response)) {
                    break; // No more products
                }
                
                // BATTLE-TESTED: Double-check each product's meta_data to ensure exact match
                // This prevents false positives from WooCommerce's meta query inconsistencies
                foreach ($response as $product) {
                    if (isset($product['id'], $product['meta_data']) && is_array($product['meta_data'])) {
                        $hasMatchingConnection = false;
                        
                        // Verify the connection ID matches exactly
                        foreach ($product['meta_data'] as $meta) {
                            if (isset($meta['key']) && $meta['key'] === 'elementa_feed_connection_id') {
                                $metaValue = (string)$meta['value']; // Ensure string comparison
                                
                                if ($metaValue === $connectionIdString) {
                                    $hasMatchingConnection = true;
                                    break;
                                }
                            }
                        }
                        
                        // Only add products that have been double-verified
                        if ($hasMatchingConnection) {
                            $productIds[] = $product['id'];
                        } else {
                            // Log potential WooCommerce API inconsistency
                            Log::warning("Product {$product['id']} returned by WooCommerce API but failed double-verification for connection ID {$connectionId}");
                        }
                    }
                }
                
                Log::debug("Found " . count($response) . " products on page {$page} for connection {$connectionId}");
                
                // If we got less than perPage results, we've reached the end
                if (count($response) < $perPage) {
                    break;
                }
                
                $page++;
                
                // Add small delay between pages to prevent overwhelming the server
                usleep(100000); // 100ms delay
                
            } catch (\Exception $e) {
                Log::error("Failed to find products by connection ID {$connectionId} on page {$page}: " . $e->getMessage());
                break;
            }
            
        } while ($page <= 50); // Safety limit
        
        Log::info("Found total " . count($productIds) . " products for connection ID {$connectionId}");
        return $productIds;
    }

    /**
     * Find products by connection ID with pagination support
     * This method provides paginated results for cleanup operations
     * 
     * @param int $connectionId The feed_website connection ID
     * @param int $page The page number (1-based)
     * @param int $perPage Number of products per page
     * @return array Array of products for the specified page
     */
    public function findProductsByConnectionIdPaginated(int $connectionId, int $page = 1, int $perPage = 100): array
    {
        $products = [];
        
        // Ensure connection ID is always a string for consistent API comparison
        $connectionIdString = (string)$connectionId;
        
        $params = [
            'meta_key' => 'elementa_feed_connection_id',
            'meta_value' => $connectionIdString,
            'per_page' => $perPage,
            'page' => $page,
            'status' => 'any' // Include all product statuses
        ];

        try {
            Log::debug("Searching for products with connection ID {$connectionId}, page {$page}, per_page {$perPage}");
            $response = $this->makeRequest('products', $params);
            
            if (!is_array($response) || empty($response)) {
                return []; // No products found
            }
            
            // BATTLE-TESTED: Double-check each product's meta_data to ensure exact match
            // This prevents false positives from WooCommerce's meta query inconsistencies
            foreach ($response as $product) {
                if (isset($product['id'], $product['meta_data']) && is_array($product['meta_data'])) {
                    $hasMatchingConnection = false;
                    
                    // Verify the connection ID matches exactly
                    foreach ($product['meta_data'] as $meta) {
                        if (isset($meta['key']) && $meta['key'] === 'elementa_feed_connection_id') {
                            $metaValue = (string)$meta['value']; // Ensure string comparison
                            
                            if ($metaValue === $connectionIdString) {
                                $hasMatchingConnection = true;
                                break;
                            }
                        }
                    }
                    
                    // Only add products that have been double-verified
                    if ($hasMatchingConnection) {
                        $products[] = $product;
                    } else {
                        // Log potential WooCommerce API inconsistency
                        Log::warning("Product {$product['id']} returned by WooCommerce API but failed double-verification for connection ID {$connectionId}");
                    }
                }
            }
            
            Log::debug("Found " . count($products) . " verified products on page {$page} for connection {$connectionId}");
            
        } catch (\Exception $e) {
            Log::error("Failed to find products by connection ID {$connectionId} on page {$page}: " . $e->getMessage());
        }
        
        return $products;
    }

    /**
     * Verify that products were actually created/updated with correct metadata (import pipeline validation).
     * This battle-tested method ensures the import pipeline's integrity by validating real results.
     *
     * @param array $productIds List of product IDs to verify
     * @param int $connectionId Expected connection ID in metadata
     * @param int $importRunId Expected import run ID in metadata
     * @return array ['verified' => [...], 'failed' => [...], 'missing' => [...]]
     */
    public function verifyImportedProducts(array $productIds, int $connectionId, int $importRunId): array
    {
        if (empty($productIds)) {
            return ['verified' => [], 'failed' => [], 'missing' => []];
        }

        $verified = [];
        $failed = [];
        $missing = [];

        Log::info("Verifying {count} imported products for connection #{$connectionId}, import run #{$importRunId}", [
            'count' => count($productIds),
            'connection_id' => $connectionId,
            'import_run_id' => $importRunId
        ]);

        // Process in chunks to respect API limits
        $chunks = array_chunk($productIds, 20);
        
        foreach ($chunks as $chunk) {
            try {
                // Fetch products by IDs
                $response = $this->makeRequest('products', [
                    'include' => implode(',', $chunk),
                    'per_page' => count($chunk),
                    'status' => 'any'
                ]);

                $foundIds = [];
                foreach ($response as $product) {
                    $productId = $product['id'];
                    $foundIds[] = $productId;
                    
                    // Battle-tested validation: Check metadata integrity
                    $hasCorrectConnection = false;
                    $hasCorrectImportRun = false;
                    
                    if (isset($product['meta_data']) && is_array($product['meta_data'])) {
                        foreach ($product['meta_data'] as $meta) {
                            if (isset($meta['key']) && isset($meta['value'])) {
                                if ($meta['key'] === 'elementa_feed_connection_id' && 
                                    (string)$meta['value'] === (string)$connectionId) {
                                    $hasCorrectConnection = true;
                                }
                                if ($meta['key'] === 'import_run_id' && 
                                    (string)$meta['value'] === (string)$importRunId) {
                                    $hasCorrectImportRun = true;
                                }
                            }
                        }
                    }
                    
                    if ($hasCorrectConnection && $hasCorrectImportRun) {
                        $verified[] = [
                            'id' => $productId,
                            'sku' => $product['sku'] ?? 'unknown',
                            'status' => $product['status'] ?? 'unknown',
                            'name' => $product['name'] ?? 'unknown'
                        ];
                    } else {
                        $failed[] = [
                            'id' => $productId,
                            'sku' => $product['sku'] ?? 'unknown',
                            'issue' => 'Missing or incorrect metadata',
                            'has_connection_id' => $hasCorrectConnection,
                            'has_import_run_id' => $hasCorrectImportRun,
                            'meta_data' => $product['meta_data'] ?? []
                        ];
                        
                        Log::warning("Product metadata validation failed", [
                            'product_id' => $productId,
                            'expected_connection_id' => $connectionId,
                            'expected_import_run_id' => $importRunId,
                            'has_correct_connection' => $hasCorrectConnection,
                            'has_correct_import_run' => $hasCorrectImportRun
                        ]);
                    }
                }
                
                // Check for missing products
                foreach ($chunk as $expectedId) {
                    if (!in_array($expectedId, $foundIds)) {
                        $missing[] = [
                            'id' => $expectedId,
                            'issue' => 'Product not found in WooCommerce'
                        ];
                        
                        Log::warning("Product missing from WooCommerce", [
                            'product_id' => $expectedId,
                            'connection_id' => $connectionId,
                            'import_run_id' => $importRunId
                        ]);
                    }
                }
                
            } catch (\Exception $e) {
                Log::error("Failed to verify product chunk: " . $e->getMessage(), [
                    'chunk' => $chunk,
                    'connection_id' => $connectionId,
                    'import_run_id' => $importRunId
                ]);
                
                // Mark all products in this chunk as failed
                foreach ($chunk as $productId) {
                    $failed[] = [
                        'id' => $productId,
                        'issue' => 'Verification failed: ' . $e->getMessage()
                    ];
                }
            }
        }

        Log::info("Import verification completed", [
            'total_requested' => count($productIds),
            'verified' => count($verified),
            'failed' => count($failed),
            'missing' => count($missing),
            'connection_id' => $connectionId,
            'import_run_id' => $importRunId
        ]);

        return [
            'verified' => $verified,
            'failed' => $failed,
            'missing' => $missing
        ];
    }

    /**
     * Find products by GUPIDs and return full product data
     * 
 
     * @param array $gupids Array of GUPIDs to search for
     * @return array Array of full product data for matching GUPIDs
     */
    public function findProductsByGUPID(array $gupids): array
    {
        if (empty($gupids)) {
            return [];
        }

        $products = [];
        $batchSize = 20; // Process GUPIDs in batches for optimal performance
        $batches = array_chunk($gupids, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $retryCount = 0;
            $maxRetries = 3;
            
            while ($retryCount < $maxRetries) {
                try {
                    $page = 1;
                    $perPage = 100;
                    
                    do {
                        $params = [
                            'per_page' => $perPage,
                            'page' => $page,
                            'meta_key' => 'gupid',
                            'orderby' => 'id',
                            'order' => 'desc',
                            'status' => 'any' // Include all statuses
                        ];

                        Log::debug("FindProductsByGUPID batch {$batchIndex} page {$page}", [
                            'batch_size' => count($batch),
                            'sample_gupids' => array_slice($batch, 0, 2)
                        ]);

                        $response = $this->makeRequest('products', $params);
                        
                        if (!is_array($response) || empty($response)) {
                            break;
                        }
                        
                        // Process products and match GUPIDs
                        foreach ($response as $product) {
                            $metaData = $product['meta_data'] ?? [];
                            
                            foreach ($metaData as $meta) {
                                if (isset($meta['key']) && $meta['key'] === 'gupid') {
                                    $gupid = $meta['value'];
                                    if (in_array($gupid, $batch)) {
                                        $products[] = $product;
                                    }
                                    break;
                                }
                            }
                        }
                        
                        // Stop if we got fewer results than requested
                        if (count($response) < $perPage) {
                            break;
                        }
                        
                        $page++;
                        
                        // Rate limiting between pages
                        if ($page > 1) {
                            usleep(50000); // 50ms delay
                        }
                        
                    } while ($page <= 20); // Reasonable limit
                    
                    // Success - exit retry loop
                    break;
                    
                } catch (\Exception $e) {
                    $retryCount++;
                    
                    if (str_contains($e->getMessage(), '502') || 
                        str_contains($e->getMessage(), '503') || 
                        str_contains($e->getMessage(), '504')) {
                        if ($retryCount < $maxRetries) {
                            Log::warning("FindProductsByGUPID server error, retrying ({$retryCount}/{$maxRetries}): " . $e->getMessage());
                            sleep(2);
                            continue;
                        }
                    }
                    
                    Log::error("FindProductsByGUPID failed: " . $e->getMessage());
                    break;
                }
            }
            
            // Rate limiting between batches
            if (count($batches) > 1 && $batchIndex < count($batches) - 1) {
                usleep(100000); // 100ms delay
            }
        }

        Log::debug("FindProductsByGUPID completed", [
            'requested_count' => count($gupids),
            'found_count' => count($products)
        ]);

        return $products;
    }
}