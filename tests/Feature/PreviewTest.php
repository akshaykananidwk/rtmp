<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Overlay;
use App\Models\Role;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['akstream.streaming.engine' => 'none', 'akstream.streaming.hls_url' => 'http://127.0.0.1:8888']);
    }

    public function test_status_reports_whether_anything_is_publishing(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson(route('admin.preview.status', $endpoint))->assertOk()->assertJson(['live' => false, 'branded' => false]);

        StreamSession::create(['tenant_id' => $tenant->id, 'stream_endpoint_id' => $endpoint->id, 'status' => 'live', 'started_at' => now(), 'resolution' => '1920x1080', 'fps' => 30]);

        $this->actingAs($user)->getJson(route('admin.preview.status', $endpoint))->assertOk()
            ->assertJson(['live' => true, 'branded' => false, 'resolution' => '1920x1080'])
            ->assertJsonPath('playlist', route('admin.preview.playlist', $endpoint));
    }

    public function test_playlist_is_proxied_and_segment_urls_are_rewritten(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);

        Http::fake(['127.0.0.1:8888/*' => Http::response("#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:1.0,\nseg0.ts\n#EXTINF:1.0,\nseg1.ts\n")]);

        $response = $this->actingAs($user)->get(route('admin.preview.playlist', $endpoint));
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');

        $body = $response->getContent();
        $this->assertStringContainsString('#EXTM3U', $body);
        $this->assertStringContainsString(route('admin.preview.segment', ['endpoint' => $endpoint->id, 'file' => 'seg0.ts']), $body);
        // The stream key must never reach the browser
        $this->assertStringNotContainsString($endpoint->plainKey(), $body);
    }

    public function test_preview_requires_authentication_and_tenant_ownership(): void
    {
        $this->seedSystem();
        $a = $this->makeTenant('a');
        $b = $this->makeTenant('b');
        $userB = $this->makeUser($b, Role::OPERATOR);
        $endpointA = StreamEndpoint::factory()->create(['tenant_id' => $a->id]);

        $this->get(route('admin.preview.playlist', $endpointA))->assertRedirect('/login');
        $this->actingAs($userB)->get(route('admin.preview.playlist', $endpointA))->assertNotFound();
        $this->actingAs($userB)->getJson(route('admin.preview.status', $endpointA))->assertNotFound();
    }

    public function test_segment_names_are_validated(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['..%2F..%2Fetc%2Fpasswd', 'seg%20with%20space.ts'] as $bad) {
            $this->actingAs($user)->get('/admin/preview/'.$endpoint->id.'/'.$bad)->assertNotFound();
        }
    }

    public function test_branded_stream_is_previewed_when_an_overlay_is_live(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $overlay = Overlay::factory()->create(['tenant_id' => $tenant->id, 'name' => 'News style']);
        StreamSession::create(['tenant_id' => $tenant->id, 'stream_endpoint_id' => $endpoint->id, 'status' => 'live', 'started_at' => now(), 'overlay_id' => $overlay->id, 'branding_status' => 'live']);

        $this->actingAs($user)->getJson(route('admin.preview.status', $endpoint))->assertOk()
            ->assertJson(['live' => true, 'branded' => true, 'overlay' => 'News style'])
            ->assertJsonPath('raw_playlist', route('admin.preview.playlist', ['endpoint' => $endpoint->id, 'source' => 'raw']));

        Http::fake(['127.0.0.1:8888/*' => Http::response("#EXTM3U\nseg0.ts\n")]);
        $this->actingAs($user)->get(route('admin.preview.playlist', $endpoint))->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'branded/'));
    }
}
