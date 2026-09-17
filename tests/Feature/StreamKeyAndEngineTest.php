<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamKeyAndEngineTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'engine-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['akstream.streaming.engine_secret' => $this->secret, 'akstream.streaming.engine' => 'none']);
    }

    public function test_admin_can_create_regenerate_reveal_revoke_key(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $r = $this->post('/admin/stream-keys', ['name' => 'Hotel Live', 'slug' => 'AKDWK-HOTEL-001']);
        $endpoint = StreamEndpoint::withoutGlobalScopes()->first();
        $r->assertRedirect('/admin/obs-setup/'.$endpoint->id)->assertSessionHas('revealed_key');
        $this->assertSame('AKDWK-HOTEL-001', $endpoint->slug);

        $this->get('/admin/obs-setup/'.$endpoint->id)->assertOk()->assertSee('Copy Server')->assertSee($endpoint->plainKey()); // shown once after creation
        $this->get('/admin/obs-setup/'.$endpoint->id)->assertOk()->assertDontSee($endpoint->plainKey());

        $rev = $this->postJson('/admin/stream-keys/'.$endpoint->id.'/reveal')->assertOk();
        $this->assertSame($endpoint->plainKey(), $rev->json('key'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'stream_key.revealed']);

        $old = $endpoint->plainKey();
        $this->post('/admin/stream-keys/'.$endpoint->id.'/regenerate')->assertRedirect();
        $this->assertNotSame($old, $endpoint->fresh()->plainKey());
        $this->assertDatabaseHas('activity_logs', ['action' => 'stream_key.regenerated']);

        $this->post('/admin/stream-keys/'.$endpoint->id.'/revoke')->assertRedirect();
        $this->assertFalse($endpoint->fresh()->isUsable());
    }

    public function test_engine_auth_hook_accepts_valid_key_and_rejects_revoked(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $key = $endpoint->plainKey();
        $h = ['X-Engine-Secret' => $this->secret];

        $this->postJson('/api/internal/engine/auth', ['action' => 'publish', 'path' => 'live/'.$key, 'ip' => '1.2.3.4'], $h)->assertOk();
        $this->postJson('/api/internal/engine/auth', ['action' => 'publish', 'path' => 'live/WRONG'], $h)->assertStatus(401);
        $this->postJson('/api/internal/engine/auth', ['action' => 'read', 'path' => 'live/'.$key, 'ip' => '127.0.0.1'], $h)->assertOk();
        $this->postJson('/api/internal/engine/auth', ['action' => 'read', 'path' => 'live/'.$key, 'ip' => '8.8.8.8'], $h)->assertStatus(401);

        $endpoint->forceFill(['is_enabled' => false])->save();
        $this->postJson('/api/internal/engine/auth', ['action' => 'publish', 'path' => 'live/'.$key], $h)->assertStatus(401);
    }

    public function test_ready_and_not_ready_hooks_create_and_end_sessions(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Dwarka']);
        $key = $endpoint->plainKey();
        $h = ['X-Engine-Secret' => $this->secret];

        $this->postJson('/api/internal/engine/ready', ['path' => 'live/'.$key], $h)->assertOk()->assertJson(['ok' => true]);
        $session = StreamSession::withoutGlobalScopes()->first();
        $this->assertNotNull($session);
        $this->assertSame('detected', $session->status);
        $this->assertSame('live', $endpoint->fresh()->status);
        $this->assertDatabaseHas('stream_destination_logs', ['event' => 'stream.detected']);

        // idempotent
        $this->postJson('/api/internal/engine/ready', ['path' => 'live/'.$key], $h)->assertOk();
        $this->assertSame(1, StreamSession::withoutGlobalScopes()->count());

        $this->postJson('/api/internal/engine/not-ready', ['path' => 'live/'.$key], $h)->assertOk();
        $this->assertSame('ended', $session->fresh()->status);
        $this->assertSame('offline', $endpoint->fresh()->status);
        $this->assertNotNull($session->fresh()->ended_at);

        // Stream test page + dashboard status endpoint
        $this->actingAs($user)->get('/admin/stream-test')->assertOk()->assertSee('Waiting for incoming stream');
        $this->actingAs($user)->getJson('/admin/dashboard/status')->assertOk()->assertJson(['live' => false]);
    }
}
