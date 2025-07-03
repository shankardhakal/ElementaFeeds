<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class MonitorServerLoad extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:server-load';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display a read-only overview of server resource usage.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("ElementaFeeds Server Status at: " . now());
        $this->line('---------------------------------');

        // 1. Memory Usage
        $this->line("\n<fg=yellow>Memory Usage:</>");
        exec('free -h', $memoryOutput);
        foreach ($memoryOutput as $line) {
            $this->line($line);
        }

        // 2. Top Processes
        $this->line("\n<fg=yellow>Top 10 Memory Consuming Processes:</>");
        exec('ps -eo pid,%mem,%cpu,command --sort=-%mem | head -11', $processOutput);
        foreach ($processOutput as $line) {
            $this->line($line);
        }

        // 3. Queue Worker Status
        $this->line("\n<fg=yellow>Queue Worker Status:</>");
        // Use a command that is less likely to be empty
        exec('ps -f -u ' . get_current_user() . ' | grep "queue:work" | grep -v "grep"', $workerOutput);
        $workerCount = count($workerOutput);
        $this->line("Found {$workerCount} running queue workers.");
        foreach ($workerOutput as $line) {
            $this->line("  - " . $line);
        }

        // 4. Queue Size
        $this->line("\n<fg=yellow>Queue Size:</>");
        try {
            $defaultQueueSize = Redis::llen('queues:default');
            $failedQueueSize = DB::table('failed_jobs')->count();
            $this->line("Pending Jobs (default queue): {$defaultQueueSize}");
            $this->line("Failed Jobs (database): {$failedQueueSize}");
        } catch (\Exception $e) {
            $this->error("Could not connect to Redis to check queue size: " . $e->getMessage());
        }

        $this->line('');
        $this->info('End of Report');
        
        return 0;
    }
}
