<?php

namespace App\Observers;

use App\Models\Website;
use App\Jobs\TestApiConnectionJob;

class WebsiteObserver
{
    /**
     * Handle the Website "saved" event.
     * This is triggered after a website is created or updated.
     */
    public function saved(Website $website): void
    {
        // Dispatch the job to test the API connection in the background.
        TestApiConnectionJob::dispatch($website->id);
    }
}