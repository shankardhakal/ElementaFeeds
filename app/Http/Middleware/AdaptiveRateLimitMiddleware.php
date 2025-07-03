<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AdaptiveRateLimitMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if request is to WooCommerce API
        if (str_contains($request->url(), 'wp-json/wc/v3/')) {
            $domain = parse_url($request->url(), PHP_URL_HOST);
            $websiteId = md5($domain);
            
            // Check if we're in a timeout recovery period
            $timeoutKey = "timeout_recovery:{$websiteId}";
            $isRecovering = Cache::get($timeoutKey, false);
            
            if ($isRecovering) {
                $recoveryTime = Cache::get("timeout_recovery_time:{$websiteId}", 60);
                Log::warning("Website {$domain} is in timeout recovery mode. Waiting {$recoveryTime} seconds before allowing request.");
                
                // Implement an increasing backoff if we're repeatedly hitting timeouts
                sleep($recoveryTime);
                
                // Increase recovery time for future timeouts (up to 5 minutes)
                $newRecoveryTime = min(300, $recoveryTime * 1.5);
                Cache::put("timeout_recovery_time:{$websiteId}", $newRecoveryTime, 60 * 24);
            }
            
            // Track this request for response monitoring
            $requestId = uniqid();
            $requestKey = "woo_request:{$requestId}";
            Cache::put($requestKey, [
                'url' => $request->url(),
                'method' => $request->method(),
                'time' => microtime(true)
            ], 60);
            
            // Attach request ID to the request for our response handler
            $request->attributes->set('woo_request_id', $requestId);
            $request->attributes->set('woo_website_id', $websiteId);
        }
        
        $response = $next($request);
        
        // Handle response monitoring for timeout detection
        if ($request->attributes->has('woo_request_id')) {
            $requestId = $request->attributes->get('woo_request_id');
            $websiteId = $request->attributes->get('woo_website_id');
            $requestKey = "woo_request:{$requestId}";
            $requestData = Cache::get($requestKey);
            
            if ($requestData && isset($response->status()) && $response->status() >= 500) {
                // A server error occurred - possibly a timeout
                $duration = microtime(true) - $requestData['time'];
                Log::warning("WooCommerce API request failed with status {$response->status()} after {$duration} seconds", [
                    'url' => $requestData['url'],
                    'method' => $requestData['method']
                ]);
                
                // Put this website in timeout recovery mode
                Cache::put("timeout_recovery:{$websiteId}", true, 60); // 1 hour timeout recovery
                
                // Set initial recovery time if not already set
                if (!Cache::has("timeout_recovery_time:{$websiteId}")) {
                    Cache::put("timeout_recovery_time:{$websiteId}", 60, 60 * 24); // Start with 60 seconds
                }
                
                // Reduce batch size for this website
                $this->reduceBatchSize($websiteId);
            }
            
            // Clean up
            Cache::forget($requestKey);
        }
        
        return $response;
    }
    
    /**
     * Reduce the batch size for a website
     */
    private function reduceBatchSize(string $websiteId): void
    {
        $cacheKey = "batch_size:{$websiteId}";
        $currentSize = Cache::get($cacheKey, 25);
        
        // More aggressive reduction - 50% reduction but never below 5
        $newSize = max(5, (int)($currentSize * 0.5));
        
        if ($newSize < $currentSize) {
            Cache::put($cacheKey, $newSize, 60 * 24); // Store for 24 hours
            Log::warning("Middleware reduced batch size for website {$websiteId} from {$currentSize} to {$newSize} due to server errors");
        }
    }
}
