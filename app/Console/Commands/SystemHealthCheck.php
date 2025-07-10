<?php

namespace App\Console\Commands;

use App\Models\FeedWebsite;
use App\Models\ImportRun;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SystemHealthCheck extends Command
{
    protected $signature = 'elementa:health-check {--connection-id= : Specific connection to check}';
    protected $description = 'Enterprise-grade health check for the ElementaFeeds system';

    public function handle()
    {
        $this->info('🔍 ElementaFeeds System Health Check');
        $this->info('=====================================');

        $connectionId = $this->option('connection-id');

        if ($connectionId) {
            $this->checkSingleConnection($connectionId);
        } else {
            $this->checkSystemOverview();
        }

        return 0;
    }

    private function checkSystemOverview()
    {
        // Active connections
        $activeConnections = FeedWebsite::where('is_active', true)->count();
        $totalConnections = FeedWebsite::count();
        
        $this->info("📊 Connection Status:");
        $this->info("   Active: {$activeConnections}/{$totalConnections}");

        // Recent import activity
        $recentImports = ImportRun::where('created_at', '>=', now()->subHours(24))->count();
        $runningImports = ImportRun::where('status', 'processing')->count();
        $failedImports = ImportRun::where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $this->info("\n📈 Import Activity (24h):");
        $this->info("   Total runs: {$recentImports}");
        $this->info("   Currently running: {$runningImports}");
        $this->info("   Failed: {$failedImports}");

        // Performance metrics
        $avgProcessingTime = ImportRun::where('status', 'completed')
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, finished_at)) as avg_time')
            ->value('avg_time');

        if ($avgProcessingTime) {
            $this->info("\n⚡ Performance:");
            $this->info("   Avg processing time: " . round($avgProcessingTime, 2) . " seconds");
        }

        // Check for potential issues
        $this->checkForIssues();
    }

    private function checkSingleConnection($connectionId)
    {
        $connection = FeedWebsite::with(['website', 'feed'])->find($connectionId);
        
        if (!$connection) {
            $this->error("Connection #{$connectionId} not found");
            return;
        }

        $this->info("🔗 Connection: {$connection->name}");
        $this->info("   Feed: {$connection->feed->name}");
        $this->info("   Website: {$connection->website->name}");
        $this->info("   Status: " . ($connection->is_active ? 'Active' : 'Inactive'));

        // API health check
        if ($connection->website->platform === 'woocommerce') {
            $this->info("\n🌐 API Health Check:");
            
            try {
                $apiClient = new WooCommerceApiClient($connection->website);
                $healthCheck = $apiClient->testConnection();
                
                if ($healthCheck['success']) {
                    $this->info("   ✅ API Connection: Healthy");
                    $this->info("   Response time: {$healthCheck['execution_time_ms']}ms");
                } else {
                    $this->error("   ❌ API Connection: Failed");
                    $this->error("   Error: " . ($healthCheck['message'] ?? 'Unknown'));
                }

                // Elementa product statistics
                $stats = $apiClient->getElementaProductStats();
                $this->info("\n📦 Product Statistics:");
                $this->info("   Elementa products: " . ($stats['total_elementa_products'] ?? 0));
                $this->info("   Connection products: " . ($stats['connection_products'][$connectionId] ?? 0));
                
            } catch (\Throwable $e) {
                $this->error("   ❌ API Check failed: " . $e->getMessage());
            }
        }

        // Recent import history
        $recentRuns = ImportRun::where('feed_website_id', $connectionId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        if ($recentRuns->isNotEmpty()) {
            $this->info("\n📋 Recent Import Runs:");
            foreach ($recentRuns as $run) {
                $duration = $run->finished_at ? 
                    $run->created_at->diffInSeconds($run->finished_at) . 's' : 
                    'Running';
                    
                $this->info("   #{$run->id}: {$run->status} - {$duration} - " . 
                    "C:{$run->created_records} U:{$run->updated_records} F:{$run->failed_records}");
            }
        }
    }

    private function checkForIssues()
    {
        $this->info("\n🚨 Issue Detection:");
        
        // Check for stuck imports
        $stuckImports = ImportRun::where('status', 'processing')
            ->where('created_at', '<', now()->subHours(2))
            ->count();

        if ($stuckImports > 0) {
            $this->warn("   ⚠️  {$stuckImports} imports may be stuck (running >2h)");
        }

        // Check for high failure rate
        $totalRecent = ImportRun::where('created_at', '>=', now()->subHours(24))->count();
        $failedRecent = ImportRun::where('created_at', '>=', now()->subHours(24))
            ->where('status', 'failed')->count();

        if ($totalRecent > 0) {
            $failureRate = ($failedRecent / $totalRecent) * 100;
            if ($failureRate > 10) {
                $this->warn("   ⚠️  High failure rate: {$failureRate}%");
            }
        }

        // Check for inactive connections with stale settings
        $staleConnections = FeedWebsite::where('is_active', true)
            ->whereNotNull('update_settings->stale_days')
            ->where('last_run_at', '<', now()->subDays(7))
            ->count();

        if ($staleConnections > 0) {
            $this->warn("   ⚠️  {$staleConnections} active connections haven't run in 7+ days");
        }

        if ($stuckImports === 0 && ($totalRecent === 0 || $failureRate <= 10) && $staleConnections === 0) {
            $this->info("   ✅ No issues detected");
        }
    }
}
