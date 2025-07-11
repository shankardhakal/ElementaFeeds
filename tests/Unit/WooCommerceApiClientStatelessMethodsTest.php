<?php

namespace Tests\Unit;

use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WooCommerceApiClientStatelessMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected $website;
    protected $apiClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce',
            'url' => 'https://test.example.com',
            'woocommerce_credentials' => json_encode([
                'key' => 'test_key',
                'secret' => 'test_secret'
            ])
        ]);

        $this->apiClient = new WooCommerceApiClient($this->website);
    }

    /** @test */
    public function it_can_find_stale_products_by_connection_and_timestamp()
    {
        $connectionId = 44;
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock the HTTP response for products with ElementaFeeds metadata
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Product 1',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => $cutoffTimestamp - 3600] // 1 hour before cutoff
                    ]
                ],
                [
                    'id' => 2,
                    'sku' => 'SKU002',
                    'name' => 'Product 2',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => $cutoffTimestamp + 3600] // 1 hour after cutoff (not stale)
                    ]
                ],
                [
                    'id' => 3,
                    'sku' => 'SKU003',
                    'name' => 'Product 3',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => $cutoffTimestamp - 7200] // 2 hours before cutoff
                    ]
                ]
            ])
        ]);

        $staleProducts = $this->apiClient->findStaleProducts($connectionId, $cutoffTimestamp);

        // Should return 2 stale products (products 1 and 3)
        $this->assertCount(2, $staleProducts);
        
        $staleProductIds = collect($staleProducts)->pluck('id')->toArray();
        $this->assertContains(1, $staleProductIds);
        $this->assertContains(3, $staleProductIds);
        $this->assertNotContains(2, $staleProductIds); // Product 2 is not stale
        
        // Verify product structure
        $this->assertArrayHasKey('id', $staleProducts[0]);
        $this->assertArrayHasKey('sku', $staleProducts[0]);
        $this->assertArrayHasKey('name', $staleProducts[0]);
        $this->assertArrayHasKey('last_seen', $staleProducts[0]);
    }

    /** @test */
    public function it_handles_products_without_timestamp_metadata()
    {
        $connectionId = 44;
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock the HTTP response for products without timestamp metadata
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Product 1',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44']
                        // Missing elementa_last_seen_timestamp
                    ]
                ]
            ])
        ]);

        $staleProducts = $this->apiClient->findStaleProducts($connectionId, $cutoffTimestamp);

        // Should return 1 stale product (products without timestamp are considered stale)
        $this->assertCount(1, $staleProducts);
        $this->assertEquals(1, $staleProducts[0]['id']);
        $this->assertNull($staleProducts[0]['last_seen']);
    }

    /** @test */
    public function it_handles_empty_product_list()
    {
        $connectionId = 44;
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock the HTTP response for no products
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response([])
        ]);

        $staleProducts = $this->apiClient->findStaleProducts($connectionId, $cutoffTimestamp);

        $this->assertEmpty($staleProducts);
    }

    /** @test */
    public function it_can_find_products_by_connection()
    {
        $connectionId = 44;

        // Mock the HTTP response
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Product 1',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44']
                    ]
                ],
                [
                    'id' => 2,
                    'sku' => 'SKU002',
                    'name' => 'Product 2',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44']
                    ]
                ]
            ])
        ]);

        $products = $this->apiClient->findProductsByConnection($connectionId);

        $this->assertCount(2, $products);
        $this->assertEquals(1, $products[0]['id']);
        $this->assertEquals(2, $products[1]['id']);
    }

    /** @test */
    public function it_can_get_elementa_product_statistics()
    {
        // Mock the HTTP response for statistics
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Product 1',
                    'modified' => '2025-07-10T10:00:00',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => now()->timestamp]
                    ]
                ],
                [
                    'id' => 2,
                    'sku' => 'SKU002',
                    'name' => 'Product 2',
                    'modified' => '2025-07-10T09:00:00',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '45'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => now()->subHour()->timestamp]
                    ]
                ]
            ], 200, [
                'X-WP-Total' => ['2'] // Mock total count header
            ])
        ]);

        $stats = $this->apiClient->getElementaProductStats();

        $this->assertArrayHasKey('total_managed_products', $stats);
        $this->assertArrayHasKey('products_by_connection', $stats);
        $this->assertArrayHasKey('oldest_last_seen', $stats);
        $this->assertArrayHasKey('newest_last_seen', $stats);
        $this->assertArrayHasKey('sample_size', $stats);

        $this->assertEquals(2, $stats['total_managed_products']);
        $this->assertEquals(2, $stats['sample_size']);
        $this->assertArrayHasKey(44, $stats['products_by_connection']);
        $this->assertArrayHasKey(45, $stats['products_by_connection']);
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        $connectionId = 44;
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Mock HTTP error response
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response('Server Error', 500)
        ]);

        $staleProducts = $this->apiClient->findStaleProducts($connectionId, $cutoffTimestamp);

        // Should return empty array when API fails
        $this->assertEmpty($staleProducts);
    }

    /** @test */
    public function it_correctly_identifies_stale_products_by_timestamp()
    {
        $cutoffTimestamp = now()->subDays(30)->timestamp;

        // Test the isProductStale method indirectly through findStaleProducts
        Http::fake([
            'test.example.com/wp-json/wc/v3/products*' => Http::response([
                [
                    'id' => 1,
                    'sku' => 'SKU001',
                    'name' => 'Fresh Product',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => now()->timestamp] // Fresh
                    ]
                ],
                [
                    'id' => 2,
                    'sku' => 'SKU002',
                    'name' => 'Stale Product',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => $cutoffTimestamp - 1] // Stale by 1 second
                    ]
                ],
                [
                    'id' => 3,
                    'sku' => 'SKU003',
                    'name' => 'Borderline Product',
                    'meta_data' => [
                        ['key' => 'elementa_feed_connection_id', 'value' => '44'],
                        ['key' => 'elementa_last_seen_timestamp', 'value' => $cutoffTimestamp] // Exactly at cutoff
                    ]
                ]
            ])
        ]);

        $staleProducts = $this->apiClient->findStaleProducts(44, $cutoffTimestamp);

        // Should return only the stale product (product 2)
        $this->assertCount(1, $staleProducts);
        $this->assertEquals(2, $staleProducts[0]['id']);
        $this->assertEquals('SKU002', $staleProducts[0]['sku']);
    }

    /** @test */
    public function it_extracts_last_seen_timestamp_correctly()
    {
        $timestamp = 1720614000; // Example timestamp
        $product = [
            'id' => 1,
            'meta_data' => [
                ['key' => 'other_meta', 'value' => 'other_value'],
                ['key' => '_elementa_last_seen_timestamp', 'value' => $timestamp],
                ['key' => 'another_meta', 'value' => 'another_value']
            ]
        ];

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->apiClient);
        $method = $reflection->getMethod('getProductLastSeen');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->apiClient, [$product]);

        $this->assertEquals($timestamp, $result);
    }

    /** @test */
    public function it_returns_null_for_missing_timestamp_metadata()
    {
        $product = [
            'id' => 1,
            'meta_data' => [
                ['key' => 'other_meta', 'value' => 'other_value'],
                ['key' => 'another_meta', 'value' => 'another_value']
            ]
        ];

        // Use reflection to test the protected method
        $reflection = new \ReflectionClass($this->apiClient);
        $method = $reflection->getMethod('getProductLastSeen');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->apiClient, [$product]);

        $this->assertNull($result);
    }
}
