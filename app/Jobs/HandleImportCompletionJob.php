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
            $importRun->status = 'completed';
            $importRun->finished_at = now();
            $importRun->save();
        }

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
            Log::info("Cleaned up chunk directory for ImportRun #{$importRunId}.");
        }
    }
}
