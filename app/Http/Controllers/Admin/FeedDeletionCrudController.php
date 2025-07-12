<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedWebsite;
use App\Jobs\DeleteFeedProductsJob;
use App\Jobs\ProcessStaleProductCleanup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeedDeletionCrudController extends Controller
{
    /**
     * Display connection cleanup management dashboard
     */
    public function index(Request $request)
    {
        $search = $request->get('search');
        $typeFilter = $request->get('type');
        $statusFilter = $request->get('status');
        
        // Build query for connections with cleanup tracking
        $query = FeedWebsite::with([
            'feed:id,name,is_active',
            'website:id,name,url',
            'cleanupRuns' => function($q) {
                $q->latest()->take(3); // Take more recent runs for better UX
            }
        ])
        ->where('is_active', true);
        
        // Search functionality
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('feed', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('website', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }
        
        // Type filter (stale vs manual)
        if ($typeFilter && $typeFilter !== 'all') {
            $query->whereHas('cleanupRuns', function($q) use ($typeFilter) {
                $q->where('type', $typeFilter);
            });
        }
        
        // Status filter
        if ($statusFilter && $statusFilter !== 'all') {
            $query->whereHas('cleanupRuns', function($q) use ($statusFilter) {
                $q->where('status', $statusFilter);
            });
        }
        
        $connections = $query->paginate(20);
        
        // Get cleanup statistics
        $stats = $this->getCleanupStats();
        
        $data = [
            'connections' => $connections,
            'stats' => $stats,
            'title' => 'Connection Cleanup Management',
            'breadcrumbs' => [
                trans('backpack::crud.admin') => backpack_url('dashboard'),
                'Connection Cleanup' => false,
            ],
        ];
        
        return view('backpack.custom.feed_deletion_dashboard', $data);
    }
    
    /**
     * Start manual cleanup for a connection
     */
    public function cleanup(Request $request, int $connectionId)
    {
        // Validate input
        $request->validate([
            'dry_run' => 'sometimes|boolean',
        ]);
        
        // Validate connection exists and user has access
        $connection = FeedWebsite::with(['feed', 'website'])
            ->where('id', $connectionId)
            ->firstOrFail();
        
        $isDryRun = $request->boolean('dry_run');
        
        try {
            // Check if there's already a running cleanup for this connection
            $runningCleanup = DB::table('connection_cleanup_runs')
                ->where('connection_id', $connectionId)
                ->where('status', 'running')
                ->first();
                
            if ($runningCleanup) {
                \Alert::warning("A cleanup is already running for '{$connection->name}'. Please wait for it to complete.")->flash();
                return redirect()->route('backpack.feed-deletion.index');
            }
            
            // Create cleanup run record with transaction safety
            DB::beginTransaction();
            
            $cleanupRun = DB::table('connection_cleanup_runs')->insertGetId([
                'connection_id' => $connectionId,
                'type' => 'manual_deletion',
                'status' => 'pending',
                'dry_run' => $isDryRun,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Dispatch enhanced cleanup job
            $job = new DeleteFeedProductsJob($connectionId, $cleanupRun);
            dispatch($job);
            
            DB::commit();
            
            $message = $isDryRun ? 
                "🧪 Dry run started for '{$connection->name}'. Check logs for results." :
                "🗑️ Cleanup started for '{$connection->name}'. This may take several minutes.";
                
            \Alert::success($message)->flash();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to start cleanup', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            \Alert::error('Failed to start cleanup: ' . $e->getMessage())->flash();
        }
        
        return redirect()->route('backpack.feed-deletion.index');
    }
    
    /**
     * Cancel a running cleanup
     */
    public function cancel(int $cleanupRunId)
    {
        // Validate cleanup run exists and belongs to accessible connection
        $cleanupRun = DB::table('connection_cleanup_runs as ccr')
            ->join('feed_website as fw', 'ccr.connection_id', '=', 'fw.id')
            ->select('ccr.*')
            ->where('ccr.id', $cleanupRunId)
            ->first();
            
        if (!$cleanupRun) {
            \Alert::error('Cleanup run not found.')->flash();
            return redirect()->route('backpack.feed-deletion.index');
        }
        
        try {
            DB::beginTransaction();
            
            $updated = DB::table('connection_cleanup_runs')
                ->where('id', $cleanupRunId)
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                
            DB::commit();
            
            if ($updated) {
                \Alert::success('Cleanup cancellation requested. Will stop after current batch.')->flash();
            } else {
                \Alert::warning('Cleanup is not currently running or already completed.')->flash();
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to cancel cleanup', [
                'cleanup_run_id' => $cleanupRunId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            \Alert::error('Failed to cancel cleanup: ' . $e->getMessage())->flash();
        }
        
        return redirect()->route('backpack.feed-deletion.index');
    }
    
    /**
     * View cleanup details
     */
    public function show(int $cleanupRunId)
    {
        $cleanupRun = DB::table('connection_cleanup_runs as ccr')
            ->join('feed_website as fw', 'ccr.connection_id', '=', 'fw.id')
            ->join('feeds as f', 'fw.feed_id', '=', 'f.id')
            ->join('websites as w', 'fw.website_id', '=', 'w.id')
            ->select([
                'ccr.*',
                'fw.name as connection_name',
                'f.name as feed_name',
                'w.name as website_name',
                'w.url as website_url'
            ])
            ->where('ccr.id', $cleanupRunId)
            ->first();
        
        if (!$cleanupRun) {
            \Alert::error('Cleanup run not found.')->flash();
            return redirect()->route('backpack.feed-deletion.index');
        }
        
        return view('backpack.custom.cleanup_run_details', compact('cleanupRun'));
    }
    
    /**
     * Get cleanup statistics
     */
    private function getCleanupStats()
    {
        $stats = DB::table('connection_cleanup_runs')
            ->select([
                DB::raw('COUNT(*) as total_runs'),
                DB::raw('COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_runs'),
                DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_runs'),
                DB::raw('COUNT(CASE WHEN status = "running" THEN 1 END) as running_runs'),
                DB::raw('SUM(products_processed) as total_products_processed')
            ])
            ->where('created_at', '>=', now()->subDays(30))
            ->first();
        
        return [
            'total_runs' => $stats->total_runs ?? 0,
            'completed_runs' => $stats->completed_runs ?? 0,
            'failed_runs' => $stats->failed_runs ?? 0,
            'running_runs' => $stats->running_runs ?? 0,
            'total_products_processed' => $stats->total_products_processed ?? 0,
            'avg_duration_minutes' => 0, // Simplified for now - can be calculated in PHP if needed
        ];
    }
}
