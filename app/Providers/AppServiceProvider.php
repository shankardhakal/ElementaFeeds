<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Website; // Add this line
use App\Observers\WebsiteObserver; // Add this line
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Blade;

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

        RateLimiter::for('import-connection', function (object $job) {
            // The $job is an instance of ProcessChunkJob, which has a public connectionId property.
            $connection = \App\Models\FeedWebsite::find($job->connectionId);

            // If the connection exists, we throttle by the website ID with more conservative limits
            if ($connection) {
                // Even more conservative rate limiting - 1 job per 2 minutes per website
                // This gives more breathing room for the destination database
                return Limit::perMinutes(2, 1)->by($connection->website_id);
            }

            // As a fallback, limit by the job's unique ID to prevent errors.
            return Limit::perMinutes(2, 1)->by($job->uniqueId());
        });
        
        // Add another rate limiter specifically for API batch operations
        // This will be used by WooCommerceApiClient to limit API calls
        RateLimiter::for('woocommerce-api', function (string $websiteId) {
            // Allow only 5 batch operations every 5 minutes for a given website
            // This is very conservative and helps prevent database overload
            return Limit::perMinutes(5, 5)->by('woocommerce-api:' . $websiteId);
        });

        Blade::anonymousComponentPath(resource_path('views/backpack/custom/components'));
    }
}