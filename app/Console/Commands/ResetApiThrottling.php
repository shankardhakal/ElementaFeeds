<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Website;

class ResetApiThrottling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:reset-throttling {--website=} {--all} {--batch-size=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset API throttling and batch size limits for websites';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->option('all')) {
            $websites = Website::all();
            foreach ($websites as $website) {
                $this->resetForWebsite($website);
            }
            $this->info("Reset throttling for all websites");
        } elseif ($websiteId = $this->option('website')) {
            $website = Website::find($websiteId);
            if (!$website) {
                $this->error("Website with ID {$websiteId} not found");
                return 1;
            }
            $this->resetForWebsite($website);
            $this->info("Reset throttling for website {$website->name} (ID: {$website->id})");
        } else {
            $this->error("Please specify either --website=ID or --all");
            return 1;
        }

        return 0;
    }

    /**
     * Reset throttling for a specific website
     */
    protected function resetForWebsite(Website $website)
    {
        $domain = parse_url($website->url, PHP_URL_HOST) ?? 'unknown';
        $websiteId = md5($domain);
        
        // Clear rate limiting
        \Illuminate\Support\Facades\RateLimiter::clear('woocommerce-api:' . $websiteId);
        
        // Clear timeout recovery flags
        Cache::forget("timeout_recovery:{$websiteId}");
        Cache::forget("timeout_recovery_time:{$websiteId}");
        
        // Reset or set batch size
        if ($batchSize = $this->option('batch-size')) {
            $batchSize = (int)$batchSize;
            if ($batchSize < 5) {
                $batchSize = 5;
                $this->warn("Minimum batch size is 5. Using 5 instead.");
            } elseif ($batchSize > 50) {
                $batchSize = 50;
                $this->warn("Maximum batch size is 50. Using 50 instead.");
            }
            
            Cache::put("batch_size:{$websiteId}", $batchSize, 60 * 24);
            Cache::put("batch_size:website:{$website->id}", $batchSize, 60 * 24);
            
            $this->info("Set batch size for {$website->name} to {$batchSize}");
        } else {
            // Reset to default
            Cache::forget("batch_size:{$websiteId}");
            Cache::forget("batch_size:website:{$website->id}");
            $this->info("Reset batch size for {$website->name} to default (25)");
        }
        
        Log::info("API throttling reset for website {$website->name} (ID: {$website->id}, Hash: {$websiteId})");
    }
}
