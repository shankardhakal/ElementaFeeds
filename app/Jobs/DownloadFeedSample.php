<?php

namespace App\Jobs;

use App\Models\Feed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;

class DownloadFeedSample implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $feedId;

    public function __construct(int $feedId)
    {
        $this->feedId = $feedId;
    }

    public function handle(): void
    {
        // Clear any old sample data before starting
        session()->forget(['connection_wizard_data.sample_headers', 'connection_wizard_data.sample_records', 'connection_wizard_data.sample_error']);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'feed_sample_');

        try {
            $feed = Feed::findOrFail($this->feedId);

            // Step 1: Download the feed content directly to the temporary local file.
            $response = Http::withOptions([
                'sink' => $tempFilePath,
                'verify' => false,
            ])->timeout(60)->get($feed->feed_url);

            if (!$response->successful()) {
                throw new \Exception("Failed to download feed sample. Server responded with status: " . $response->status());
            }

            // Step 2: Parse the now-local temporary file.
            $csv = Reader::createFromPath($tempFilePath, 'r');
            $csv->setDelimiter($feed->delimiter);
            $csv->setEnclosure($feed->enclosure);
            $csv->setHeaderOffset(0);

            $headers = $csv->getHeader();
            $records = collect($csv->getRecords())->take(100);

            session([
                'connection_wizard_data.sample_headers' => $headers,
                'connection_wizard_data.sample_records' => $records->values()->all(),
                'connection_wizard_data.sample_failed' => false,
            ]);

        } catch (\Exception $e) {
            $errorMessage = "Failed to process feed ID {$this->feedId}: " . $e->getMessage();
            Log::error($errorMessage);
            // Store the specific error message in the session for the UI.
            session([
                'connection_wizard_data.sample_failed' => true,
                'connection_wizard_data.sample_error' => $e->getMessage()
            ]);
        } finally {
            if (file_exists($tempFilePath)) {
                unlink($tempFilePath);
            }
        }
    }
}