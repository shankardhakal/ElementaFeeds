<?php

namespace App\Observers;

use App\Models\Feed;
use App\Jobs\DeleteFeedProductsJob;
use Illuminate\Support\Facades\Log;

class FeedObserver
{
    /**
     * Handle the Feed "deleting" event.
     * This is triggered before a feed is deleted.
     */
    public function deleting(Feed $feed): void
    {
        Log::info("Feed deletion triggered for: {$feed->name} (ID: {$feed->id})");
        
        // Get all connections for this feed
        $connections = $feed->websites()->get();
        
        foreach ($connections as $connection) {
            try {
                // Dispatch cleanup job for each connection
                DeleteFeedProductsJob::dispatch($connection->pivot->id);
                
                Log::info("Dispatched product cleanup job for connection #{$connection->pivot->id} ({$feed->name} → {$connection->name})");
            } catch (\Throwable $e) {
                Log::error("Failed to dispatch cleanup job for connection #{$connection->pivot->id}: " . $e->getMessage());
            }
        }
    }
}
