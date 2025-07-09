<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessChunkJob;
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
            'feed_id' => 'required|exists:feed_website,feed_id',
        ]);

        $feedId = $request->input('feed_id');
        $results = [];

        try {
            $websites = FeedWebsite::where('feed_id', $feedId)->get();

            foreach ($websites as $website) {
                try {
                    $job = new ProcessChunkJob(0, $feedId, '');
                    $job->deleteFeedProducts($feedId);

                    $results[] = [
                        'website' => $website->website->name,
                        'status' => 'success',
                        'message' => "Products deleted successfully.",
                    ];
                } catch (\Throwable $e) {
                    $results[] = [
                        'website' => $website->website->name,
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return view('backpack.custom.cleanup_results', ['results' => $results]);
        } catch (\Throwable $e) {
            Log::error("Feed cleanup failed: " . $e->getMessage());
            return redirect()->back()->with('error', 'Feed cleanup failed. Please check logs for details.');
        }
    }
}
