<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class ManageQueueWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:manage-workers
                           {--restart : Restart all queue workers}
                           {--optimize : Optimize queue workers (restart high-memory workers)}
                           {--kill-all : Kill all queue workers (use with caution)}
                           {--dynamic : Run in dynamic scaling mode based on queue size}
                           {--max-memory=150 : Max memory threshold for worker optimization (MB)}
                           {--count=8 : Number of workers to maintain}
                           {--max-time=3600 : Max lifetime for new workers (seconds)}
                           {--memory=150 : Memory limit for new workers (MB)}
                           {--min-workers=1 : Minimum number of workers in dynamic mode}
                           {--max-workers=8 : Maximum number of workers in dynamic mode}
                           {--jobs-per-worker=50 : Number of jobs that trigger adding a worker}
                           {--check-interval=60 : Seconds between checks in dynamic mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Laravel queue workers to optimize memory usage and performance';

    /**
     * Worker processes found on the system
     */
    protected $workers = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Queue Worker Manager');
        $this->line('---------------------');
        
        // Check if dynamic mode is enabled
        if ($this->option('dynamic')) {
            $this->runDynamicScaling();
            return 0;
        }
        
        // Get current queue workers
        $this->findQueueWorkers();
        
        // Display current workers
        $this->displayWorkers();
        
        // Handle command options
        if ($this->option('kill-all')) {
            $this->killAllWorkers();
        } elseif ($this->option('restart')) {
            $this->restartAllWorkers();
        } elseif ($this->option('optimize')) {
            $this->optimizeWorkers();
        } else {
            // Just display worker stats if no action option was provided
            $this->workerStats();
        }
        
        return 0;
    }
    
    /**
     * Find all queue workers running on the system
     */
    protected function findQueueWorkers()
    {
        // Get list of PHP processes with detailed memory information
        exec("ps -o pid,%cpu,%mem,rss,vsz,etime,command -C php", $output);
        
        // Extract queue workers
        $this->workers = [];
        
        foreach ($output as $index => $line) {
            if ($index === 0) continue; // Skip header line
            
            if (strpos($line, 'queue:work') !== false) {
                $parts = preg_split('/\s+/', trim($line), 7);
                if (count($parts) >= 7) {
                    list($pid, $cpu, $mem, $rss, $vsz, $etime, $command) = $parts;
                    
                    // Calculate memory in MB (RSS is in KB)
                    $memoryMB = round($rss / 1024, 2);
                    
                    // Parse uptime
                    $uptimeSeconds = $this->parseUptime($etime);
                    
                    $this->workers[] = [
                        'pid' => $pid,
                        'cpu' => (float) $cpu,
                        'mem_percent' => (float) $mem,
                        'memory_mb' => $memoryMB,
                        'uptime_seconds' => $uptimeSeconds,
                        'uptime' => $etime,
                        'command' => $command
                    ];
                }
            }
        }
        
        // Sort workers by memory usage (descending)
        usort($this->workers, function($a, $b) {
            return $b['memory_mb'] <=> $a['memory_mb'];
        });
    }
    
    /**
     * Parse uptime string to seconds
     */
    protected function parseUptime($uptime)
    {
        $parts = explode('-', $uptime);
        $days = 0;
        
        if (count($parts) > 1) {
            $days = (int) $parts[0];
            $timeStr = $parts[1];
        } else {
            $timeStr = $parts[0];
        }
        
        $timeParts = explode(':', $timeStr);
        $hours = 0;
        $minutes = 0;
        $seconds = 0;
        
        if (count($timeParts) === 3) {
            $hours = (int) $timeParts[0];
            $minutes = (int) $timeParts[1];
            $seconds = (int) $timeParts[2];
        } elseif (count($timeParts) === 2) {
            $minutes = (int) $timeParts[0];
            $seconds = (int) $timeParts[1];
        } elseif (count($timeParts) === 1) {
            $seconds = (int) $timeParts[0];
        }
        
        return $days * 86400 + $hours * 3600 + $minutes * 60 + $seconds;
    }
    
    /**
     * Display all queue workers
     */
    protected function displayWorkers()
    {
        if (empty($this->workers)) {
            $this->warn('No queue workers found');
            return;
        }
        
        $this->info('Current Queue Workers: ' . count($this->workers));
        
        $this->table(
            ['PID', 'CPU %', 'MEM %', 'Memory MB', 'Uptime', 'Command'],
            array_map(function($worker) {
                return [
                    $worker['pid'],
                    $worker['cpu'],
                    $worker['mem_percent'],
                    $worker['memory_mb'],
                    $worker['uptime'],
                    substr($worker['command'], 0, 60) . (strlen($worker['command']) > 60 ? '...' : '')
                ];
            }, $this->workers)
        );
    }
    
    /**
     * Display worker statistics and memory patterns
     */
    protected function workerStats()
    {
        if (empty($this->workers)) {
            return;
        }
        
        // Calculate average memory usage
        $totalMemory = array_sum(array_column($this->workers, 'memory_mb'));
        $avgMemory = round($totalMemory / count($this->workers), 2);
        
        // Get memory ranges
        $memoryRanges = [
            '0-50MB' => 0,
            '50-100MB' => 0,
            '100-150MB' => 0,
            '150-200MB' => 0,
            '200MB+' => 0
        ];
        
        foreach ($this->workers as $worker) {
            $mem = $worker['memory_mb'];
            
            if ($mem < 50) {
                $memoryRanges['0-50MB']++;
            } elseif ($mem < 100) {
                $memoryRanges['50-100MB']++;
            } elseif ($mem < 150) {
                $memoryRanges['100-150MB']++;
            } elseif ($mem < 200) {
                $memoryRanges['150-200MB']++;
            } else {
                $memoryRanges['200MB+']++;
            }
        }
        
        // Calculate uptime ranges
        $uptimeRanges = [
            '0-15 min' => 0,
            '15-30 min' => 0,
            '30-60 min' => 0,
            '1-3 hours' => 0,
            '3+ hours' => 0
        ];
        
        foreach ($this->workers as $worker) {
            $uptime = $worker['uptime_seconds'];
            
            if ($uptime < 900) { // 15 minutes
                $uptimeRanges['0-15 min']++;
            } elseif ($uptime < 1800) { // 30 minutes
                $uptimeRanges['15-30 min']++;
            } elseif ($uptime < 3600) { // 60 minutes
                $uptimeRanges['30-60 min']++;
            } elseif ($uptime < 10800) { // 3 hours
                $uptimeRanges['1-3 hours']++;
            } else {
                $uptimeRanges['3+ hours']++;
            }
        }
        
        // Display memory ranges
        $this->line('');
        $this->info('Worker Memory Distribution:');
        $this->table(
            ['Memory Range', 'Worker Count', 'Percentage'],
            array_map(function($range, $count) {
                $percent = round(($count / count($this->workers)) * 100, 1);
                return [$range, $count, "$percent%"];
            }, array_keys($memoryRanges), array_values($memoryRanges))
        );
        
        // Display uptime ranges
        $this->line('');
        $this->info('Worker Uptime Distribution:');
        $this->table(
            ['Uptime Range', 'Worker Count', 'Percentage'],
            array_map(function($range, $count) {
                $percent = round(($count / count($this->workers)) * 100, 1);
                return [$range, $count, "$percent%"];
            }, array_keys($uptimeRanges), array_values($uptimeRanges))
        );
        
        // Check for memory leak patterns
        $this->checkMemoryLeakPatterns();
        
        // Display recommendations
        $this->line('');
        $this->info('Recommendations:');
        
        $maxMemory = $this->option('max-memory');
        $targetCount = $this->option('count');
        
        if ($avgMemory > $maxMemory) {
            $this->warn("- Average memory usage ($avgMemory MB) exceeds threshold ($maxMemory MB)");
            $this->line("  Recommendation: Run with --optimize to restart high-memory workers");
        }
        
        if (count($this->workers) !== $targetCount) {
            if (count($this->workers) < $targetCount) {
                $this->warn("- Running fewer workers (" . count($this->workers) . ") than target ($targetCount)");
                $this->line("  Recommendation: Run with --restart to restore the target worker count");
            } else {
                $this->warn("- Running more workers (" . count($this->workers) . ") than target ($targetCount)");
                $this->line("  Recommendation: Run with --optimize to reduce to the target worker count");
            }
        }
        
        // Check if we have any very high memory workers
        $highMemWorkers = array_filter($this->workers, function($worker) use ($maxMemory) {
            return $worker['memory_mb'] > $maxMemory;
        });
        
        if (count($highMemWorkers) > 0) {
            $this->warn("- Found " . count($highMemWorkers) . " workers exceeding memory threshold ($maxMemory MB)");
            $this->line("  Recommendation: Run with --optimize to restart these high-memory workers");
        }
    }
    
    /**
     * Check for memory leak patterns in workers
     */
    protected function checkMemoryLeakPatterns()
    {
        // Group workers by uptime ranges
        $uptimeGroups = [
            'short' => [], // 0-30 min
            'medium' => [], // 30-60 min
            'long' => [] // 60+ min
        ];
        
        foreach ($this->workers as $worker) {
            $uptime = $worker['uptime_seconds'];
            
            if ($uptime < 1800) { // 30 minutes
                $uptimeGroups['short'][] = $worker;
            } elseif ($uptime < 3600) { // 60 minutes
                $uptimeGroups['medium'][] = $worker;
            } else {
                $uptimeGroups['long'][] = $worker;
            }
        }
        
        // Calculate average memory by group
        $averages = [];
        
        foreach ($uptimeGroups as $group => $workers) {
            if (empty($workers)) {
                $averages[$group] = 0;
            } else {
                $totalMem = array_sum(array_column($workers, 'memory_mb'));
                $averages[$group] = round($totalMem / count($workers), 2);
            }
        }
        
        // Display memory vs uptime analysis
        $this->line('');
        $this->info('Memory vs Uptime Analysis:');
        
        $this->table(
            ['Uptime Group', 'Avg Memory (MB)', 'Worker Count'],
            [
                ['Short (0-30 min)', $averages['short'], count($uptimeGroups['short'])],
                ['Medium (30-60 min)', $averages['medium'], count($uptimeGroups['medium'])],
                ['Long (60+ min)', $averages['long'], count($uptimeGroups['long'])]
            ]
        );
        
        // Check for memory leak pattern
        if ($averages['short'] > 0 && $averages['long'] > 0 && $averages['long'] > ($averages['short'] * 1.3)) {
            $ratio = round($averages['long'] / $averages['short'], 2);
            $this->line('');
            $this->error("MEMORY LEAK DETECTED: Long-running workers use {$ratio}x more memory than short-running workers");
            $this->line("This indicates a memory leak in the application or queue jobs.");
            $this->line("Consider implementing shorter worker lifetimes or investigating the leak source.");
        }
    }
    
    /**
     * Kill all queue workers
     */
    protected function killAllWorkers()
    {
        if (empty($this->workers)) {
            $this->warn('No queue workers found to kill');
            return;
        }
        
        $this->warn('Killing all ' . count($this->workers) . ' queue workers...');
        
        foreach ($this->workers as $worker) {
            $pid = $worker['pid'];
            exec("kill $pid");
            $this->line("Killed worker PID $pid");
        }
        
        $this->info('All queue workers terminated');
        Log::warning('ManageQueueWorkers: All queue workers were terminated via command');
        
        $this->startNewWorkers();
    }
    
    /**
     * Restart all queue workers
     */
    protected function restartAllWorkers()
    {
        if (!empty($this->workers)) {
            $this->warn('Restarting all ' . count($this->workers) . ' queue workers...');
            
            foreach ($this->workers as $worker) {
                $pid = $worker['pid'];
                exec("kill $pid");
                $this->line("Terminated worker PID $pid");
            }
            
            $this->info('All queue workers terminated');
        } else {
            $this->warn('No existing queue workers found');
        }
        
        $this->startNewWorkers();
    }
    
    /**
     * Optimize queue workers by restarting high-memory workers
     */
    protected function optimizeWorkers()
    {
        $maxMemory = $this->option('max-memory');
        $targetCount = $this->option('count');
        
        // Find high memory workers
        $highMemWorkers = array_filter($this->workers, function($worker) use ($maxMemory) {
            return $worker['memory_mb'] > $maxMemory;
        });
        
        // Calculate how many workers to keep
        $workersToKeep = min(count($this->workers), $targetCount);
        $workersToRemove = count($this->workers) - $workersToKeep;
        
        if ($workersToRemove > 0) {
            // Combine high memory workers with excess workers
            $workersToKill = array_slice($this->workers, 0, $workersToRemove);
            $highMemWorkers = array_merge($highMemWorkers, $workersToKill);
            
            // Remove duplicates
            $uniqueWorkers = [];
            foreach ($highMemWorkers as $worker) {
                $uniqueWorkers[$worker['pid']] = $worker;
            }
            $highMemWorkers = array_values($uniqueWorkers);
        }
        
        if (empty($highMemWorkers)) {
            $this->info('No high-memory workers found to optimize');
            
            // If we have fewer workers than target, start more
            if (count($this->workers) < $targetCount) {
                $workersToStart = $targetCount - count($this->workers);
                $this->startAdditionalWorkers($workersToStart);
            }
            
            return;
        }
        
        $this->warn('Restarting ' . count($highMemWorkers) . ' high-memory or excess workers...');
        
        foreach ($highMemWorkers as $worker) {
            $pid = $worker['pid'];
            $memory = $worker['memory_mb'];
            exec("kill $pid");
            $this->line("Terminated worker PID $pid (Memory: $memory MB)");
        }
        
        $this->info('High-memory workers terminated');
        Log::warning('ManageQueueWorkers: Restarted ' . count($highMemWorkers) . ' high-memory workers');
        
        // Start replacement workers
        $this->startAdditionalWorkers(count($highMemWorkers));
    }
    
    /**
     * Start new queue workers
     */
    protected function startNewWorkers()
    {
        $count = $this->option('count');
        $maxTime = $this->option('max-time');
        $memory = $this->option('memory');
        
        $this->info("Starting $count new queue workers...");
        
        for ($i = 0; $i < $count; $i++) {
            exec("php " . base_path('artisan') . " queue:work --max-time=$maxTime --memory=$memory > /dev/null 2>&1 &");
        }
        
        $this->info("Started $count new queue workers with max lifetime of $maxTime seconds and memory limit of $memory MB");
        Log::info("ManageQueueWorkers: Started $count new queue workers");
    }
    
    /**
     * Start additional queue workers
     */
    protected function startAdditionalWorkers($count, $maxMemory, $maxTime)
    {
        $sleep = config('queue.worker_configuration.sleep', 3);
        $tries = config('queue.worker_configuration.tries', 3);
        $queue = config('queue.connections.' . config('queue.default') . '.queue', 'default');
        
        $this->info("Starting {$count} new worker(s)");
        
        for ($i = 0; $i < $count; $i++) {
            $command = "php artisan queue:work --sleep={$sleep} --tries={$tries} --max-memory={$maxMemory} --max-time={$maxTime} --queue={$queue} > /dev/null 2>&1 &";
            exec($command);
        }
    }
    
    /**
     * Reduce the number of workers by stopping the specified count
     */
    protected function reduceWorkers($count)
    {
        // Make sure we have workers data
        if (empty($this->workers)) {
            $this->findQueueWorkers();
        }
        
        // Get the workers sorted by memory usage (highest first)
        // This ensures we remove the most memory-hungry workers first
        $sortedWorkers = $this->workers;
        usort($sortedWorkers, function($a, $b) {
            return $b['memory_mb'] <=> $a['memory_mb'];
        });
        
        // Take only the number we need to remove
        $workersToStop = array_slice($sortedWorkers, 0, $count);
        
        foreach ($workersToStop as $worker) {
            $this->info("Stopping worker PID {$worker['pid']} (memory: {$worker['memory_mb']} MB)");
            exec("kill {$worker['pid']}");
        }
    }
    
    /**
     * Run dynamic worker scaling based on queue size
     */
    protected function runDynamicScaling()
    {
        $minWorkers = (int) $this->option('min-workers');
        $maxWorkers = (int) $this->option('max-workers');
        $jobsPerWorker = (int) $this->option('jobs-per-worker');
        $checkInterval = (int) $this->option('check-interval');
        $maxMemory = (int) $this->option('max-memory');
        $maxTime = (int) $this->option('max-time');
        
        // Use env variables if configured
        if (config('queue.dynamic_scaling.enabled', false)) {
            $minWorkers = config('queue.dynamic_scaling.min_workers', $minWorkers);
            $maxWorkers = config('queue.dynamic_scaling.max_workers', $maxWorkers);
            $jobsPerWorker = config('queue.dynamic_scaling.jobs_per_worker', $jobsPerWorker);
            $checkInterval = config('queue.dynamic_scaling.check_interval', $checkInterval);
            $maxMemory = config('queue.dynamic_scaling.worker_memory_limit', $maxMemory);
            $maxTime = config('queue.dynamic_scaling.worker_max_time', $maxTime);
        }
        
        $this->info("Starting dynamic worker scaling:");
        $this->line("- Minimum workers: $minWorkers");
        $this->line("- Maximum workers: $maxWorkers");
        $this->line("- Jobs per worker: $jobsPerWorker");
        $this->line("- Check interval: $checkInterval seconds");
        $this->line("- Worker memory limit: $maxMemory MB");
        $this->line("- Worker max lifetime: $maxTime seconds");
        $this->line('---------------------');
        
        // Run continuous monitoring and scaling
        while (true) {
            // Find current workers
            $this->findQueueWorkers();
            $currentWorkers = count($this->workers);
            
            // Check queue size
            $queueSize = $this->getQueueSize();
            
            // Calculate target worker count
            $targetWorkers = $this->calculateOptimalWorkers($queueSize, $minWorkers, $maxWorkers, $jobsPerWorker);
            
            // Scale workers if needed
            if ($targetWorkers > $currentWorkers) {
                $this->info(date('Y-m-d H:i:s') . " - Scaling up: {$currentWorkers} → {$targetWorkers} workers (queue size: {$queueSize})");
                $this->startAdditionalWorkers($targetWorkers - $currentWorkers, $maxMemory, $maxTime);
            } elseif ($targetWorkers < $currentWorkers) {
                $this->info(date('Y-m-d H:i:s') . " - Scaling down: {$currentWorkers} → {$targetWorkers} workers (queue size: {$queueSize})");
                $this->reduceWorkers($currentWorkers - $targetWorkers);
            } else {
                $this->info(date('Y-m-d H:i:s') . " - Maintaining {$currentWorkers} workers (queue size: {$queueSize})");
            }
            
            // Check system memory
            $systemMemory = $this->getSystemMemoryPercentage();
            if ($systemMemory > config('queue.dynamic_scaling.high_memory_threshold', 90)) {
                $this->warn(date('Y-m-d H:i:s') . " - High memory usage ({$systemMemory}%) - optimizing workers");
                $this->optimizeWorkers($maxMemory);
            }
            
            // Wait for next check
            $this->line(date('Y-m-d H:i:s') . " - Next check in {$checkInterval} seconds");
            sleep($checkInterval);
        }
    }
    
    /**
     * Calculate the optimal number of workers based on queue size and system load
     */
    protected function calculateOptimalWorkers($queueSize, $minWorkers, $maxWorkers, $jobsPerWorker)
    {
        // Start with minimum workers
        $workers = $minWorkers;
        
        // If queue has jobs, scale up based on jobs_per_worker ratio
        if ($queueSize > 0) {
            $calculatedWorkers = ceil($queueSize / $jobsPerWorker);
            $workers = max($workers, $calculatedWorkers);
        }
        
        // Cap at max_workers
        $workers = min($workers, $maxWorkers);
        
        // If system memory usage is high, scale back down
        $memoryUsage = $this->getSystemMemoryPercentage();
        $highMemThreshold = config('queue.dynamic_scaling.high_memory_threshold', 90);
        
        if ($memoryUsage > $highMemThreshold) {
            $this->warn("Memory usage is high ({$memoryUsage}%), reducing workers");
            $workers = max(1, floor($workers / 2));
        }
        
        return $workers;
    }
    
    /**
     * Get the system memory usage as a percentage
     */
    protected function getSystemMemoryPercentage()
    {
        exec('free | grep Mem | awk \'{print $3/$2 * 100.0}\'', $output);
        return round((float)$output[0], 2);
    }
    
    /**
     * Get the current queue size
     */
    protected function getQueueSize()
    {
        $connection = config('queue.default');
        $queue = config("queue.connections.{$connection}.queue", 'default');
        
        if ($connection === 'redis') {
            return Redis::connection(
                config('queue.connections.redis.connection', 'default')
            )->llen('queues:' . $queue);
        }
        
        // For database queue, query the jobs table
        if ($connection === 'database') {
            $table = config('queue.connections.database.table', 'jobs');
            return DB::table($table)
                ->where('queue', $queue)
                ->count();
        }
        
        // For other queue types, default to 0 (not implemented)
        return 0;
    }
    
    /**
     * Get system memory usage
     */
    protected function getSystemMemoryUsage()
    {
        $memInfo = [];
        
        try {
            // Try to get memory info from /proc/meminfo
            exec('free -m', $output);
            
            if (!empty($output)) {
                preg_match('/Mem:\s+(\d+)\s+(\d+)/', implode("\n", $output), $matches);
                
                if (isset($matches[1]) && isset($matches[2])) {
                    $totalMem = (int)$matches[1];
                    $usedMem = (int)$matches[2];
                    $percentage = round(($usedMem / $totalMem) * 100, 1);
                    
                    $memInfo = [
                        'total' => $totalMem,
                        'used' => $usedMem,
                        'percentage' => $percentage
                    ];
                }
            }
        } catch (\Exception $e) {
            // Default values if we can't get memory info
            $memInfo = [
                'total' => 0,
                'used' => 0,
                'percentage' => 0
            ];
        }
        
        return $memInfo;
    }
    
    /**
     * Optimize to a specific worker count
     */
    protected function optimizeToCount($targetCount)
    {
        if (empty($this->workers)) {
            if ($targetCount > 0) {
                $this->startAdditionalWorkers($targetCount);
            }
            return;
        }
        
        $currentCount = count($this->workers);
        
        if ($currentCount <= $targetCount) {
            // Need to add workers
            $workersToAdd = $targetCount - $currentCount;
            if ($workersToAdd > 0) {
                $this->startAdditionalWorkers($workersToAdd);
            }
            return;
        }
        
        // Need to remove workers
        $workersToRemove = $currentCount - $targetCount;
        
        // Sort by memory usage (remove highest memory users first)
        usort($this->workers, function($a, $b) {
            return $b['memory_mb'] <=> $a['memory_mb'];
        });
        
        // Get workers to remove
        $workersToKill = array_slice($this->workers, 0, $workersToRemove);
        
        foreach ($workersToKill as $worker) {
            $pid = $worker['pid'];
            $memory = $worker['memory_mb'];
            exec("kill -15 $pid"); // SIGTERM for graceful shutdown
            $this->line("Terminated worker PID $pid (Memory: $memory MB)");
        }
        
        $this->info("Removed $workersToRemove workers, keeping $targetCount workers running");
        Log::info("ManageQueueWorkers: Scaled down to $targetCount workers");
    }
    
    /**
     * Check for high memory workers and restart them
     */
    protected function checkForHighMemoryWorkers()
    {
        $maxMemory = $this->option('max-memory');
        
        // Find high memory workers
        $highMemWorkers = array_filter($this->workers, function($worker) use ($maxMemory) {
            return $worker['memory_mb'] > $maxMemory;
        });
        
        if (empty($highMemWorkers)) {
            return;
        }
        
        $this->warn('Found ' . count($highMemWorkers) . ' high-memory workers exceeding ' . $maxMemory . 'MB');
        
        foreach ($highMemWorkers as $worker) {
            $pid = $worker['pid'];
            $memory = $worker['memory_mb'];
            exec("kill -15 $pid"); // SIGTERM for graceful shutdown
            $this->line("Restarting high-memory worker PID $pid (Memory: $memory MB)");
        }
        
        // Start replacement workers
        $this->startAdditionalWorkers(count($highMemWorkers));
        
        $this->info("Restarted " . count($highMemWorkers) . " high-memory workers");
        Log::info("ManageQueueWorkers: Restarted " . count($highMemWorkers) . " high-memory workers");
    }
    
    /**
     * Display worker memory statistics
     */
    protected function displayWorkerMemoryStats()
    {
        if (empty($this->workers)) {
            return;
        }
        
        // Calculate total and average memory
        $totalMemory = array_sum(array_column($this->workers, 'memory_mb'));
        $avgMemory = round($totalMemory / count($this->workers), 2);
        $maxMemWorker = max(array_column($this->workers, 'memory_mb'));
        $minMemWorker = min(array_column($this->workers, 'memory_mb'));
        
        $this->line("Worker memory usage: Avg: {$avgMemory}MB, Min: {$minMemWorker}MB, Max: {$maxMemWorker}MB, Total: {$totalMemory}MB");
    }
}
