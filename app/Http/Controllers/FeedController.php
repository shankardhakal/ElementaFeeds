<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\DeleteFeedProductsJob;
use App\Models\FeedWebsite;
use Illuminate\Support\Facades\Log;

class FeedController extends Controller
{
    /**
     * Handle feed cleanup request.
     */
    public function cleanup(Request $request)
    {
        $request->validate([
            'feed_id' => 'required|exists:feeds,id',
        ]);

        $feedId = $request->input('feed_id');
        $results = [];

        try {
            // Get all connections for this feed
            $connections = FeedWebsite::where('feed_id', $feedId)
                ->with(['website', 'feed'])
                ->get();

            if ($connections->isEmpty()) {
                return redirect()->back()->with('error', 'No connections found for this feed.');
            }

            foreach ($connections as $connection) {
                try {
                    // Dispatch the proper deletion job for each connection
                    DeleteFeedProductsJob::dispatch($connection->id);
                    
                    $results[] = [
                        'website' => $connection->website->name,
                        'status' => 'dispatched',
                        'message' => "Product deletion job dispatched successfully.",
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'website' => $connection->website->name,
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return redirect()->back()->with('results', $results);
        } catch (\Throwable $e) {
            Log::error("Feed cleanup failed: " . $e->getMessage());
            return redirect()->back()->with('error', 'Feed cleanup failed. Please check logs for details.');
        }
    }
}
