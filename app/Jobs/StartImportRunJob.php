<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartImportRunJob implements ShouldQueue, ShouldBeUnique
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
     * Get the unique ID for the job instance.
     */
    public function uniqueId(): string
    {
        return (string)$this->feedWebsiteConnection->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if an import is already in progress for this connection.
        if ($this->feedWebsiteConnection->is_importing) {
            Log::warning("Import already in progress for connection: {$this->feedWebsiteConnection->name} (#{$this->feedWebsiteConnection->id})");
            return;
        }

        // Mark the connection as currently importing.
        $this->feedWebsiteConnection->update(['is_importing' => true]);

        Log::info("Starting import run for connection: {$this->feedWebsiteConnection->name} (#{$this->feedWebsiteConnection->id})");

        // Create a record in the `import_runs` table to track this specific execution.
        $importRun = $this->feedWebsiteConnection->importRuns()->create([
            'status' => 'pending', // The run is pending until the first real work starts.
            'error_records' => [], // Initialize error records as an empty JSON array
        ]);

        $this->feedWebsiteConnection->update(['last_run_at' => now()]);

        // Dispatch the first job in the pipeline: DownloadFeedJob.
        // We pass IDs instead of full models to prevent serialization issues.
        DownloadFeedJob::dispatch($importRun->id, $this->feedWebsiteConnection->id);

        Log::info("Dispatched DownloadFeedJob for import run #{$importRun->id}");
    }
}