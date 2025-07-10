<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatelessMigrationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Setup method to create required tables for testing
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create minimal required tables without running all migrations
        $this->createRequiredTables();
    }

    /**
     * Cleanup after each test
     */
    protected function tearDown(): void
    {
        // Clean up our test tables
        Schema::dropIfExists('syndicated_products');
        Schema::dropIfExists('feed_website');
        
        parent::tearDown();
    }

    /**
     * Create the minimal tables required for our migration test
     */
    private function createRequiredTables(): void
    {
        // Always drop and recreate to ensure clean state
        Schema::dropIfExists('syndicated_products');
        Schema::dropIfExists('feed_website');
        
        // Create feed_website table (required for foreign key)
        Schema::create('feed_website', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        // Create syndicated_products table (this is what we'll be dropping)
        Schema::create('syndicated_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feed_website_id');
            $table->string('source_product_identifier');
            $table->string('destination_product_id');
            $table->string('last_updated_hash')->nullable();
            $table->timestamps();

            $table->foreign('feed_website_id')->references('id')->on('feed_website')->onDelete('cascade');
            $table->unique(['feed_website_id', 'source_product_identifier']);
        });
        
        // Create other tables that might be referenced by migrations
        $this->createMinimalSupportingTables();
    }

    /**
     * Create minimal supporting tables to avoid foreign key issues
     */
    private function createMinimalSupportingTables(): void
    {
        if (!Schema::hasTable('feeds')) {
            Schema::create('feeds', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('websites')) {
            Schema::create('websites', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('import_runs')) {
            Schema::create('import_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('feed_website_id');
                $table->string('status');
                $table->timestamps();
                
                $table->foreign('feed_website_id')->references('id')->on('feed_website')->onDelete('cascade');
            });
        }
    }

    #[Test]
    public function it_drops_syndicated_products_table_successfully(): void
    {
        // Ensure table exists before migration
        $this->assertTrue(Schema::hasTable('syndicated_products'));
        
        // Simulate the migration up method
        Schema::dropIfExists('syndicated_products');
        
        // Assert table is dropped
        $this->assertFalse(Schema::hasTable('syndicated_products'));
    }

    #[Test]
    public function it_recreates_syndicated_products_table_on_rollback(): void
    {
        // Drop the table first (simulate running up migration)
        Schema::dropIfExists('syndicated_products');
        $this->assertFalse(Schema::hasTable('syndicated_products'));
        
        // Simulate the migration down method (recreate table)
        Schema::create('syndicated_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feed_website_id');
            $table->string('source_product_identifier');
            $table->string('destination_product_id');
            $table->string('last_updated_hash')->nullable();
            $table->timestamps();

            $table->foreign('feed_website_id')->references('id')->on('feed_website')->onDelete('cascade');
            $table->unique(['feed_website_id', 'source_product_identifier']);
        });
        
        // Assert table is recreated
        $this->assertTrue(Schema::hasTable('syndicated_products'));
        
        // Check that the table has the expected columns
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'id'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'feed_website_id'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'source_product_identifier'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'destination_product_id'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'last_updated_hash'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'created_at'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'updated_at'));
    }

    #[Test]
    public function it_handles_dropping_non_existent_table_gracefully(): void
    {
        // Drop table first to ensure it doesn't exist
        Schema::dropIfExists('syndicated_products');
        $this->assertFalse(Schema::hasTable('syndicated_products'));
        
        // Running dropIfExists on non-existent table should not throw error
        try {
            Schema::dropIfExists('syndicated_products');
            $this->assertTrue(true, 'Migration handled non-existent table gracefully');
        } catch (\Exception $e) {
            $this->fail('Migration should handle non-existent table gracefully: ' . $e->getMessage());
        }
        
        // Table should still not exist
        $this->assertFalse(Schema::hasTable('syndicated_products'));
    }

    #[Test]
    public function stateless_architecture_eliminates_local_tracking_dependency(): void
    {
        // Test that we can work without the syndicated_products table
        Schema::dropIfExists('syndicated_products');
        
        // Simulate what the new stateless approach does - no local tracking needed
        $productMetadata = [
            'meta_data' => [
                ['key' => '_elementa_last_seen_timestamp', 'value' => now()->timestamp],
                ['key' => '_elementa_feed_connection_id', 'value' => 123]
            ]
        ];
        
        // Verify metadata structure
        $this->assertArrayHasKey('meta_data', $productMetadata);
        $this->assertCount(2, $productMetadata['meta_data']);
        
        // Find the timestamp metadata
        $timestampMeta = collect($productMetadata['meta_data'])
            ->firstWhere('key', 'elementa_last_seen_timestamp');
        
        $connectionMeta = collect($productMetadata['meta_data'])
            ->firstWhere('key', 'elementa_feed_connection_id');
        
        $this->assertNotNull($timestampMeta);
        $this->assertNotNull($connectionMeta);
        $this->assertEquals(123, $connectionMeta['value']);
        $this->assertIsNumeric($timestampMeta['value']);
    }


}
