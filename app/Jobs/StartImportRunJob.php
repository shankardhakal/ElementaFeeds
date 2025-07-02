<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartImportRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * THE FIX: Renamed the property from $connection to $feedWebsiteConnection
     * to avoid a name conflict with Laravel's internal Queueable trait.
     * @var \App\Models\FeedWebsite
     */
    protected FeedWebsite $feedWebsiteConnection;

    /**
     * Create a new job instance.
     */
    public function __construct(FeedWebsite $connection)
    {
        // Assign the incoming connection model to our renamed property.
        $this->feedWebsiteConnection = $connection;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Use the renamed property throughout the handle method.
        Log::info("Starting import run for connection: {$this->feedWebsiteConnection->name} (#{$this->feedWebsiteConnection->id})");

        // Create a record in the `import_runs` table to track this specific execution.
        $importRun = $this->feedWebsiteConnection->importRuns()->create([
            'status' => 'processing',
        ]);
        
        // Mark the connection's last run time.
        $this->feedWebsiteConnection->update(['last_run_at' => now()]);

        // Dispatch the next job in our pipeline, passing it the newly created $importRun record.
        DownloadAndChunkJob::dispatch($importRun);

        Log::info("Successfully dispatched DownloadAndChunkJob for import run #{$importRun->id}.");
    }
}