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
        // Atomically check and mark importing to prevent concurrent runs.
        try {
            DB::beginTransaction();
            $conn = FeedWebsite::where('id', $this->feedWebsiteConnection->id)
                ->lockForUpdate()
                ->first();
            if ($conn && $conn->is_importing) {
                Log::warning("Import already in progress for connection: {$conn->name} (#{$conn->id})");
                DB::rollBack();
                return;
            }
            
            // Check website-level concurrency limit
            $websiteId = $conn->website_id;
            $maxConcurrentImports = config('feeds.max_concurrent_imports_per_website', 3); // Default to 3
            $activeImports = FeedWebsite::where('website_id', $websiteId)
                ->where('is_importing', true)
                ->count();
                
            if ($activeImports >= $maxConcurrentImports) {
                Log::warning("Maximum concurrent imports ({$maxConcurrentImports}) reached for website #{$websiteId}. Connection: {$conn->name} (#{$conn->id})");
                DB::rollBack();
                return;
            }
            
            $conn->update(['is_importing' => true]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("StartImportRunJob: failed to acquire lock for connection #{$this->feedWebsiteConnection->id}: " . $e->getMessage());
            return;
        }

        Log::info("Starting import run for connection: {$this->feedWebsiteConnection->name} (#{$this->feedWebsiteConnection->id})");

        // Create a record in the `import_runs` table to track this specific execution.
        try {
            $importRun = $this->feedWebsiteConnection->importRuns()->create([
                'status' => 'processing',
                'error_records' => [],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning("Duplicate import run detected for connection #{$this->feedWebsiteConnection->id}, aborting.");
            // Reset importing flag so user can retry later
            $this->feedWebsiteConnection->update(['is_importing' => false]);
            return;
        }

        $this->feedWebsiteConnection->update(['last_run_at' => now()]);

        // Dispatch the first job in the pipeline: DownloadFeedJob.
        // We pass IDs instead of full models to prevent serialization issues.
        DownloadFeedJob::dispatch($importRun->id, $this->feedWebsiteConnection->id);

        Log::info("Dispatched DownloadFeedJob for import run #{$importRun->id}");
    }
}