<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Website; // Add this line
use App\Observers\WebsiteObserver; // Add this line

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Add this line to register the observer
        Website::observe(WebsiteObserver::class);
    }
}