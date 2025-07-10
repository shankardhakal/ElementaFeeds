<?php

namespace Tests\Unit;

use App\Jobs\ProcessStaleProductCleanup;
use App\Models\Feed;
use App\Models\FeedWebsite;
use App\Models\Network;
use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ProcessStaleProductCleanupJobTest extends TestCase
{
    use RefreshDatabase;

    protected $mockApiClient;
    protected $connection;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce',
            'url' => 'https://test.example.com',
            'woocommerce_credentials' => json_encode([
                'key' => 'test_key',
                'secret' => 'test_secret'
            ])
        ]);

        $this->connection = FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'set_stock_zero',
                'stale_days' => 30
            ]
        ]);

        // Mock the API client
        $this->mockApiClient = Mockery::mock(WooCommerceApiClient::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_find_and_process_stale_products_with_set_stock_zero_action()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;
        $staleProducts = [
            ['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1', 'last_seen' => $cutoffTimestamp - 3600],
            ['id' => 2, 'sku' => 'SKU002', 'name' => 'Product 2', 'last_seen' => $cutoffTimestamp - 7200],
        ];

        // Mock API client methods
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn($staleProducts);

        $this->mockApiClient
            ->shouldReceive('batchProducts')
            ->with([
                'update' => [
                    [
                        'id' => 1,
                        'stock_status' => 'outofstock',
                        'manage_stock' => true,
                        'stock_quantity' => 0
                    ],
                    [
                        'id' => 2,
                        'stock_status' => 'outofstock',
                        'manage_stock' => true,
                        'stock_quantity' => 0
                    ]
                ]
            ])
            ->once()
            ->andReturn([
                'update' => [
                    ['id' => 1, 'sku' => 'SKU001'],
                    ['id' => 2, 'sku' => 'SKU002']
                ]
            ]);

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'set_stock_zero');
        $job->handle();

        // Verify the job completed without exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_can_find_and_process_stale_products_with_delete_action()
    {
        $cutoffTimestamp = now()->subDays(7)->timestamp;
        $staleProducts = [
            ['id' => 3, 'sku' => 'SKU003', 'name' => 'Product 3', 'last_seen' => $cutoffTimestamp - 3600],
        ];

        // Mock API client methods
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn($staleProducts);

        $this->mockApiClient
            ->shouldReceive('batchProducts')
            ->with(['delete' => [3]])
            ->once()
            ->andReturn([
                'delete' => [
                    ['id' => 3, 'sku' => 'SKU003']
                ]
            ]);

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'delete');
        $job->handle();

        // Verify the job completed without exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_no_stale_products_gracefully()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock API client to return no stale products
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn([]);

        // Should not call batchProducts if no stale products found
        $this->mockApiClient
            ->shouldNotReceive('batchProducts');

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'set_stock_zero');
        $job->handle();

        // Verify the job completed without exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_processes_products_in_batches()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;
        
        // Create a large number of stale products (75 products to test batching at batch size 50)
        $staleProducts = [];
        for ($i = 1; $i <= 75; $i++) {
            $staleProducts[] = [
                'id' => $i,
                'sku' => "SKU{$i}",
                'name' => "Product {$i}",
                'last_seen' => $cutoffTimestamp - 3600
            ];
        }

        // Mock API client methods
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn($staleProducts);

        // Should be called twice (batch 1: 50 products, batch 2: 25 products)
        $this->mockApiClient
            ->shouldReceive('batchProducts')
            ->twice()
            ->andReturn(['update' => []]);

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'set_stock_zero');
        $job->handle();

        // Verify the job completed without exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock API client to throw an exception
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andThrow(new \Exception('API connection failed'));

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'set_stock_zero');

        // Expect the job to throw an exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API connection failed');

        $job->handle();
    }

    /** @test */
    public function it_handles_batch_processing_errors()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;
        $staleProducts = [
            ['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1', 'last_seen' => $cutoffTimestamp - 3600],
        ];

        // Mock API client methods
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn($staleProducts);

        // Mock batchProducts to return an error response
        $this->mockApiClient
            ->shouldReceive('batchProducts')
            ->once()
            ->andReturn([
                'update' => [
                    [
                        'id' => 1,
                        'error' => [
                            'code' => 'woocommerce_rest_product_invalid_id',
                            'message' => 'Product does not exist'
                        ]
                    ]
                ]
            ]);

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'set_stock_zero');
        $job->handle();

        // Verify the job completed without exceptions (errors are handled gracefully)
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_unknown_actions_gracefully()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;
        $staleProducts = [
            ['id' => 1, 'sku' => 'SKU001', 'name' => 'Product 1', 'last_seen' => $cutoffTimestamp - 3600],
        ];

        // Mock API client methods
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn($staleProducts);

        // Should not call batchProducts for unknown action
        $this->mockApiClient
            ->shouldNotReceive('batchProducts');

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job with unknown action
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'unknown_action');
        $job->handle();

        // Verify the job completed without exceptions
        $this->assertTrue(true);
    }

    /** @test */
    public function it_fails_when_connection_not_found()
    {
        $nonExistentConnectionId = 99999;
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Create the job with non-existent connection
        $job = new ProcessStaleProductCleanup($nonExistentConnectionId, $cutoffTimestamp, 'set_stock_zero');

        // Expect the job to throw a ModelNotFoundException
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $job->handle();
    }

    /** @test */
    public function it_logs_appropriate_messages()
    {
        Log::shouldReceive('info')->atLeast()->once();

        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock API client to return no stale products
        $this->mockApiClient
            ->shouldReceive('findStaleProducts')
            ->with($this->connection->id, $cutoffTimestamp)
            ->once()
            ->andReturn([]);

        // Mock the constructor to return our mock
        $this->app->bind(WooCommerceApiClient::class, function () {
            return $this->mockApiClient;
        });

        // Create and handle the job
        $job = new ProcessStaleProductCleanup($this->connection->id, $cutoffTimestamp, 'set_stock_zero');
        $job->handle();

        // Verify the job completed without exceptions
        $this->assertTrue(true);
    }
}
