<?php

namespace App\Jobs;

use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class DownloadAndChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ImportRun $importRun;
    protected const CHUNK_SIZE = 1000;

    public function __construct(ImportRun $importRun)
    {
        $this->importRun = $importRun;
    }

    public function handle(): void
    {
        Log::info("DownloadAndChunkJob started for import run #{$this->importRun->id}.");

        $feed = $this->importRun->feedWebsite->feed;
        $importDirectory = "imports/{$this->importRun->id}";
        Storage::disk('local')->makeDirectory($importDirectory);
        $localPath = Storage::disk('local')->path($importDirectory);
        $sourceFilePath = $localPath . '/source.dat';

        try {
            // Step 1: Download the entire file to a temporary local path first.
            $response = Http::withOptions([
                'sink' => $sourceFilePath, // Save directly to the file
                'verify' => false,
            ])->get($feed->feed_url);

            if (!$response->successful()) {
                throw new \Exception("Failed to download feed. Status: " . $response->status());
            }
            Log::info("Successfully downloaded source file for import run #{$this->importRun->id}.");

            // Step 2: Now, parse the local file, which is always seekable.
            $this->processCsv($sourceFilePath);
            
        } catch (\Exception $e) {
            Log::error("DownloadAndChunkJob failed for import run #{$this->importRun->id}: " . $e->getMessage());
            $this->importRun->update(['status' => 'failed', 'log_messages' => $e->getMessage()]);
            // Clean up directory on failure
            File::deleteDirectory($localPath);
        }
    }

    /**
     * This method now takes a file path instead of a directory path
     * and reads from the already downloaded local file.
     */
    protected function processCsv(string $sourceFilePath): void
    {
        $feed = $this->importRun->feedWebsite->feed;
        $chunkBasePath = Storage::disk('local')->path("imports/{$this->importRun->id}");

        // createFromPath is reliable for local files.
        $csv = Reader::createFromPath($sourceFilePath, 'r');
        $csv->setDelimiter($feed->delimiter);
        $csv->setEnclosure($feed->enclosure);
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();
        $chunkIndex = 1;

        foreach (collect($records)->chunk(self::CHUNK_SIZE) as $chunk) {
            $chunkFileName = "chunk_{$chunkIndex}.json";
            ProcessChunkJob::dispatch($this->importRun, $chunkFileName);
            File::put("{$chunkBasePath}/{$chunkFileName}", $chunk->toJson());
            Log::info("Dispatched ProcessChunkJob for chunk: {$chunkFileName}");
            $chunkIndex++;
        }
    }
}