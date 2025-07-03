<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Models\ImportRun;
use Throwable;

class FinalizeImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $importRunId;

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
        
        if (!$importRun) {
            Log::error("FinalizeImportJob could not find ImportRun #{$this->importRunId}");
            return;
        }

        // Check if the import run has already been completed or failed
        if (in_array($importRun->status, ['completed', 'failed'])) {
            Log::info("ImportRun #{$this->importRunId} already finalized with status: {$importRun->status}");
            return;
        }
        
        // Check the batch status using the batch ID stored in the import run
        if (!$importRun->batch_id) {
            Log::error("ImportRun #{$this->importRunId} does not have a batch_id. Cannot check batch status.");
            HandleImportFailureJob::dispatch($this->importRunId, "Critical error: Batch ID missing during finalization.");
            return;
        }
        
        try {
            $batch = Bus::findBatch($importRun->batch_id);
            
            if (!$batch) {
                Log::error("Batch {$importRun->batch_id} for ImportRun #{$this->importRunId} not found.");
                HandleImportFailureJob::dispatch($this->importRunId, "Critical error: Batch not found during finalization.");
                return;
            }
            
            // Check if all jobs are finished
            if (!$batch->finished()) {
                // If the batch is still processing, reschedule this job
                Log::info("Batch {$batch->id} for ImportRun #{$this->importRunId} is still processing. Rescheduling finalization check.");
                FinalizeImportJob::dispatch($this->importRunId)->delay(now()->addMinutes(2));
                return;
            }
            
            // Check if any jobs in the batch failed
            if ($batch->failedJobs > 0 || $batch->failed()) {
                Log::warning("Batch {$batch->id} for ImportRun #{$this->importRunId} had {$batch->failedJobs} failed jobs.");
                $exceptionMessage = "Batch processing had one or more failures. Check the failed_jobs table for details.";
                HandleImportFailureJob::dispatch($this->importRunId, $exceptionMessage);
            } else {
                Log::info("Batch {$batch->id} for ImportRun #{$this->importRunId} completed successfully.");
                HandleImportCompletionJob::dispatch($this->importRunId);
            }
            
            // Clean up the chunks directory
            $this->cleanupChunks($this->importRunId);
            
        } catch (\Exception $e) {
            Log::error("Error checking batch status for ImportRun #{$this->importRunId}: " . $e->getMessage());
            HandleImportFailureJob::dispatch($this->importRunId, "Error during batch finalization: " . $e->getMessage());
        }
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
            Log::info("Cleaned up chunk directory for ImportRun #{$importRunId}.");
        }
    }
}
