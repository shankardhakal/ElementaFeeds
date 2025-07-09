<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feed;
use App\Models\Website;
use App\Models\FeedWebsite;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use App\Models\ImportRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function dashboard()
    {
        $data['title'] = 'ElementaFeeds Dashboard';
        $data['breadcrumbs'] = [
            trans('backpack::crud.admin') => backpack_url('dashboard'),
            'Dashboard' => false,
        ];

        // --- Custom Data ---
        $data['websiteCount'] = Website::count();
        $data['feedCount'] = Feed::count();
        $data['connectionCount'] = FeedWebsite::count();
        $data['activeConnectionCount'] = FeedWebsite::where('is_active', true)->count();
        
        // --- Queue & Job Stats ---
        try {
            // Get pending jobs from the default Redis queue
            $data['pendingJobs'] = Redis::llen('queues:default');
        } catch (\Exception $e) {
            $data['pendingJobs'] = 'N/A';
            Log::error('DashboardController: Could not connect to Redis. ' . $e->getMessage());
        }

        // Get failed jobs from the database table
        $data['failedJobs'] = DB::table('failed_jobs')->count();

        // --- Import Run Analytics (from last 7 days) ---
        $recentRuns = ImportRun::where('created_at', '>=', now()->subDays(7))->get();
        $totalRuns = $recentRuns->count();
        $successfulRuns = $recentRuns->whereIn('status', ['completed', 'completed_with_errors'])->count();

        $data['successRatio'] = ($totalRuns > 0) ? round(($successfulRuns / $totalRuns) * 100) : 100;

        // Calculate average duration for successfully completed runs
        $completedRuns = $recentRuns->where('status', 'completed');
        if ($completedRuns->count() > 0) {
            $totalDurationSeconds = $completedRuns->sum(function ($run) {
                return Carbon::parse($run->updated_at)->diffInSeconds(Carbon::parse($run->created_at));
            });
            $averageDuration = round($totalDurationSeconds / $completedRuns->count());
            $data['averageDuration'] = gmdate('H:i:s', $averageDuration);
        } else {
            $data['averageDuration'] = 'N/A';
        }

        // Get the last 10 import runs for the dashboard table
        $data['recentImportRuns'] = ImportRun::with(['feedWebsite.feed', 'feedWebsite.website'])
                                        ->orderBy('created_at', 'desc')
                                        ->take(10)
                                        ->get();

        $data['feeds'] = Feed::all();

        // Mapping wizard enhancement: include the unique identifier field for mapping
        $data['destination_fields']['unique_identifier'] = 'Unique Identifier (Feed Name + Source ID)';

        // This now points to your custom view path.
        return view('backpack.custom.dashboard', $data);
    }

    /**
     * Download detailed error records for an import run.
     */
    public function errors(int $id)
    {
        $run = ImportRun::findOrFail($id);
        return response()->json(['errors' => $run->error_records ?? []]);
    }
}