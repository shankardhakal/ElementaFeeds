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
     * Test that upsertProductsByGUPID method correctly handles create and update scenarios
     */
    public function test_upsert_products_by_gupid_handles_create_and_update()
    {
        // Create a mock website
        $website = Mockery::mock(Website::class);
        $website->shouldReceive('getAttribute')->with('url')->andReturn('https://example.com');
        $website->shouldReceive('getAttribute')->with('woocommerce_credentials')->andReturn('{"key":"test_key","secret":"test_secret"}');
        $website->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock the WooCommerceApiClient
        $apiClient = Mockery::mock(WooCommerceApiClient::class)->makePartial();
        $apiClient->shouldAllowMockingProtectedMethods();

        // Mock the existing product lookup by GUPIDs
        $apiClient->shouldReceive('getProductIdMapByGUPIDs')
            ->once()
            ->with(['existing-gupid-123', 'new-gupid-456'])
            ->andReturn(['existing-gupid-123' => 123]);

        // Mock the batch products call
        $apiClient->shouldReceive('batchProducts')
            ->once()
            ->with([
                'create' => [
                    [
                        'name' => 'New Product',
                        'sku' => 'new-sku',
                        'price' => '29.99',
                        'meta_data' => [
                            ['key' => 'gupid', 'value' => 'new-gupid-456']
                        ]
                    ]
                ],
                'update' => [
                    [
                        'id' => 123,
                        'name' => 'Updated Product',
                        'price' => '19.99',
                        'meta_data' => [
                            ['key' => 'gupid', 'value' => 'existing-gupid-123']
                        ]
                    ]
                ]
            ])
            ->andReturn([
                'success' => true,
                'created' => [
                    ['id' => 456, 'name' => 'New Product']
                ],
                'updated' => [
                    ['id' => 123, 'name' => 'Updated Product']
                ],
                'failed' => []
            ]);

        // Test products array with GUPIDs
        $products = [
            [
                'name' => 'Updated Product',
                'sku' => 'existing-sku',
                'price' => '19.99',
                'meta_data' => [
                    ['key' => 'gupid', 'value' => 'existing-gupid-123']
                ]
            ],
            [
                'name' => 'New Product',
                'sku' => 'new-sku',
                'price' => '29.99',
                'meta_data' => [
                    ['key' => 'gupid', 'value' => 'new-gupid-456']
                ]
            ]
        ];

        // Call upsertProductsByGUPID method
        $result = $apiClient->upsertProductsByGUPID($products);

        // Assertions
        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['total_requested']);
        $this->assertEquals(1, $result['total_created']);
        $this->assertEquals(1, $result['total_updated']);
        $this->assertEmpty($result['failed']);
    }

    /**
     * Test that upsertProductsByGUPID handles server errors gracefully
     */
    public function test_upsert_products_by_gupid_handles_server_errors()
    {
        // Create a mock website
        $website = Mockery::mock(Website::class);
        $website->shouldReceive('getAttribute')->with('url')->andReturn('https://example.com');
        $website->shouldReceive('getAttribute')->with('woocommerce_credentials')->andReturn('{"key":"test_key","secret":"test_secret"}');
        $website->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock the WooCommerceApiClient
        $apiClient = Mockery::mock(WooCommerceApiClient::class)->makePartial();
        $apiClient->shouldAllowMockingProtectedMethods();

        // Mock the existing product lookup to throw server error
        $apiClient->shouldReceive('getProductIdMapByGUPIDs')
            ->once()
            ->with(['test-gupid-123'])
            ->andThrow(new \Exception('Server errors prevent GUPID lookup. Please try again later.'));

        // Test products array with GUPIDs
        $products = [
            [
                'name' => 'Test Product',
                'sku' => 'test-sku',
                'price' => '19.99',
                'meta_data' => [
                    ['key' => 'gupid', 'value' => 'test-gupid-123']
                ]
            ]
        ];

        // Call upsertProductsByGUPID method
        $result = $apiClient->upsertProductsByGUPID($products);

        // Assertions
        $this->assertFalse($result['success']);
        $this->assertEquals(1, $result['total_requested']);
        $this->assertEquals(0, $result['total_created']);
        $this->assertEquals(0, $result['total_updated']);
        $this->assertNotEmpty($result['failed']);
        $this->assertStringContainsString('GUPID lookup failed', $result['error']);
    }

    /**
     * Test that findProductsByGUPID method returns correct products
     */
    public function test_find_products_by_gupid()
    {
        // Create a mock website
        $website = Mockery::mock(Website::class);
        $website->shouldReceive('getAttribute')->with('url')->andReturn('https://example.com');
        $website->shouldReceive('getAttribute')->with('woocommerce_credentials')->andReturn('{"key":"test_key","secret":"test_secret"}');
        $website->shouldReceive('getAttribute')->with('id')->andReturn(1);

        // Mock the WooCommerceApiClient
        $apiClient = Mockery::mock(WooCommerceApiClient::class)->makePartial();
        $apiClient->shouldAllowMockingProtectedMethods();

        // Mock the makeRequest method to return products
        $apiClient->shouldReceive('makeRequest')
            ->once()
            ->with('products', [
                'per_page' => 100,
                'page' => 1,
                'meta_key' => 'gupid',
                'orderby' => 'id',
                'order' => 'desc',
                'status' => 'any'
            ])
            ->andReturn([
                [
                    'id' => 123,
                    'name' => 'Test Product',
                    'sku' => 'test-sku',
                    'meta_data' => [
                        ['key' => 'gupid', 'value' => 'test-gupid-123']
                    ]
                ],
                [
                    'id' => 456,
                    'name' => 'Another Product',
                    'sku' => 'another-sku',
                    'meta_data' => [
                        ['key' => 'gupid', 'value' => 'different-gupid-456']
                    ]
                ]
            ]);

        // Test GUPIDs array
        $gupids = ['test-gupid-123'];

        // Call findProductsByGUPID method
        $result = $apiClient->findProductsByGUPID($gupids);

        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(123, $result[0]['id']);
        $this->assertEquals('Test Product', $result[0]['name']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
