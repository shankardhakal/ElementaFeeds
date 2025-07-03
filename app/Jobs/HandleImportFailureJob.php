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
use Throwable;

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

        if ($importRun) {
            Log::error("Batch for ImportRun #{$this->importRunId} failed. Finalizing run as failed.", [
                'exception' => $this->errorMessage,
            ]);
            $importRun->status = 'failed';
            $importRun->finished_at = now();
            $importRun->log_messages = $this->errorMessage;
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
            Log::info("Cleaned up chunk directory for failed ImportRun #{$importRunId}.");
        }
    }
}
