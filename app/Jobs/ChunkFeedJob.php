<?php

namespace App\Jobs;

use App\Models\FeedWebsite;
use App\Models\ImportRun;
use App\Services\CategoryNormalizer;
use App\Services\FilterService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use Throwable;
use App\Jobs\CleanupImportRunChunksJob; // Add this line

/**
 * ChunkFeedJob
 *
 * This job is responsible for splitting a large CSV feed file into smaller JSON chunks for batch processing.
 *
 * Key Tasks:
 * - Reads the CSV file using League\Csv\Reader.
 * - Splits the records into chunks of a predefined size (CHUNK_SIZE).
 * - Saves each chunk as a JSON file in a designated directory.
 * - Dispatches ProcessChunkJob for each chunk to process the data.
 */
class ChunkFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $importRunId;
    public int $feedWebsiteConnectionId;
    public string $sourceFilePath;
    protected const CHUNK_SIZE = 25; // Reduced from 100 to 25 for better reliability
    protected const MIN_CHUNK_SIZE = 10; // Minimum chunk size 
    protected const MAX_CHUNK_SIZE = 50; // Maximum chunk size (reduced from 100)

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
        
        // Safely update status with constraint handling
        try {
            $importRun->update(['status' => 'chunking']);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Clean up any conflicting stuck runs for this connection
            ImportRun::where('feed_website_id', $connection->id)
                ->where('status', 'chunking')
                ->where('id', '!=', $importRun->id)
                ->where('updated_at', '<', now()->subMinutes(30))
                ->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'log_messages' => 'Import run interrupted by newer import.'
                ]);
            
            // Retry the status update
            $importRun->refresh();
            $importRun->update(['status' => 'chunking']);
        }

        $chunkDirectory = storage_path("app/import_chunks/{$importRun->id}");

        try {
            // The source file is already downloaded by DownloadFeedJob.
            // Step 1: Process the downloaded file and get chunk file paths.
            $chunkFiles = $this->processCsv($connection, $this->sourceFilePath, $chunkDirectory);

            if (empty($chunkFiles)) {
                // Safely update status with constraint handling
                try {
                    $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => 'No chunk files were created. The source feed might be empty or invalid.']);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Clean up any conflicting stuck runs for this connection
                    ImportRun::where('feed_website_id', $connection->id)
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
                    $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => 'No chunk files were created. The source feed might be empty or invalid.']);
                }
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

            // Safely update status with constraint handling
            try {
                $importRun->update(['batch_id' => $batch->id, 'status' => 'processing']);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Clean up any conflicting stuck runs for this connection
                ImportRun::where('feed_website_id', $connection->id)
                    ->where('status', 'processing')
                    ->where('id', '!=', $importRun->id)
                    ->where('updated_at', '<', now()->subMinutes(30))
                    ->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                        'log_messages' => 'Import run interrupted by newer import.'
                    ]);
                
                // Retry the status update
                $importRun->refresh();
                $importRun->update(['batch_id' => $batch->id, 'status' => 'processing']);
            }
            Log::info("Dispatched ProcessChunkJob batch {$batch->id} for import run #{$importRun->id}.");

        } catch (\Exception $e) {
            Log::error("ChunkFeedJob failed for import run #{$importRun->id}: " . $e->getMessage());
            // Safely update status with constraint handling
            try {
                $importRun->update(['status' => 'failed', 'finished_at' => now(), 'log_messages' => $e->getMessage()]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $constraintError) {
                // Clean up any conflicting stuck runs for this connection
                ImportRun::where('feed_website_id', $connection->id)
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
            CleanupImportRunChunksJob::dispatch($importRun->id); // Use the job
        }
    }

    /**
     * processCsv
     *
     * Processes the CSV file and splits it into smaller chunks.
     *
     * @param FeedWebsite $connection The feed website connection.
     * @param string $sourceFilePath The path to the source CSV file.
     * @param string $chunkDirectory The directory to save chunk files.
     * @return array List of chunk file paths.
     *
     * Key Tasks:
     * - Reads the CSV file using League\Csv\Reader.
     * - Splits the records into chunks of CHUNK_SIZE.
     * - Saves each chunk as a JSON file.
     */
    protected function processCsv(FeedWebsite $connection, string $sourceFilePath, string $chunkDirectory): array
    {
        $feed = $connection->feed;
        $chunkFiles = [];

        if (!File::exists($sourceFilePath)) {
            throw new \Exception("Source file does not exist at path: {$sourceFilePath}");
        }

        // Get dynamic chunk size based on website performance
        $dynamicChunkSize = $this->getRecommendedChunkSize($connection->website->id);
        Log::info("Using dynamic chunk size: {$dynamicChunkSize} for ImportRun #{$this->importRunId}");

        // Eagerly load mappings and rules to avoid repeated access inside the loop.
        $rawCategoryMappings = $connection->category_mappings ?? [];
        
        // Transform category mappings from wizard format [['source' => '...', 'dest' => '...']] 
        // to normalizer format ['source' => 'dest_id']
        $categoryMap = [];
        foreach ($rawCategoryMappings as $mapping) {
            if (isset($mapping['source']) && isset($mapping['dest']) && !empty($mapping['dest'])) {
                $categoryMap[$mapping['source']] = (int) $mapping['dest'];
            }
        }
        
        $filteringRules = $connection->filtering_rules;
        $categorySourceField = $connection->category_source_field;
        $userDelimiter = $connection->category_delimiter;

        if (empty($categorySourceField)) {
            // If the admin hasn't configured the category source field, we can't proceed with mapping.
            // We can still import products, but they won't be categorized.
            Log::warning("Category source field is not configured for connection #{$connection->id}. Products will be imported without category mapping.");
        }

        if (empty($userDelimiter)) {
            Log::warning("Category delimiter is not configured for connection #{$connection->id}. The system will attempt to find a suitable delimiter automatically.");
        }

        $csv = Reader::createFromPath($sourceFilePath, 'r');
        $csv->setDelimiter($feed->delimiter);
        $csv->setEnclosure($feed->enclosure);
        $csv->setHeaderOffset(0);

        $records = $csv->getRecords();
        $filterService = new \App\Services\FilterService();
        
        // Debug: Log filtering configuration
        $mappedCategoriesCount = count($categoryMap);
        Log::info("ChunkFeedJob Debug - Connection {$connection->id}", [
            'category_source_field' => $categorySourceField,
            'category_delimiter' => $userDelimiter,
            'mapped_categories_count' => $mappedCategoriesCount,
            'raw_mappings_count' => count($rawCategoryMappings),
            'filtering_rules_count' => count($filteringRules ?? []),
            'sample_category_mappings' => array_slice($categoryMap, 0, 5),
            'sample_raw_mappings' => array_slice($rawCategoryMappings, 0, 3)
        ]);
        
        $chunkIndex = 1;
        $buffer = [];
        $totalProcessed = 0;
        $categoryFilterCount = 0;
        $ruleFilterCount = 0;
        
        foreach ($records as $record) {
            $totalProcessed++;
            
            // Check category mapping first
            if (empty($categorySourceField) || !isset($record[$categorySourceField])) {
                $categoryFilterCount++;
                continue; // Skip to the next record
            }

            $rawCategory = $record[$categorySourceField];
            $mappedCategoryId = \App\Services\CategoryNormalizer::normalize($rawCategory, $userDelimiter, $categoryMap);

            // If the category normalizer doesn't find a valid, mapped category, skip the product.
            if ($mappedCategoryId === null) {
                $categoryFilterCount++;
                continue;
            }

            // Apply mapping-wizard filter on-the-fly
            if (!$filterService->passes($record, $filteringRules)) {
                $ruleFilterCount++;
                continue;
            }

            $buffer[] = $record;
            if (count($buffer) >= $dynamicChunkSize) {
                $chunkFileName = "chunk_{$chunkIndex}.json";
                $chunkFilePath = "{$chunkDirectory}/{$chunkFileName}";
                File::put($chunkFilePath, json_encode(array_values($buffer)));
                $chunkFiles[] = $chunkFilePath;
                Log::info("Prepared chunk file: {$chunkFileName} for ImportRun #{$this->importRunId}");
                $buffer = [];
                $chunkIndex++;
            }
        }
        
        // Write any remaining records
        if (! empty($buffer)) {
            $chunkFileName = "chunk_{$chunkIndex}.json";
            $chunkFilePath = "{$chunkDirectory}/{$chunkFileName}";
            File::put($chunkFilePath, json_encode(array_values($buffer)));
            $chunkFiles[] = $chunkFilePath;
            Log::info("Prepared chunk file: {$chunkFileName} for ImportRun #{$this->importRunId}");
        }

        $totalFiltered = $categoryFilterCount + $ruleFilterCount;
        
        // Debug: Log filtering results
        Log::info("ChunkFeedJob Debug - Filtering Results", [
            'connection_id' => $connection->id,
            'total_processed' => $totalProcessed,
            'total_filtered_out' => $totalFiltered,
            'category_filter_count' => $categoryFilterCount,
            'rule_filter_count' => $ruleFilterCount,
            'chunks_created' => count($chunkFiles),
            'records_passing_filters' => $totalProcessed - $totalFiltered
        ]);

        return $chunkFiles;
    }

    /**
     * Get recommended chunk size based on website performance
     */
    protected function getRecommendedChunkSize(int $websiteId): int
    {
        $cacheKey = "recommended_chunk_size:website:{$websiteId}";
        $recommendedSize = Cache::get($cacheKey, self::CHUNK_SIZE);
        
        // Ensure it's within bounds
        $recommendedSize = max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $recommendedSize));
        
        Log::info("Using chunk size {$recommendedSize} for website #{$websiteId}");
        
        return $recommendedSize;
    }

    /**
     * Reduce recommended chunk size for a website when server errors occur
     */
    public static function reduceRecommendedChunkSize(int $websiteId): void
    {
        $cacheKey = "recommended_chunk_size:website:{$websiteId}";
        $currentSize = Cache::get($cacheKey, self::CHUNK_SIZE);
        
        // Reduce by 30% but never below minimum
        $newSize = max(self::MIN_CHUNK_SIZE, (int)($currentSize * 0.7));
        
        if ($newSize < $currentSize) {
            Cache::put($cacheKey, $newSize, 60 * 60 * 24); // Store for 24 hours
            Log::warning("Reduced recommended chunk size for website #{$websiteId} from {$currentSize} to {$newSize} due to server errors");
        }
    }

}