<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Jobs\ReconcileProductStatusJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
            // Determine final status based on recorded failures
            $hasErrors = ($importRun->failed_records ?? 0) > 0;
            $finalStatus = $hasErrors ? 'completed_with_errors' : 'completed';
            Log::info("Batch for ImportRun #{$this->importRunId} completed (errors: {$importRun->failed_records}). Finalizing run as '{$finalStatus}'.");
            
            // Safely update status with constraint handling
            try {
                // Use a database transaction to ensure atomicity
                DB::transaction(function() use ($importRun, $finalStatus) {
                    // First, expire any old completed runs for this connection
                    $expiredCount = \App\Models\ImportRun::where('feed_website_id', $importRun->feed_website_id)
                        ->where('status', 'completed')
                        ->where('id', '!=', $importRun->id)
                        ->where('updated_at', '<', now()->subMinutes(30))
                        ->update([
                            'status' => 'expired',
                            'finished_at' => now(),
                            'log_messages' => 'Import run status changed to avoid constraint violation.'
                        ]);
                    
                    if ($expiredCount > 0) {
                        Log::info("Expired {$expiredCount} old import runs to prevent constraint violation");
                    }
                    
                    // Now update the current run to final status
                    $importRun->status = $finalStatus;
                    $importRun->finished_at = now();
                    $importRun->save();
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                Log::warning("Constraint violation when completing import run #{$importRun->id}. Attempting recovery...");
                
                // Try to resolve the conflict by expiring conflicting runs
                try {
                    DB::transaction(function() use ($importRun, $finalStatus) {
                        // Force expire ALL other completed runs for this connection
                        \App\Models\ImportRun::where('feed_website_id', $importRun->feed_website_id)
                            ->where('status', 'completed')
                            ->where('id', '!=', $importRun->id)
                            ->update([
                                'status' => 'expired',
                                'finished_at' => now(),
                                'log_messages' => 'Import run status changed due to constraint violation recovery.'
                            ]);
                        
                        // Try again to mark this run as final status
                        $importRun->status = $finalStatus;
                        $importRun->finished_at = now();
                        $importRun->save();
                    });
                    Log::info("Successfully recovered from constraint violation for import run #{$importRun->id}");
                } catch (\Exception $retryException) {
                    Log::error("Failed to recover from constraint violation: " . $retryException->getMessage());
                    // Mark as failed instead of completed to avoid infinite loops
                    // Use the same $finalStatus logic for consistency
                    $importRun->status = 'failed';
                    $importRun->finished_at = now();
                    $importRun->save();
                }
            }
            
            // CRITICAL: Verify actual results in WooCommerce before marking as complete
            // This ensures dashboard statistics reflect reality, not just job completion
            try {
                VerifyImportResultsJob::dispatch($this->importRunId)
                    ->delay(now()->addMinutes(2)); // Allow time for WooCommerce to index
                
                Log::info("Scheduled VerifyImportResultsJob for ImportRun #{$this->importRunId} to verify actual results");
            } catch (\Exception $e) {
                Log::error("Failed to schedule VerifyImportResultsJob for ImportRun #{$this->importRunId}: " . $e->getMessage());
            }

            // Schedule the ReconcileProductStatusJob to run after verification
            // This will attempt to publish any products left in draft status
            try {
                // Find the connection ID from the import run
                $feedWebsiteId = $importRun->feed_website_id;
                
                // Add a delay after verification to let the system stabilize
                ReconcileProductStatusJob::dispatch($this->importRunId, $feedWebsiteId)
                    ->delay(now()->addMinutes(3)); // Run after verification completes
                
                Log::info("Scheduled ReconcileProductStatusJob for ImportRun #{$this->importRunId} to run after verification");
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
