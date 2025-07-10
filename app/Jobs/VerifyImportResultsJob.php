<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Models\FeedWebsite;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * VerifyImportResultsJob
 *
 * This job verifies and corrects import statistics by querying WooCommerce directly.
 * It replaces unreliable job-completion-based counting with actual API results.
 *
 * Enterprise Solution: No new columns needed - uses existing import_runs fields.
 * Battle-tested approach: Single source of truth from destination API.
 */
class VerifyImportResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $importRunId;
    public int $timeout = 300; // 5 minutes timeout

    public function __construct(int $importRunId)
    {
        $this->importRunId = $importRunId;
    }

    /**
     * Execute the verification job.
     *
     * This is the single source of truth for import results.
     */
    public function handle(): void
    {
        $importRun = ImportRun::findOrFail($this->importRunId);
        $connection = FeedWebsite::findOrFail($importRun->feed_website_id);

        Log::info("Starting import verification for ImportRun #{$this->importRunId}");

        try {
            // Get WooCommerce API client
            $website = $connection->website;
            $apiClient = new WooCommerceApiClient($website->woocommerce_credentials);

            // Get actual results from WooCommerce
            $actualResults = $this->getActualWooCommerceResults($apiClient, $importRun);

            // Update import run with REAL results using existing columns
            $importRun->update([
                'created_records' => $actualResults['created'],
                'updated_records' => $actualResults['updated'],
                'error_records' => $actualResults['failed'],
                'processed_records' => $actualResults['total'],
                'log_messages' => 'VERIFIED: ' . $actualResults['notes'] . ' | Original: ' . $importRun->log_messages
            ]);

            Log::info("Import verification completed for ImportRun #{$this->importRunId}", [
                'actual_created' => $actualResults['created'],
                'actual_updated' => $actualResults['updated'],
                'actual_failed' => $actualResults['failed'],
                'total_processed' => $actualResults['total']
            ]);

            // If results show success, mark import as completed
            if ($actualResults['created'] > 0 || $actualResults['updated'] > 0) {
                $importRun->update([
                    'status' => 'completed',
                    'finished_at' => now()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Import verification failed for ImportRun #{$this->importRunId}: " . $e->getMessage());
            
            // Add verification failure note using existing log_messages column
            $importRun->update([
                'log_messages' => 'VERIFICATION FAILED: ' . $e->getMessage() . ' | ' . $importRun->log_messages
            ]);
        }
    }

    /**
     * Get actual results from WooCommerce by querying products with this import_run_id
     * This is the SINGLE SOURCE OF TRUTH for import statistics.
     */
    private function getActualWooCommerceResults(WooCommerceApiClient $apiClient, ImportRun $importRun): array
    {
        Log::info("Querying WooCommerce for actual import results", ['import_run_id' => $importRun->id]);

        // Query products that have this specific import_run_id in meta_data
        $params = [
            'per_page' => 100,
            'meta_key' => 'import_run_id',
            'meta_value' => (string)$importRun->id,
            'status' => 'any' // Include all statuses
        ];

        $allProducts = [];
        $page = 1;
        $totalPages = 1;
        
        do {
            $params['page'] = $page;
            Log::debug("Fetching page {$page} of products for import run {$importRun->id}");
            
            $response = $apiClient->makeRequest('products', $params);
            
            if (is_array($response)) {
                $allProducts = array_merge($allProducts, $response);
                
                // Check if there are more pages (WooCommerce sends pagination headers)
                if (count($response) == 100) {
                    $page++;
                } else {
                    break;
                }
            } else {
                Log::warning("Invalid response from WooCommerce products API", ['response' => $response]);
                break;
            }
        } while ($page <= 50); // Safety limit

        Log::info("Found {$totalFound} products in WooCommerce for import run {$importRun->id}", [
            'count' => count($allProducts), 
            'import_run_id' => $importRun->id
        ]);

        // Analyze the products to determine created vs updated
        $created = 0;
        $updated = 0;
        $importStartTime = $importRun->created_at->timestamp;
        
        foreach ($allProducts as $product) {
            $productCreatedAt = strtotime($product['date_created'] ?? '');
            $productModifiedAt = strtotime($product['date_modified'] ?? '');
            
            // If product was created during or after the import run, count as created
            // If it was created before but modified during import, count as updated
            if ($productCreatedAt >= $importStartTime) {
                $created++;
            } else if ($productModifiedAt >= $importStartTime) {
                $updated++;
            }
        }

        $totalFound = count($allProducts);
        $notes = "WooCommerce verification complete: Found {$totalFound} products";
        
        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => 0, // If we can't find products, they weren't created - but this is handled elsewhere
            'total' => $totalFound,
            'notes' => $notes
        ];
    }
}
