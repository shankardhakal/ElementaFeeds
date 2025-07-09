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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * HandleImportFailureJob
 *
 * This job handles the cleanup and logging after an import run fails.
 *
 * Key Tasks:
 * - Updates the status of the import run to "failed".
 * - Logs the error details.
 * - Dispatches cleanup jobs to remove temporary files.
 */
class HandleImportFailureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $importRunId;
    protected string $errorMessage;

    /**
     * Create a new job instance.
     *
     * @param int $importRunId
     * @param string $errorMessage
     */
    public function __construct(int $importRunId, string $errorMessage)
    {
        $this->importRunId = $importRunId;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $importRun = ImportRun::find($this->importRunId);

        if (!$importRun) {
            Log::error("HandleImportFailureJob: Could not find ImportRun with ID {$this->importRunId}.");
            return;
        }

        // Find and cancel the batch to prevent further processing of chunks.
        if ($importRun->batch_id) {
            $batch = Bus::findBatch($importRun->batch_id);
            if ($batch && !$batch->cancelled()) {
                Log::info("Cancelling batch #{$batch->id} for import run #{$this->importRunId}.");
                $batch->cancel();
                // Purge any pending jobs for this batch (database queue)
                try {
                    DB::table('jobs')->where('batch_id', $batch->id)->delete();
                    Log::info("Purged pending jobs for batch #{$batch->id}.");
                } catch (\Throwable $e) {
                    Log::warning("Could not purge pending jobs for batch #{$batch->id}: " . $e->getMessage());
                }
            }
        }

        // Only update the status if it's not already in a failed state.
        // This prevents race conditions from multiple job failures.
        if ($importRun->status !== 'failed') {
            Log::error("Batch for ImportRun #{$this->importRunId} failed. Finalizing run as failed.", [
                'exception' => $this->errorMessage,
            ]);
            
            // Safely update status with constraint handling
            try {
                $importRun->status = 'failed';
                $importRun->finished_at = now();
                $importRun->log_messages = $this->errorMessage;
                $importRun->save();
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Clean up any conflicting stuck runs for this connection
                \App\Models\ImportRun::where('feed_website_id', $importRun->feed_website_id)
                    ->where('status', 'failed')
                    ->where('id', '!=', $importRun->id)
                    ->where('updated_at', '<', now()->subMinutes(30))
                    ->update([
                        'status' => 'expired',
                        'finished_at' => now(),
                        'log_messages' => 'Import run status changed to avoid constraint violation.'
                    ]);
                
                // Retry the status update
                $importRun->refresh();
                $importRun->status = 'failed';
                $importRun->finished_at = now();
                $importRun->log_messages = $this->errorMessage;
                $importRun->save();
            }
        }

        // Cleanup can still run, as it's based on the import run ID.
        $this->cleanupChunks($this->importRunId);
    }

    /**
     * Clean up the chunk directory for a given import run.
     *
     * @param int $importRunId
     * @return void
     */
    protected function cleanupChunks(int $importRunId): void
    {
        $chunkDirectory = storage_path("app/import_chunks/{$importRunId}");
        if (File::isDirectory($chunkDirectory)) {
            File::deleteDirectory($chunkDirectory);
            Log::info("Cleaned up chunk directory for failed ImportRun #{$importRunId}.");
        }
    }
}
