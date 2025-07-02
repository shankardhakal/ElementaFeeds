<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\Api\WooCommerceApiClient;
use App\Services\Api\WordPressApiClient;
use App\Services\SyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyndicateProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Use a classic constructor and public properties for maximum queue compatibility.
    public int $importRunId;
    public array $productData;

    public function __construct(int $importRunId, array $productData)
    {
        $this->importRunId = $importRunId;
        $this->productData = $productData;
    }

    public function handle(SyndicationService $syndicationService): void
    {
        try {
            $importRun = ImportRun::with('feedWebsite.website')->findOrFail($this->importRunId);
            $connection = $importRun->feedWebsite;
            $website = $connection->website;

            $apiClient = ($website->platform === 'woocommerce')
                ? new WooCommerceApiClient($website)
                : new WordPressApiClient($website);

            $syndicationService->syndicate($this->productData, $connection, $apiClient);

        } catch (\Throwable $e) {
            Log::critical("SyndicateProductJob failed.", [
                'import_run_id' => $this->importRunId,
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'product_data' => $this->productData,
            ]);
            throw $e;
        }
    }
}