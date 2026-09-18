<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Domain\Streaming\Relay\RelaySupervisor;
use App\Domain\Streaming\StreamSessionService;
use App\Events\DestinationFailed;
use App\Models\Recording;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSessionDestination;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WORKFLOW B/C simulation with a fake ffmpeg: one destination stays live while another fails,
 * retries with backoff and finally gives up – without stopping the others.
 */
class DistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'akstream.streaming.engine' => 'none',
            'akstream.streaming.ffmpeg' => base_path('tests/Fixtures/fake-ffmpeg.sh'),
            'akstream.streaming.backoff' => [0, 0, 0],
            'akstream.recording.disk' => 'local',
            'akstream.recording.path' => 'test-recordings',
        ]);
        app()->forgetInstance(StreamEngineInterface::class);
        app()->forgetInstance(RelaySupervisor::class);
    }

    public function test_multi_destination_distribution_with_failure_isolation_and_retries(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        app(SettingsService::class)->set('streaming', 'retry_count', '1');
        Notification::fake();

        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $good = StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'name' => 'YouTube-like', 'rtmp_url' => 'rtmp://good.example.com/live2']);
        $bad = StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Facebook-like', 'rtmp_url' => 'rtmp://fail.example.com/rtmp']);
        $disabled = StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Disabled', 'is_enabled' => false]);

        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        $this->assertSame('detected', $session->status);

        $created = app(DistributionService::class)->start($session, null, $user);
        $this->assertCount(2, $created); // disabled skipped
        $this->assertSame('live', $session->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stream.started']);

        $supervisor = app(RelaySupervisor::class);
        try {
            $supervisor->tick();
            $rows = fn () => StreamSessionDestination::withoutGlobalScopes()->get()->keyBy('stream_destination_id');
            $this->assertContains($rows()[$good->id]->status, ['connecting', 'live']);

            usleep(700_000);
            $supervisor->tick(); // good: bytes flowing → live ; bad: exited → retry 1
            usleep(300_000);
            $supervisor->tick();
            $this->assertSame('live', $rows()[$good->id]->status, 'good destination should be live');
            $this->assertSame('live', $good->fresh()->status);
            $this->assertGreaterThan(0, $rows()[$good->id]->bytes_sent);

            // bad destination: retry loop then failed (max 1 retry)
            for ($i = 0; $i < 6 && $rows()[$bad->id]->status !== 'failed'; $i++) {
                usleep(300_000);
                $supervisor->tick();
            }
            $badRow = $rows()[$bad->id];
            $this->assertSame('failed', $badRow->status);
            $this->assertStringContainsString('Connection refused', (string) $badRow->last_error);
            $this->assertGreaterThanOrEqual(1, $badRow->retry_count);
            $this->assertSame('failed', $bad->fresh()->status);

            // The good one is unaffected
            $this->assertSame('live', $rows()[$good->id]->status);
            $this->assertSame('live', $session->fresh()->status);
            Notification::assertSentTo($user, SystemNotification::class, fn ($n) => $n->type === 'destination.failed');

            // Logs timeline exists
            $this->assertDatabaseHas('stream_destination_logs', ['event' => 'destination.live']);
            $this->assertDatabaseHas('stream_destination_logs', ['event' => 'destination.failed']);

            // Restart failed destination → pending again
            app(DistributionService::class)->restartDestination($badRow, $user);
            $this->assertSame('pending', $badRow->fresh()->status);

            // Stop a single destination
            app(DistributionService::class)->stopDestination($rows()[$good->id], $user);
            $supervisor->tick();
            $this->assertSame('stopped', $rows()[$good->id]->status);

            // Stop whole stream
            app(DistributionService::class)->stop($session, $user);
            $supervisor->tick();
            $this->assertSame('ended', $session->fresh()->status);
            $this->assertSame(0, StreamSessionDestination::withoutGlobalScopes()->where('desired_state', 'running')->count());
            $this->assertDatabaseHas('activity_logs', ['action' => 'stream.stopped']);
        } finally {
            $supervisor->shutdown();
        }
    }

    public function test_recording_lifecycle(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        Storage::fake('local');
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id, 'record_enabled' => true]);
        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        $this->assertTrue($session->recording_enabled);

        $supervisor = app(RelaySupervisor::class);
        try {
            $supervisor->tick();
            $rec = Recording::withoutGlobalScopes()->first();
            $this->assertNotNull($rec);
            $this->assertSame('recording', $rec->status);
            usleep(400_000);
            app(StreamSessionService::class)->end($session);
            $supervisor->tick();
            $rec->refresh();
            $this->assertSame('completed', $rec->status);
            $this->assertGreaterThan(0, $rec->size_bytes);
            $this->assertTrue(Storage::disk('local')->exists($rec->path));

            // Authorized download + delete via HTTP
            $this->actingAs($user)->get('/admin/recordings')->assertOk()->assertSee($rec->title);
            $this->actingAs($user)->get('/admin/recordings/'.$rec->id.'/download')->assertOk();
            $this->actingAs($user)->delete('/admin/recordings/'.$rec->id)->assertRedirect();
            $this->assertFalse(Storage::disk('local')->exists($rec->path));
        } finally {
            $supervisor->shutdown();
        }
    }

    public function test_auto_distribution_mode(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        Event::fake([DestinationFailed::class]);
        app(SettingsService::class)->set('streaming', 'auto_distribution', '1');
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        StreamDestination::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        $this->assertSame('live', $session->fresh()->status);
        $this->assertSame(2, $session->destinations()->count());
    }

    public function test_live_page_controls_via_http(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $d = StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user)->post('/admin/live/start')->assertSessionHas('error'); // no source yet
        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        $this->actingAs($user)->post('/admin/live/start', ['destination_ids' => [$d->id]])->assertSessionHas('status');
        $this->actingAs($user)->get('/admin/live')->assertOk()->assertSee('LIVE');
        $this->actingAs($user)->getJson('/admin/live/logs?after=0')->assertOk()->assertJsonStructure(['logs']);
        $this->actingAs($user)->post('/admin/live/stop')->assertSessionHas('status');
        $this->assertSame('ended', $session->fresh()->status);
        $this->actingAs($user)->get('/admin/history/'.$session->id)->assertOk()->assertSee($d->name);
    }

    public function test_a_running_relay_switches_to_the_overlay_stream_when_it_goes_live(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        config(['akstream.streaming.engine' => 'mediamtx', 'akstream.streaming.internal_rtmp_url' => 'rtmp://127.0.0.1:1935']);
        app()->forgetInstance(StreamEngineInterface::class);
        app()->forgetInstance(RelaySupervisor::class);

        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'name' => 'YouTube-like', 'rtmp_url' => 'rtmp://good.example.com/live2']);

        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        app(DistributionService::class)->start($session, null, $user);

        $supervisor = app(RelaySupervisor::class);

        try {
            // The overlay encoder is not up yet, so the relay copies the plain ingest stream.
            $supervisor->tick();
            $this->assertStringContainsString('live/'.$endpoint->plainKey(), $this->relaySource($supervisor));
            $this->assertStringNotContainsString('branded/', $this->relaySource($supervisor));

            // A few seconds later the encoder reports it is rendering.
            $session->forceFill(['branding_status' => 'live'])->save();
            $supervisor->tick();

            $this->assertStringContainsString('branded/'.$endpoint->plainKey(), $this->relaySource($supervisor),
                'the relay must follow the branded stream, otherwise the overlay never reaches the platform');
            $this->assertDatabaseHas('stream_destination_logs', ['event' => 'destination.source_changed']);

            // If the encoder dies, the relay falls back so the platform keeps receiving video.
            $session->forceFill(['branding_status' => 'failed'])->save();
            $supervisor->tick();
            $this->assertStringNotContainsString('branded/', $this->relaySource($supervisor));
        } finally {
            $supervisor->shutdown();
        }
    }

    private function relaySource(RelaySupervisor $supervisor): string
    {
        $relays = (new \ReflectionProperty($supervisor, 'relays'))->getValue($supervisor);
        $this->assertNotEmpty($relays, 'a relay should be running');

        return reset($relays)->sourceUrl();
    }
}
