<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DownloadFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $importRunId;
    public int $feedWebsiteConnectionId;

    /**
     * Create a new job instance.
     *
     * @param int $importRunId
     * @param int $feedWebsiteConnectionId
     */
    public function __construct(int $importRunId, int $feedWebsiteConnectionId)
    {
        $this->importRunId = $importRunId;
        $this->feedWebsiteConnectionId = $feedWebsiteConnectionId;
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
        $feed = $connection->feed;

        Log::info("DownloadFeedJob started for import run #{$importRun->id}.");
        $importRun->update(['status' => 'downloading']);

        $chunkDirectory = storage_path("app/import_chunks/{$importRun->id}");
        $sourceFilePath = $chunkDirectory . '/source.dat';

        try {
            // Ensure the chunk directory exists.
            if (!File::isDirectory($chunkDirectory)) {
                File::makeDirectory($chunkDirectory, 0755, true);
            }

            // Download the file.
            $response = Http::withOptions(['sink' => $sourceFilePath, 'verify' => false])->get($feed->feed_url);

            if (!$response->successful()) {
                throw new \Exception("Failed to download feed from {$feed->feed_url}. Status: " . $response->status());
            }

            Log::info("Successfully downloaded source file for import run #{$importRun->id} to {$sourceFilePath}.");
            $importRun->update(['status' => 'downloaded']);

            // On success, dispatch the next job in the pipeline.
            ChunkFeedJob::dispatch($this->importRunId, $this->feedWebsiteConnectionId, $sourceFilePath);

        } catch (\Exception $e) {
            Log::error("DownloadFeedJob failed for import run #{$importRun->id}: " . $e->getMessage());
            $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => $e->getMessage()]);
            
            // Clean up the directory if the download fails.
            if (File::isDirectory($chunkDirectory)) {
                File::deleteDirectory($chunkDirectory);
                Log::info("Cleaned up chunk directory for failed ImportRun #{$importRun->id}.");
            }
        }
    }
}
