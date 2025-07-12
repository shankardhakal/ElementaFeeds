<?php

namespace Tests\Feature;

use App\Models\Feed;
use App\Models\FeedWebsite;
use App\Models\Network;
use App\Models\Website;
use App\Models\User;
use App\Jobs\StartImportRunJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeedStatusValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $network;
    protected $website;
    protected $feed;
    protected $connection;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Create test data without using factories to avoid dependency issues
        $this->network = Network::create([
            'name' => 'Test Network',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $this->website = Website::create([
            'name' => 'Test Website',
            'platform' => 'woocommerce',
            'url' => 'https://test.example.com',
            'language' => 'en',
            'credentials' => json_encode([
                'key' => 'test_key',
                'secret' => 'test_secret'
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $this->feed = Feed::create([
            'network_id' => $this->network->id,
            'name' => 'Test Feed',
            'feed_url' => 'https://test.example.com/feed.csv',
            'language' => 'en',
            'is_active' => true,
            'delimiter' => ',',
            'enclosure' => '"',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $this->connection = FeedWebsite::create([
            'feed_id' => $this->feed->id,
            'website_id' => $this->website->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'update_settings' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Mock HTTP client to prevent actual API calls
        Http::fake([
            '*' => Http::response(['products' => []], 200)
        ]);
        
        // Fake queues to prevent job dispatching
        Queue::fake();
    }

    /** @test */
    public function it_shows_feed_disabled_status_when_feed_is_inactive()
    {
        // Disable the feed
        $this->feed->update(['is_active' => false]);
        
        // Visit the connections dashboard as authenticated user
        $response = $this->actingAs($this->user)->get(route('connection.index'));
        
        $response->assertStatus(200);
        $response->assertSee('Feed Disabled');
        $response->assertDontSee('Active');
    }

    /** @test */
    public function it_shows_connection_paused_status_when_connection_is_inactive()
    {
        // Disable the connection but keep feed active
        $this->connection->update(['is_active' => false]);
        
        // Visit the connections dashboard as authenticated user
        $response = $this->actingAs($this->user)->get(route('connection.index'));
        
        $response->assertStatus(200);
        $response->assertSee('Connection Paused');
        $response->assertDontSee('Active');
    }

    /** @test */
    public function it_shows_active_status_when_both_feed_and_connection_are_active()
    {
        // Both feed and connection are active (default state)
        
        // Visit the connections dashboard as authenticated user
        $response = $this->actingAs($this->user)->get(route('connection.index'));
        
        $response->assertStatus(200);
        $response->assertSee('Active');
        $response->assertDontSee('Feed Disabled');
        $response->assertDontSee('Connection Paused');
    }

    /** @test */
    public function it_prevents_manual_import_when_feed_is_disabled()
    {
        // Disable the feed
        $this->feed->update(['is_active' => false]);
        
        // Attempt to run import as authenticated user
        $response = $this->actingAs($this->user)->post(route('connection.run', $this->connection->id));
        
        $response->assertRedirect(route('connection.index'));
        $response->assertSessionHas('alert');
        
        // Verify no job was dispatched
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_prevents_manual_import_when_connection_is_disabled()
    {
        // Disable the connection
        $this->connection->update(['is_active' => false]);
        
        // Attempt to run import as authenticated user
        $response = $this->actingAs($this->user)->post(route('connection.run', $this->connection->id));
        
        $response->assertRedirect(route('connection.index'));
        $response->assertSessionHas('alert');
        
        // Verify no job was dispatched
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_allows_manual_import_when_both_are_active()
    {
        // Both feed and connection are active (default state)
        
        // Mock the cache to prevent actual locking
        $this->mock(\Illuminate\Contracts\Cache\Repository::class)
            ->shouldReceive('lock')
            ->andReturnSelf()
            ->shouldReceive('get')
            ->andReturn(true)
            ->shouldReceive('release')
            ->andReturn(true);
        
        // Attempt to run import as authenticated user
        $response = $this->actingAs($this->user)->post(route('connection.run', $this->connection->id));
        
        $response->assertRedirect(route('connection.index'));
        $response->assertSessionHas('alert');
    }

    /** @test */
    public function start_import_job_aborts_when_feed_is_disabled()
    {
        // Disable the feed
        $this->feed->update(['is_active' => false]);
        
        // Create and run the job
        $job = new StartImportRunJob($this->connection);
        $job->handle();
        
        // Verify no import run was created
        $this->assertDatabaseMissing('import_runs', [
            'feed_website_id' => $this->connection->id
        ]);
    }

    /** @test */
    public function start_import_job_aborts_when_connection_is_disabled()
    {
        // Disable the connection
        $this->connection->update(['is_active' => false]);
        
        // Create and run the job
        $job = new StartImportRunJob($this->connection);
        $job->handle();
        
        // Verify no import run was created
        $this->assertDatabaseMissing('import_runs', [
            'feed_website_id' => $this->connection->id
        ]);
    }

    /** @test */
    public function start_import_job_proceeds_when_both_are_active()
    {
        // Both feed and connection are active (default state)
        
        // Mock the DownloadFeedJob dispatch
        Queue::fake();
        
        // Create and run the job
        $job = new StartImportRunJob($this->connection);
        $job->handle();
        
        // Verify import run was created
        $this->assertDatabaseHas('import_runs', [
            'feed_website_id' => $this->connection->id,
            'status' => 'processing'
        ]);
        
        // Verify DownloadFeedJob was dispatched
        Queue::assertPushed(\App\Jobs\DownloadFeedJob::class);
    }

    /** @test */
    public function model_helper_methods_work_correctly()
    {
        // Test with both active
        $this->assertTrue($this->connection->isEffectivelyActive());
        $this->assertEquals('Active', $this->connection->getEffectiveStatusText());
        $this->assertEquals('bg-success', $this->connection->getEffectiveStatusClass());
        
        // Test with feed disabled
        $this->feed->update(['is_active' => false]);
        $this->connection->refresh();
        $this->assertFalse($this->connection->isEffectivelyActive());
        $this->assertEquals('Feed Disabled', $this->connection->getEffectiveStatusText());
        $this->assertEquals('bg-danger', $this->connection->getEffectiveStatusClass());
        
        // Test with connection disabled (feed active)
        $this->feed->update(['is_active' => true]);
        $this->connection->update(['is_active' => false]);
        $this->connection->refresh();
        $this->assertFalse($this->connection->isEffectivelyActive());
        $this->assertEquals('Connection Paused', $this->connection->getEffectiveStatusText());
        $this->assertEquals('bg-secondary', $this->connection->getEffectiveStatusClass());
    }
}
