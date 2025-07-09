<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use Illuminate\Support\Facades\DB;
use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * StartImportRunJob
 *
 * This job initiates a new import run.
 *
 * Key Tasks:
 * - Creates a new record in the import_runs table.
 * - Dispatches the first job in the import pipeline (DownloadFeedJob).
 */
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
        Log::info("Starting import run for connection: {$this->feedWebsiteConnection->name} (#{$this->feedWebsiteConnection->id})");

        // Create a record in the `import_runs` table to track this specific execution.
        // The unique constraint on (feed_website_id, status) prevents concurrent runs.
        try {
            $importRun = $this->feedWebsiteConnection->importRuns()->create([
                'status' => 'processing',
                'error_records' => [],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle constraint violation (duplicate processing run)
            if ($e->getCode() === '23000') {
                Log::warning("Concurrent import run detected for connection #{$this->feedWebsiteConnection->id}, aborting.");
                return;
            }
            
            // Handle other database errors
            Log::error("Failed to create import run for connection #{$this->feedWebsiteConnection->id}: " . $e->getMessage());
            return;
        }

        $this->feedWebsiteConnection->update(['last_run_at' => now()]);

        // Dispatch the first job in the pipeline: DownloadFeedJob.
        // We pass IDs instead of full models to prevent serialization issues.
        DownloadFeedJob::dispatch($importRun->id, $this->feedWebsiteConnection->id);

        Log::info("Dispatched DownloadFeedJob for import run #{$importRun->id}");
    }
}