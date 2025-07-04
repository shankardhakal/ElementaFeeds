<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use App\Models\ImportRun;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use Throwable;
use App\Jobs\CleanupImportRunChunksJob; // Add this line

class ChunkFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $importRunId;
    public int $feedWebsiteConnectionId;
    public string $sourceFilePath;
    protected const CHUNK_SIZE = 100; // For WooCommerce batch API

    /**
     * Create a new job instance.
     *
     * @param int $importRunId
     * @param int $feedWebsiteConnectionId
     * @param string $sourceFilePath
     */
    public function __construct(int $importRunId, int $feedWebsiteConnectionId, string $sourceFilePath)
    {
        $this->importRunId = $importRunId;
        $this->feedWebsiteConnectionId = $feedWebsiteConnectionId;
        $this->sourceFilePath = $sourceFilePath;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $importRun = ImportRun::findOrFail($this->importRunId);
        $connection = FeedWebsite::findOrFail($this->feedWebsiteConnectionId);

        Log::info("ChunkFeedJob started for import run #{$importRun->id}.");
        $importRun->update(['status' => 'chunking']);

        $chunkDirectory = storage_path("app/import_chunks/{$importRun->id}");

        try {
            // The source file is already downloaded by DownloadFeedJob.
            // Step 1: Process the downloaded file and get chunk file paths.
            $chunkFiles = $this->processCsv($connection, $this->sourceFilePath, $chunkDirectory);

            if (empty($chunkFiles)) {
                $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => 'No chunk files were created. The source feed might be empty or invalid.']);
                Log::error("No chunk files created for import run #{$importRun->id}.");
                CleanupImportRunChunksJob::dispatch($importRun->id); // Use the job
                return;
            }

            // Step 2: Prepare and dispatch the batch of ProcessChunkJobs.
            $jobs = collect($chunkFiles)->map(function ($chunkFilePath) {
                return new ProcessChunkJob($this->importRunId, $this->feedWebsiteConnectionId, $chunkFilePath);
            })->all();

            $batch = Bus::batch($jobs)->then(function (Batch $batch) use ($importRun) {
                // All jobs completed successfully...
                Log::info("Batch {$batch->id} completed successfully for import run #{$importRun->id}. Dispatching completion job.");
                HandleImportCompletionJob::dispatch($importRun->id);
            })->catch(function (Batch $batch, Throwable $e) use ($importRun) {
                // A job within the batch failed...
                Log::error("Batch {$batch->id} failed for import run #{$importRun->id}. Dispatching failure job. Error: {$e->getMessage()}");
                HandleImportFailureJob::dispatch($importRun->id, $e->getMessage());
            })->finally(function (Batch $batch) use ($importRun) {
                // The batch has finished executing, successful or not. Clean up chunks.
                Log::info("Batch {$batch->id} finished for import run #{$importRun->id}. Cleaning up chunk files.");
                CleanupImportRunChunksJob::dispatch($importRun->id); // Use the job
            })->name("Import Run #{$importRun->id} for Connection #{$connection->id}")->dispatch();

            $importRun->update(['batch_id' => $batch->id, 'status' => 'processing']);
            Log::info("Dispatched ProcessChunkJob batch {$batch->id} for import run #{$importRun->id}.");

        } catch (\Exception $e) {
            Log::error("ChunkFeedJob failed for import run #{$importRun->id}: " . $e->getMessage());
            $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => $e->getMessage()]);
            CleanupImportRunChunksJob::dispatch($importRun->id); // Use the job
        }
    }

    protected function processCsv(FeedWebsite $connection, string $sourceFilePath, string $chunkDirectory): array
    {
        $feed = $connection->feed;
        $chunkFiles = [];

        if (!File::exists($sourceFilePath)) {
            throw new \Exception("Source file does not exist at path: {$sourceFilePath}");
        }

        $csv = Reader::createFromPath($sourceFilePath, 'r');
        $csv->setDelimiter($feed->delimiter);
        $csv->setEnclosure($feed->enclosure);
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();
        $chunkIndex = 1;

        foreach (collect($records)->chunk(self::CHUNK_SIZE) as $chunk) {
            if ($chunk->isEmpty()) continue;
            $chunkFileName = "chunk_{$chunkIndex}.json";
            $chunkFilePath = "{$chunkDirectory}/{$chunkFileName}";
            File::put($chunkFilePath, $chunk->values()->toJson()); // Use values() to reset keys for valid JSON array
            $chunkFiles[] = $chunkFilePath;
            Log::info("Prepared chunk file: {$chunkFileName} for ImportRun #{$this->importRunId}");
            $chunkIndex++;
        }
        return $chunkFiles;
    }
}