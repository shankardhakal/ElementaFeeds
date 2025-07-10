<?php

namespace Tests\Unit;

use App\Console\Commands\ReconcileStaleProducts;
use App\Jobs\ProcessStaleProductCleanup;
use App\Models\Feed;
use App\Models\FeedWebsite;
use App\Models\Network;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileStaleProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @test */
    public function it_can_find_connections_with_stale_product_settings()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create connection with stale product settings
        $connection = FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'set_stock_zero',
                'stale_days' => 30
            ]
        ]);

        // Run the command in dry-run mode
        $this->artisan('elementa:reconcile-stale-products', ['--dry-run' => true])
            ->expectsOutput('🔄 Starting stateless product reconciliation...')
            ->expectsOutput('Found 1 feed connection(s) to process:')
            ->expectsOutput('✅ Dry run completed. 1 connection(s) would be processed.')
            ->assertExitCode(0);

        // Verify no jobs were dispatched in dry-run mode
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_dispatches_cleanup_jobs_for_active_connections()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create connection with stale product settings
        $connection = FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'delete',
                'stale_days' => 7
            ]
        ]);

        // Run the command with force flag
        $this->artisan('elementa:reconcile-stale-products', ['--force' => true])
            ->expectsOutput('🔄 Starting stateless product reconciliation...')
            ->expectsOutput('Found 1 feed connection(s) to process:')
            ->expectsOutput('✅ Reconciliation completed. 1 cleanup job(s) dispatched for 1 connection(s).')
            ->assertExitCode(0);

        // Verify the cleanup job was dispatched
        Queue::assertPushed(ProcessStaleProductCleanup::class, function ($job) use ($connection) {
            return $job->connectionId === $connection->id &&
                   $job->action === 'delete' &&
                   $job->cutoffTimestamp > 0;
        });
    }

    /** @test */
    public function it_ignores_connections_without_stale_settings()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create connection without stale product settings
        FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection Without Stale Settings',
            'is_active' => true,
            'update_settings' => []
        ]);

        // Create connection with invalid stale settings
        FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection With Invalid Settings',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'invalid_action',
                'stale_days' => 0
            ]
        ]);

        // Run the command
        $this->artisan('elementa:reconcile-stale-products', ['--force' => true])
            ->expectsOutput('No active feed connections found with stale product handling enabled.')
            ->assertExitCode(0);

        // Verify no jobs were dispatched
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_ignores_inactive_connections()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create inactive connection with stale product settings
        FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Inactive Test Connection',
            'is_active' => false,
            'update_settings' => [
                'stale_action' => 'set_stock_zero',
                'stale_days' => 30
            ]
        ]);

        // Run the command
        $this->artisan('elementa:reconcile-stale-products', ['--force' => true])
            ->expectsOutput('No active feed connections found with stale product handling enabled.')
            ->assertExitCode(0);

        // Verify no jobs were dispatched
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_can_process_specific_connection_id()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create two connections
        $connection1 = FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection 1',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'set_stock_zero',
                'stale_days' => 30
            ]
        ]);

        $connection2 = FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection 2',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'delete',
                'stale_days' => 7
            ]
        ]);

        // Run the command for specific connection
        $this->artisan('elementa:reconcile-stale-products', [
            '--connection-id' => $connection1->id,
            '--force' => true
        ])
            ->expectsOutput('Found 1 feed connection(s) to process:')
            ->expectsOutput('✅ Reconciliation completed. 1 cleanup job(s) dispatched for 1 connection(s).')
            ->assertExitCode(0);

        // Verify only the specific connection's job was dispatched
        Queue::assertPushed(ProcessStaleProductCleanup::class, 1);
        Queue::assertPushed(ProcessStaleProductCleanup::class, function ($job) use ($connection1) {
            return $job->connectionId === $connection1->id;
        });
    }

    /** @test */
    public function it_calculates_correct_cutoff_timestamps()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create connection with 15-day threshold
        $connection = FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'update_settings' => [
                'stale_action' => 'set_stock_zero',
                'stale_days' => 15
            ]
        ]);

        $expectedCutoff = now()->subDays(15)->timestamp;

        // Run the command
        $this->artisan('elementa:reconcile-stale-products', ['--force' => true]);

        // Verify the cutoff timestamp is approximately correct (within 1 minute)
        Queue::assertPushed(ProcessStaleProductCleanup::class, function ($job) use ($expectedCutoff) {
            return abs($job->cutoffTimestamp - $expectedCutoff) < 60;
        });
    }

    /** @test */
    public function it_handles_missing_update_settings_gracefully()
    {
        // Create test data
        $network = Network::factory()->create(['name' => 'Test Network']);
        $feed = Feed::factory()->create([
            'network_id' => $network->id,
            'name' => 'Test Feed'
        ]);
        $website = Website::factory()->create([
            'name' => 'Test Website',
            'platform' => 'woocommerce'
        ]);

        // Create connection with null update_settings
        FeedWebsite::create([
            'feed_id' => $feed->id,
            'website_id' => $website->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'update_settings' => null
        ]);

        // Run the command
        $this->artisan('elementa:reconcile-stale-products', ['--force' => true])
            ->expectsOutput('No active feed connections found with stale product handling enabled.')
            ->assertExitCode(0);

        // Verify no jobs were dispatched
        Queue::assertNothingPushed();
    }
}
