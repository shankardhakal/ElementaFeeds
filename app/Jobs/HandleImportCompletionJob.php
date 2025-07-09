<?php

namespace App\Jobs;

use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * HandleImportCompletionJob
 *
 * This job handles the final steps after an import run is successfully completed.
 *
 * Key Tasks:
 * - Updates the status of the import run to "completed".
 * - Logs the completion details.
 * - Schedules the ReconcileProductStatusJob to publish draft products.
 * - Cleans up temporary chunk files.
 */
class HandleImportCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $importRunId;

    /**
     * Create a new job instance.
     *
     * @param int $importRunId
     */
    public function __construct(int $importRunId)
    {
        $this->importRunId = $importRunId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $importRun = ImportRun::find($this->importRunId);

        if ($importRun && $importRun->status !== 'failed') {
            Log::info("Batch for ImportRun #{$this->importRunId} completed successfully. Finalizing run.");
            
            // Safely update status with constraint handling
            try {
                $importRun->status = 'completed';
                $importRun->finished_at = now();
                $importRun->save();
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Clean up any conflicting stuck runs for this connection
                \App\Models\ImportRun::where('feed_website_id', $importRun->feed_website_id)
                    ->where('status', 'completed')
                    ->where('id', '!=', $importRun->id)
                    ->where('updated_at', '<', now()->subMinutes(30))
                    ->update([
                        'status' => 'expired',
                        'finished_at' => now(),
                        'log_messages' => 'Import run status changed to avoid constraint violation.'
                    ]);
                
                // Retry the status update
                $importRun->refresh();
                $importRun->status = 'completed';
                $importRun->finished_at = now();
                $importRun->save();
            }
            
            // Schedule the ReconcileProductStatusJob to run after import completion
            // This will attempt to publish any products left in draft status
            try {
                // Find the connection ID from the import run
                $feedWebsiteId = $importRun->feed_website_id;
                
                // Add a delay before running reconciliation to let the system stabilize
                ReconcileProductStatusJob::dispatch($this->importRunId, $feedWebsiteId)
                    ->delay(now()->addMinutes(1)); // Reduced delay to 1 minute for faster testing
                
                Log::info("Scheduled ReconcileProductStatusJob for ImportRun #{$this->importRunId} to run in 1 minute");
            } catch (\Exception $e) {
                Log::error("Failed to schedule ReconcileProductStatusJob for ImportRun #{$this->importRunId}: " . $e->getMessage());
            }
        } else {
            Log::warning("HandleImportCompletionJob: ImportRun #{$this->importRunId} not found or has failed. Skipping reconciliation scheduling.");
        }

        $this->cleanupChunks($this->importRunId);
    }

    /**
     * Clean up the chunk directory for a given import run.
     *
     * @param int $importRunId The ID of the import run.
     * @return void
     *
     * Key Tasks:
     * - Deletes the chunk directory and its contents.
     * - Logs the cleanup operation.
     */
    protected function cleanupChunks(int $importRunId): void
    {
        $chunkDirectory = storage_path("app/import_chunks/{$importRunId}");
        if (File::isDirectory($chunkDirectory)) {
            File::deleteDirectory($chunkDirectory);
            Log::info("Cleaned up chunk directory for ImportRun #{$importRunId}.");
        }
    }
}
