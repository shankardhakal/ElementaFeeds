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
                // Allow only 1 job per minute per website
                // This ensures we don't overwhelm the destination site
                return Limit::perMinute(1)->by($connection->website_id);
            }

            // As a fallback, limit by the job's unique ID to prevent errors.
            return Limit::perMinute(1)->by($job->uniqueId());
        });

        Blade::anonymousComponentPath(resource_path('views/backpack/custom/components'));
    }
}