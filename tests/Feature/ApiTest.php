<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_token_and_protected_endpoints(): void
    {
        $this->pretendDestinationsAreReachable();
        [$tenant, $user] = $this->adminSetup();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(401);
        $r = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Str0ng!Password#2026', 'device_name' => 'test'])->assertOk();
        $token = $r->json('token');
        $this->assertContains('manage', $r->json('abilities'));

        $h = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/auth/me', $h)->assertOk()->assertJsonPath('email', $user->email);
        $this->getJson('/api/v1/dashboard', $h)->assertOk()->assertJsonPath('data.live', false);
        $this->getJson('/api/v1/stream-status', $h)->assertOk();
        $this->getJson('/api/v1/analytics', $h)->assertOk()->assertJsonStructure(['data' => ['total_streams', 'daily']]);
        $this->getJson('/api/v1/streams', $h)->assertOk();
        $this->getJson('/api/v1/stream-keys', $h)->assertOk();
        $this->getJson('/api/v1/system/health', $h)->assertOk()->assertJsonStructure(['critical_ok', 'results']);

        $c = $this->postJson('/api/v1/destinations', ['platform' => 'custom_rtmp', 'name' => 'API dest', 'rtmp_url' => 'rtmp://x.example.com/app', 'stream_key' => 'sekrit'], $h)->assertCreated();
        $id = $c->json('data.id');
        $this->assertStringNotContainsString('sekrit', $c->getContent());
        $this->getJson('/api/v1/destinations/'.$id, $h)->assertOk()->assertJsonPath('data.name', 'API dest');
        $this->putJson('/api/v1/destinations/'.$id, ['platform' => 'custom_rtmp', 'name' => 'Renamed', 'rtmp_url' => 'rtmp://x.example.com/app'], $h)->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->postJson('/api/v1/destinations/'.$id.'/test', [], $h)->assertOk()->assertJsonPath('result', 'pass');
        $this->postJson('/api/v1/streams/start', [], $h)->assertStatus(409);
        $this->deleteJson('/api/v1/destinations/'.$id, [], $h)->assertOk();
        $this->assertSoftDeleted('stream_destinations', ['id' => $id]);

        $k = $this->postJson('/api/v1/stream-keys', ['name' => 'API key'], $h)->assertCreated();
        $this->assertStringStartsWith('AKDWK-', $k->json('data.stream_key'));

        $this->postJson('/api/v1/auth/logout', [], $h)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $h)->assertStatus(401);
    }

    public function test_viewer_token_cannot_control_or_manage(): void
    {
        [$tenant, $viewer] = $this->adminSetup(Role::VIEWER);
        $token = $viewer->createToken('t', ['read'])->plainTextToken;
        $h = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/dashboard', $h)->assertOk();
        $this->postJson('/api/v1/streams/start', [], $h)->assertForbidden();
        $this->postJson('/api/v1/destinations', ['platform' => 'custom_rtmp', 'name' => 'x', 'rtmp_url' => 'rtmp://a/b', 'stream_key' => 'k'], $h)->assertForbidden();
        $this->getJson('/api/v1/updates/check', $h)->assertForbidden();
    }

    public function test_operator_token_without_control_ability_is_blocked(): void
    {
        [$tenant, $op] = $this->adminSetup(Role::OPERATOR);
        $token = $op->createToken('t', ['read'])->plainTextToken;
        $this->postJson('/api/v1/streams/stop', [], ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }
}
