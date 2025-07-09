<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use App\Models\ImportRun;
use App\Services\FilterService;
use League\Csv\Reader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DownloadFeedJob
 *
 * This job is responsible for downloading the feed file from a remote source.
 *
 * Key Tasks:
 * - Fetches the feed file using the URL provided in the feed configuration.
 * - Saves the file locally for further processing.
 */
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

            // Apply mapping-wizard filters on a sample of records before chunking
            $filterService = new FilterService();
            $hasValid = false;
            try {
                // Use SplFileObject to stream sample rows
                $stream = new \SplFileObject($sourceFilePath, 'r');
                $stream->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
                $stream->setCsvControl($feed->delimiter, $feed->enclosure);
                // Read header row
                $headers = $stream->fgetcsv();
                // Sample next 10 rows
                for ($i = 0; $i < 10 && !$stream->eof(); $i++) {
                    $row = $stream->fgetcsv();
                    if (!$row || count($row) === 0) {
                        continue;
                    }
                    $assoc = array_combine($headers, $row);
                    if ($filterService->passes($assoc, $connection->filtering_rules)) {
                        $hasValid = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('DownloadFeedJob filter check failed: ' . $e->getMessage());
                $hasValid = true; // fallback to proceed
            }
            if (! $hasValid) {
                Log::info("DownloadFeedJob: No records pass filters; skipping chunking for import run #{$importRun->id}.");
                // Safely update status with constraint handling
                try {
                    $importRun->update(['status' => 'completed']);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Clean up any conflicting stuck runs for this connection
                    $connection = \App\Models\FeedWebsite::findOrFail($this->feedWebsiteConnectionId);
                    \App\Models\ImportRun::where('feed_website_id', $connection->id)
                        ->where('status', 'completed')
                        ->where('id', '!=', $importRun->id)
                        ->where('updated_at', '<', now()->subMinutes(30))
                        ->update([
                            'status' => 'failed',
                            'finished_at' => now(),
                            'log_messages' => 'Import run interrupted by newer import.'
                        ]);
                    
                    // Retry the status update
                    $importRun->refresh();
                    $importRun->update(['status' => 'completed']);
                }
                File::deleteDirectory($chunkDirectory);
                return;
            }
            // On success, dispatch the next job in the pipeline.
            ChunkFeedJob::dispatch($this->importRunId, $this->feedWebsiteConnectionId, $sourceFilePath);

        } catch (\Exception $e) {
            Log::error("DownloadFeedJob failed for import run #{$importRun->id}: " . $e->getMessage());
            // Safely update status with constraint handling
            try {
                $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => $e->getMessage()]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $constraintError) {
                // Clean up any conflicting stuck runs for this connection
                $connection = \App\Models\FeedWebsite::findOrFail($this->feedWebsiteConnectionId);
                \App\Models\ImportRun::where('feed_website_id', $connection->id)
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
                $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => $e->getMessage()]);
            }
            
            // Clean up the directory if the download fails.
            if (File::isDirectory($chunkDirectory)) {
                File::deleteDirectory($chunkDirectory);
                Log::info("Cleaned up chunk directory for failed ImportRun #{$importRun->id}.");
            }
        }
    }
}
