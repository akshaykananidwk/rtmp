<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Destinations\ConnectorRegistry;
use App\Models\PlatformAccount;
use App\Models\PlatformToken;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DestinationConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_lists_platforms_with_honest_support_levels(): void
    {
        $defs = app(ConnectorRegistry::class)->definitions();
        $this->assertSame('supported', $defs['youtube']->support);
        $this->assertSame('supported', $defs['facebook']->support);
        $this->assertSame('supported', $defs['custom_rtmp']->support);
        $this->assertSame('not_supported_by_api', $defs['instagram']->support);
        $this->assertSame('partial', $defs['linkedin']->support);
        $this->assertStringContainsString('NOT SUPPORTED BY CURRENT OFFICIAL API', $defs['instagram']->description);
    }

    public function test_custom_rtmp_validation_and_target(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $d = StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'rtmp_url' => 'rtmp://live.example.com/app']);
        $d->setStreamKey('my-key');
        $d->save();
        $connector = app(ConnectorRegistry::class)->for($d);
        $this->assertTrue($connector->validate()['ok']);
        $sd = new StreamSessionDestination;
        $this->assertSame('rtmp://live.example.com/app/my-key', $connector->rtmpTarget($sd));
        $this->assertSame('••••••••••-key', $d->maskedKey());
    }

    public function test_youtube_connector_creates_broadcast_via_official_api(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        Http::fake([
            'https://www.googleapis.com/youtube/v3/liveBroadcasts?part=snippet,status,contentDetails' => Http::response(['id' => 'BCAST1'], 200),
            'https://www.googleapis.com/youtube/v3/liveStreams?part=snippet,cdn,contentDetails' => Http::response(['id' => 'STREAM1', 'cdn' => ['ingestionInfo' => ['ingestionAddress' => 'rtmp://a.rtmp.youtube.com/live2', 'streamName' => 'yt-secret-name']]], 200),
            'https://www.googleapis.com/youtube/v3/liveBroadcasts/bind*' => Http::response(['id' => 'BCAST1'], 200),
            'https://www.googleapis.com/youtube/v3/liveBroadcasts/transition*' => Http::response(['id' => 'BCAST1'], 200),
        ]);

        $account = PlatformAccount::create(['tenant_id' => $tenant->id, 'platform' => 'youtube', 'external_id' => 'UC1', 'name' => 'My Channel']);
        $t = new PlatformToken(['platform_account_id' => $account->id, 'expires_at' => now()->addHour()]);
        $t->setAccessToken('ya29.token');
        $t->save();

        $d = StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'platform' => 'youtube', 'platform_account_id' => $account->id, 'connection_method' => 'oauth', 'options' => ['title' => 'Test', 'privacy' => 'unlisted']]);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $session = StreamSession::create(['tenant_id' => $tenant->id, 'stream_endpoint_id' => $endpoint->id, 'status' => 'detected', 'started_at' => now()]);
        $sd = StreamSessionDestination::create(['tenant_id' => $tenant->id, 'stream_session_id' => $session->id, 'stream_destination_id' => $d->id, 'status' => 'preparing']);

        $connector = app(ConnectorRegistry::class)->for($d);
        $this->assertTrue($connector->requiresPreparation());
        $connector->startStream($sd);
        $sd->refresh();
        $this->assertSame('BCAST1', $sd->platform_meta['broadcast_id']);
        $this->assertSame('https://youtube.com/watch?v=BCAST1', $sd->platform_meta['watch_url']);
        $this->assertStringNotContainsString('yt-secret-name', json_encode($sd->platform_meta));
        $this->assertSame('rtmp://a.rtmp.youtube.com/live2/yt-secret-name', $connector->rtmpTarget($sd));
        $connector->onRelayLive($sd);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'liveBroadcasts/transition') && str_contains($r->url(), 'broadcastStatus=live'));
    }

    public function test_facebook_connector_creates_live_video(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        Http::fake([
            'https://graph.facebook.com/*/PAGE1?fields=access_token' => Http::response(['access_token' => 'page-token'], 200),
            'https://graph.facebook.com/*/PAGE1/live_videos' => Http::response(['id' => 'LV1', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/fb-secret'], 200),
            'https://graph.facebook.com/*/LV1' => Http::response(['success' => true], 200),
        ]);
        $account = PlatformAccount::create(['tenant_id' => $tenant->id, 'platform' => 'facebook', 'external_id' => 'U1', 'name' => 'Akshay', 'meta' => ['pages' => [['id' => 'PAGE1', 'name' => 'AK Page']]]]);
        $t = new PlatformToken(['platform_account_id' => $account->id, 'expires_at' => now()->addDays(30)]);
        $t->setAccessToken('user-token');
        $t->save();
        $d = StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'platform' => 'facebook', 'platform_account_id' => $account->id, 'options' => ['page_id' => 'PAGE1', 'title' => 'Hello']]);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $session = StreamSession::create(['tenant_id' => $tenant->id, 'stream_endpoint_id' => $endpoint->id, 'status' => 'detected', 'started_at' => now()]);
        $sd = StreamSessionDestination::create(['tenant_id' => $tenant->id, 'stream_session_id' => $session->id, 'stream_destination_id' => $d->id, 'status' => 'preparing']);

        $connector = app(ConnectorRegistry::class)->for($d);
        $this->assertTrue($connector->validate()['ok']);
        $connector->startStream($sd);
        $sd->refresh();
        $this->assertSame('LV1', $sd->platform_meta['live_video_id']);
        $this->assertSame('rtmps://live-api-s.facebook.com:443/rtmp/fb-secret', $connector->rtmpTarget($sd));
        $this->assertStringNotContainsString('fb-secret', json_encode(array_diff_key($sd->platform_meta, ['_secret' => 1, '_page_token' => 1])));
        $connector->stopStream($sd);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/LV1') && $r['end_live_video'] === 'true');
    }

    public function test_destination_form_crud(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->get('/admin/destinations/create?platform=custom_rtmp')->assertOk()->assertSee('RTMP URL');
        $this->post('/admin/destinations', ['platform' => 'custom_rtmp', 'name' => 'Kick', 'rtmp_url' => 'rtmps://fa723fc1b171.global-contribute.live-video.net/app', 'stream_key' => 'sk_abc', 'is_enabled' => 1])->assertRedirect('/admin/destinations');
        $d = StreamDestination::withoutGlobalScopes()->first();
        $this->assertSame('sk_abc', $d->streamKey());
        $this->assertSame('pass', $d->last_test_result);
        $this->get('/admin/destinations')->assertOk()->assertSee('Kick')->assertDontSee('sk_abc');
        $this->put('/admin/destinations/'.$d->id, ['platform' => 'custom_rtmp', 'name' => 'Kick2', 'rtmp_url' => $d->rtmp_url])->assertRedirect();
        $this->assertSame('sk_abc', $d->fresh()->streamKey(), 'blank key keeps existing secret');
        $this->assertSame('Kick2', $d->fresh()->name);
        $this->post('/admin/destinations/'.$d->id.'/toggle')->assertRedirect();
        $this->assertFalse($d->fresh()->is_enabled);
        $this->delete('/admin/destinations/'.$d->id)->assertRedirect();
        $this->assertSoftDeleted('stream_destinations', ['id' => $d->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'destination.deleted']);
    }
}
