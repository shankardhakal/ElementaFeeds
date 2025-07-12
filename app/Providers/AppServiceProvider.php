<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Website; // Add this line
use App\Models\Feed; // Add this line
use App\Observers\WebsiteObserver; // Add this line
use App\Observers\FeedObserver; // Add this line
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        Website::observe(WebsiteObserver::class);
        Feed::observe(FeedObserver::class);
        Feed::observe(FeedObserver::class);

        RateLimiter::for('import-connection', function (object $job) {
            // The $job is an instance of ProcessChunkJob, which has a public connectionId property.
            $connection = \App\Models\FeedWebsite::find($job->connectionId);

            // If the connection exists, we throttle by the website ID with more conservative limits
            if ($connection) {
                $websiteId = $connection->website_id;
                
                // Check for dynamic rate limit set by MonitorServerLoad command
                $limit = Cache::get("rate_limit:import:{$websiteId}:limit", 15); // Default to 15 jobs
                $minutes = Cache::get("rate_limit:import:{$websiteId}:minutes", 1);  // per minute
                
                Log::debug("Applying rate limit for website ID {$websiteId}: {$limit} per {$minutes} minutes");
                
                // Apply the dynamic rate limit
                return Limit::perMinutes($minutes, $limit)->by($websiteId);
            }

            // As a fallback, limit by the job's unique ID to prevent errors.
            return Limit::perMinute(10)->by($job->uniqueId());
        });
        
        // This rate limiter is used by the WooCommerceApiClient to avoid hitting API request limits.
        RateLimiter::for('woocommerce-api', function (string $key) {
            // The key is expected to be in the format "websiteId:operation"
            // We only use the websiteId for rate limiting to create a per-site request pool.
            list($websiteId) = explode(':', $key, 2);

            // A generous but safe default: 120 API calls per minute per website.
            // This can be overridden by environment variables if a specific site needs more or less.
            $limit = config('feeds.woocommerce_api_limit', 120);
            $decayMinutes = config('feeds.woocommerce_api_decay_minutes', 1);

            return Limit::perMinutes($decayMinutes, $limit)->by($websiteId);
        });

        Blade::anonymousComponentPath(resource_path('views/backpack/custom/components'));

        View::addNamespace('backpack', resource_path('views/backpack'));

        $this->configureQueue();
    }

    /**
     * Configure queue for optimal memory usage
     */
    protected function configureQueue()
    {
        // Add memory optimization for queue workers
        // These settings help reduce memory leaks and improve queue worker efficiency
        
        // Ensure queue worker sleep between jobs to allow memory cleanup
        config(['queue.worker_sleep_seconds' => env('QUEUE_WORKER_SLEEP_SECONDS', 3)]);
        
        // Add a small delay between processing jobs to allow garbage collection
        Queue::after(function (JobProcessed $event) {
            // Small sleep to allow PHP garbage collection to run
            usleep(100000); // 100ms
        });
        
        // Listen for memory leak indicators (jobs that use excessive memory)
        Queue::looping(function () {
            // Check memory usage after each job loop
            $memoryUsage = memory_get_usage(true) / 1024 / 1024; // in MB
            
            // If we're using more than 120MB, force garbage collection
            if ($memoryUsage > 120) {
                // Force garbage collection
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                
                // Log high memory usage if it's excessive
                if ($memoryUsage > 150) {
                    Log::warning("Queue worker using excessive memory: {$memoryUsage}MB");
                }
            }
        });
    }
}