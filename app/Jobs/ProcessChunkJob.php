<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\FilterService;
use App\Services\TransformationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @param \App\Models\ImportRun $importRun The parent import run record.
     * @param string $chunkFileName The name of the specific chunk file to process.
     */
    public function __construct(
        protected ImportRun $importRun,
        protected string $chunkFileName
    ) {}

    /**
     * Execute the job.
     *
     * @param \App\Services\FilterService $filterService
     * @param \App\Services\TransformationService $transformationService
     * @return void
     */
    public function handle(FilterService $filterService, TransformationService $transformationService): void
    {
        $chunkPath = Storage::disk('local')->path("imports/{$this->importRun->id}/{$this->chunkFileName}");
        Log::info("Processing chunk: {$this->chunkFileName} for import run #{$this->importRun->id}");

        if (!File::exists($chunkPath)) {
            Log::error("Chunk file not found: {$chunkPath}");
            $this->fail("Chunk file not found: {$chunkPath}");
            return;
        }

        $records = json_decode(File::get($chunkPath), true);
        $connectionSettings = $this->importRun->feedWebsite;

        foreach ($records as $record) {
            // Step 1: Apply Filtering Rules
            if (!$filterService->passes($record, $connectionSettings->filtering_rules)) {
                continue;
            }

            // Step 2: Apply Transformations (Field & Attribute Mapping)
            $transformedProduct = $transformationService->transform(
                $record,
                $connectionSettings->field_mappings,
                $connectionSettings->category_mappings,
                $connectionSettings->attribute_mappings 
            );

            // This prevents serialization errors.
            SyndicateProductJob::dispatch($this->importRun->id, $transformedProduct);
        }

        // The chunk has been processed and its individual product jobs have been dispatched.
        File::delete($chunkPath);
        
        Log::info("Finished processing and dispatched jobs for chunk: {$this->chunkFileName}");
    }
}