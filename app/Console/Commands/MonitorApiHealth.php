<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Website;
use App\Models\ImportRun;

class MonitorApiHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:monitor-health';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor API health and adjust throttling as needed';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("Starting API health monitoring...");
        
        // Get active websites with recent import runs
        $recentImportRuns = ImportRun::with('feed_website.website')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();
            
        $websiteStats = [];
        
        foreach ($recentImportRuns as $importRun) {
            if (!isset($importRun->feed_website) || !isset($importRun->feed_website->website)) {
                continue;
            }
            
            $website = $importRun->feed_website->website;
            $websiteId = $website->id;
            
            if (!isset($websiteStats[$websiteId])) {
                $websiteStats[$websiteId] = [
                    'website' => $website,
                    'import_runs' => 0,
                    'total_errors' => 0,
                    'timeout_errors' => 0,
                    'total_products' => 0,
                    'failed_products' => 0,
                ];
            }
            
            $websiteStats[$websiteId]['import_runs']++;
            $websiteStats[$websiteId]['total_products'] += $importRun->processed_records ?? 0;
            $websiteStats[$websiteId]['failed_products'] += $importRun->failed_records ?? 0;
            
            // Analyze error records
            if (!empty($importRun->error_records)) {
                foreach ($importRun->error_records as $error) {
                    $websiteStats[$websiteId]['total_errors']++;
                    
                    // Check for timeout-related errors
                    $errorMessage = $error['error'] ?? '';
                    if (str_contains(strtolower($errorMessage), 'timeout') || 
                        str_contains(strtolower($errorMessage), 'gateway') || 
                        str_contains(strtolower($errorMessage), '504')) {
                        $websiteStats[$websiteId]['timeout_errors']++;
                    }
                }
            }
        }
        
        foreach ($websiteStats as $websiteId => $stats) {
            $website = $stats['website'];
            $this->info("Website: {$website->name} (ID: {$websiteId})");
            $this->info("  Import runs: {$stats['import_runs']}");
            $this->info("  Total products: {$stats['total_products']}");
            $this->info("  Failed products: {$stats['failed_products']}");
            $this->info("  Total errors: {$stats['total_errors']}");
            $this->info("  Timeout errors: {$stats['timeout_errors']}");
            
            // Calculate error rates
            $errorRate = $stats['total_products'] > 0 ? ($stats['failed_products'] / $stats['total_products']) * 100 : 0;
            $timeoutRate = $stats['total_errors'] > 0 ? ($stats['timeout_errors'] / $stats['total_errors']) * 100 : 0;
            
            $this->info("  Error rate: " . number_format($errorRate, 2) . "%");
            $this->info("  Timeout percentage: " . number_format($timeoutRate, 2) . "%");
            
            // Make recommendations based on error rates
            if ($timeoutRate > 50 || $errorRate > 20) {
                $this->warn("  Recommendation: Consider reducing batch size and increasing delays");
                Log::warning("High error rate detected for website {$website->name} (ID: {$websiteId}). Error rate: {$errorRate}%, Timeout rate: {$timeoutRate}%");
                
                // Adjust batch size automatically if needed
                $domain = parse_url($website->url, PHP_URL_HOST) ?? 'unknown';
                $websiteHash = md5($domain);
                
                // Get current batch size
                $currentBatchSize = \Illuminate\Support\Facades\Cache::get("batch_size:{$websiteHash}", 25);
                
                // If timeouts are high, reduce batch size
                if ($timeoutRate > 50 && $currentBatchSize > 10) {
                    $newBatchSize = max(5, (int)($currentBatchSize * 0.6));
                    \Illuminate\Support\Facades\Cache::put("batch_size:{$websiteHash}", $newBatchSize, 60 * 24);
                    \Illuminate\Support\Facades\Cache::put("batch_size:website:{$websiteId}", $newBatchSize, 60 * 24);
                    
                    $this->warn("  Automatically reduced batch size from {$currentBatchSize} to {$newBatchSize}");
                    Log::warning("Automatically reduced batch size for website {$website->name} from {$currentBatchSize} to {$newBatchSize} due to high timeout rate");
                }
            } else {
                $this->info("  API health looks good for this website");
            }
            
            $this->newLine();
        }
        
        if (empty($websiteStats)) {
            $this->info("No recent import runs found in the last 24 hours");
        }

        return 0;
    }
}
