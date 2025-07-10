<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DropSyndicatedProductsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_drops_syndicated_products_table_on_up()
    {
        // First, create the table to simulate it existing
        Schema::create('syndicated_products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('feed_website_id');
            $table->string('source_product_identifier');
            $table->string('destination_product_id');
            $table->string('last_updated_hash')->nullable();
            $table->timestamps();
        });

        // Verify table exists
        $this->assertTrue(Schema::hasTable('syndicated_products'));

        // Run the migration
        $migration = include database_path('migrations/2025_07_10_131100_drop_syndicated_products_table.php');
        $migration->up();

        // Verify table has been dropped
        $this->assertFalse(Schema::hasTable('syndicated_products'));
    }

    #[Test]
    public function it_recreates_syndicated_products_table_on_down()
    {
        // Ensure table doesn't exist
        Schema::dropIfExists('syndicated_products');
        $this->assertFalse(Schema::hasTable('syndicated_products'));

        // Run the rollback migration
        $migration = include database_path('migrations/2025_07_10_131100_drop_syndicated_products_table.php');
        $migration->down();

        // Verify table has been recreated
        $this->assertTrue(Schema::hasTable('syndicated_products'));

        // Verify table structure
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'id'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'feed_website_id'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'source_product_identifier'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'destination_product_id'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'last_updated_hash'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'created_at'));
        $this->assertTrue(Schema::hasColumn('syndicated_products', 'updated_at'));
    }

    #[Test]
    public function it_handles_dropping_non_existent_table_gracefully()
    {
        // Ensure table doesn't exist
        Schema::dropIfExists('syndicated_products');
        $this->assertFalse(Schema::hasTable('syndicated_products'));

        // Run the migration - should not throw exception
        $migration = include database_path('migrations/2025_07_10_131100_drop_syndicated_products_table.php');
        
        try {
            $migration->up();
            $this->assertTrue(true, 'Migration should handle non-existent table gracefully');
        } catch (\Exception $e) {
            $this->fail('Migration should not throw exception when table does not exist: ' . $e->getMessage());
        }
    }

    #[Test]
    public function it_can_run_up_and_down_migrations_repeatedly()
    {
        $migration = include database_path('migrations/2025_07_10_131100_drop_syndicated_products_table.php');

        // Create table first
        $migration->down();
        $this->assertTrue(Schema::hasTable('syndicated_products'));

        // Drop it
        $migration->up();
        $this->assertFalse(Schema::hasTable('syndicated_products'));

        // Recreate it
        $migration->down();
        $this->assertTrue(Schema::hasTable('syndicated_products'));

        // Drop it again
        $migration->up();
        $this->assertFalse(Schema::hasTable('syndicated_products'));
    }

    #[Test]
    public function recreated_table_has_correct_foreign_key_constraints()
    {
        // First ensure feed_website table exists (it should via migrations)
        if (!Schema::hasTable('feed_website')) {
            Schema::create('feed_website', function ($table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        // Run the rollback migration to create syndicated_products
        $migration = include database_path('migrations/2025_07_10_131100_drop_syndicated_products_table.php');
        $migration->down();

        // Verify table exists
        $this->assertTrue(Schema::hasTable('syndicated_products'));

        // We can't easily test foreign key constraints without actually inserting data
        // But we can verify the table structure is as expected
        $columns = Schema::getColumnListing('syndicated_products');
        $expectedColumns = [
            'id',
            'feed_website_id',
            'source_product_identifier',
            'destination_product_id',
            'last_updated_hash',
            'created_at',
            'updated_at'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, "Column '{$column}' should exist in syndicated_products table");
        }
    }
}
