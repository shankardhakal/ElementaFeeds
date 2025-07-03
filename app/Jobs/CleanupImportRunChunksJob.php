<?php

namespace App\\Jobs;

use Illuminate\\Bus\\Queueable;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Foundation\\Bus\\Dispatchable;
use Illuminate\\Queue\\InteractsWithQueue;
use Illuminate\\Queue\\SerializesModels;
use Illuminate\\Support\\Facades\\File;
use Illuminate\Support\\Facades\\Log;

class CleanupImportRunChunksJob implements ShouldQueue
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
        $chunkDirectory = storage_path("app/import_chunks/{$this->importRunId}");
        if (File::isDirectory($chunkDirectory)) {
            File::deleteDirectory($chunkDirectory);
            Log::info("Cleaned up chunk directory for ImportRun #{$this->importRunId}.");
        }
    }
}
