<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\Api\WooCommerceApiClient;
use App\Models\Website;
use PHPUnit\Framework\MockObject\MockObject;
use Mockery;

class UpsertProductsTest extends TestCase
{
    /**
     * Test that upsertProducts method correctly handles create and update scenarios
     */
    public function test_upsert_products_handles_create_and_update()
    {
        // Create a mock website
        $website = Mockery::mock(Website::class);
        $website->shouldReceive('getAttribute')->with('url')->andReturn('https://example.com');
        $website->shouldReceive('getAttribute')->with('consumer_key')->andReturn('test_key');
        $website->shouldReceive('getAttribute')->with('consumer_secret')->andReturn('test_secret');

        // Mock the WooCommerceApiClient
        $apiClient = Mockery::mock(WooCommerceApiClient::class)->makePartial();
        $apiClient->shouldAllowMockingProtectedMethods();

        // Mock the existing product lookup
        $apiClient->shouldReceive('getProductIdMapBySkus')
            ->once()
            ->with(['existing-sku', 'new-sku'])
            ->andReturn(['existing-sku' => 123]);

        // Mock the update call
        $apiClient->shouldReceive('updateProducts')
            ->once()
            ->with([
                [
                    'id' => 123,
                    'name' => 'Updated Product',
                    'sku' => 'existing-sku',
                    'price' => '19.99'
                ]
            ])
            ->andReturn([
                'success' => true,
                'total_requested' => 1,
                'total_updated' => 1,
                'updated' => [
                    ['id' => 123, 'sku' => 'existing-sku']
                ],
                'failed' => []
            ]);

        // Mock the create call
        $apiClient->shouldReceive('createProducts')
            ->once()
            ->with([
                [
                    'name' => 'New Product',
                    'sku' => 'new-sku',
                    'price' => '29.99'
                ]
            ])
            ->andReturn([
                'success' => true,
                'total_requested' => 1,
                'total_created' => 1,
                'created' => [
                    ['id' => 456, 'sku' => 'new-sku']
                ],
                'failed' => []
            ]);

        // Test products array
        $products = [
            [
                'name' => 'Updated Product',
                'sku' => 'existing-sku',
                'price' => '19.99'
            ],
            [
                'name' => 'New Product',
                'sku' => 'new-sku',
                'price' => '29.99'
            ]
        ];

        // Call upsertProducts method
        $result = $apiClient->upsertProducts($products);

        // Assertions
        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['total_requested']);
        $this->assertEquals(1, $result['total_created']);
        $this->assertEquals(1, $result['total_updated']);
        $this->assertEmpty($result['failed']);
    }

    /**
     * Test that upsertProducts handles SKU conflicts by converting failed creates to updates
     */
    public function test_upsert_products_handles_sku_conflicts()
    {
        // Create a mock website
        $website = Mockery::mock(Website::class);
        $website->shouldReceive('getAttribute')->with('url')->andReturn('https://example.com');
        $website->shouldReceive('getAttribute')->with('consumer_key')->andReturn('test_key');
        $website->shouldReceive('getAttribute')->with('consumer_secret')->andReturn('test_secret');

        // Mock the WooCommerceApiClient
        $apiClient = Mockery::mock(WooCommerceApiClient::class)->makePartial();
        $apiClient->shouldAllowMockingProtectedMethods();

        // Mock the existing product lookup - returns empty initially
        $apiClient->shouldReceive('getProductIdMapBySkus')
            ->once()
            ->with(['conflict-sku'])
            ->andReturn([]);

        // Mock the create call that fails with SKU conflict
        $apiClient->shouldReceive('createProducts')
            ->once()
            ->with([
                [
                    'name' => 'Conflict Product',
                    'sku' => 'conflict-sku',
                    'price' => '39.99'
                ]
            ])
            ->andReturn([
                'success' => false,
                'total_requested' => 1,
                'total_created' => 0,
                'created' => [],
                'failed' => [
                    [
                        'error' => [
                            'code' => 'product_invalid_sku',
                            'message' => 'SKU-koodia (conflict-sku) jo hakutaulukossa'
                        ]
                    ]
                ]
            ]);

        // Mock the findProductBySKU method for the retry attempt
        $apiClient->shouldReceive('findProductBySKU')
            ->once()
            ->with('conflict-sku')
            ->andReturn(['id' => 789, 'sku' => 'conflict-sku']);

        // Mock the updateProduct method (single product update)
        $apiClient->shouldReceive('updateProduct')
            ->once()
            ->with(789, [
                'name' => 'Conflict Product',
                'sku' => 'conflict-sku',
                'price' => '39.99'
            ])
            ->andReturn(['id' => 789, 'sku' => 'conflict-sku']);

        // Mock the update call for the SKU conflict resolution
        $apiClient->shouldReceive('updateProducts')
            ->never(); // This shouldn't be called since we're doing individual updates

        // Test products array
        $products = [
            [
                'name' => 'Conflict Product',
                'sku' => 'conflict-sku',
                'price' => '39.99'
            ]
        ];

        // Call upsertProducts method
        $result = $apiClient->upsertProducts($products);

        // Assertions
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['total_requested']);
        $this->assertEquals(0, $result['total_created']);
        $this->assertEquals(1, $result['total_updated']);
        $this->assertEmpty($result['failed']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
