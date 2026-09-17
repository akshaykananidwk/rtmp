<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Tenancy\TenantContext;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** IDOR / cross-tenant access must always fail. */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_b_cannot_see_or_edit_tenant_a_resources(): void
    {
        $this->seedSystem();
        $a = $this->makeTenant('a');
        $b = $this->makeTenant('b');
        $userA = $this->makeUser($a);
        $userB = $this->makeUser($b);

        $destA = StreamDestination::factory()->create(['tenant_id' => $a->id, 'name' => 'Tenant A YouTube']);
        $keyA = StreamEndpoint::factory()->create(['tenant_id' => $a->id, 'name' => 'Tenant A Key']);

        $this->actingAs($userB);
        $this->get('/admin/destinations')->assertOk()->assertDontSee('Tenant A YouTube');
        $this->get('/admin/destinations/'.$destA->id.'/edit')->assertNotFound();
        $this->put('/admin/destinations/'.$destA->id, ['platform' => 'custom_rtmp', 'name' => 'hacked', 'rtmp_url' => 'rtmp://x/y', 'stream_key' => 'k'])->assertNotFound();
        $this->delete('/admin/destinations/'.$destA->id)->assertNotFound();
        $this->post('/admin/destinations/'.$destA->id.'/test')->assertNotFound();
        $this->get('/admin/stream-keys/'.$keyA->id.'/edit')->assertNotFound();
        $this->post('/admin/stream-keys/'.$keyA->id.'/reveal')->assertNotFound();
        $this->post('/admin/stream-keys/'.$keyA->id.'/regenerate')->assertNotFound();
        $this->getJson('/api/v1/destinations/'.$destA->id, ['Authorization' => 'Bearer '.$userB->createToken('t', ['read'])->plainTextToken])->assertNotFound();

        $this->assertSame('Tenant A YouTube', $destA->fresh()->name);
        $this->assertNull($destA->fresh()->deleted_at);
    }

    public function test_global_scope_fails_closed_without_tenant(): void
    {
        $this->seedSystem();
        $a = $this->makeTenant('a');
        StreamDestination::factory()->create(['tenant_id' => $a->id]);
        app(TenantContext::class)->clear();
        $this->assertSame(0, StreamDestination::count());
        $this->assertSame(1, StreamDestination::withoutGlobalScopes()->count());
        app(TenantContext::class)->set($a);
        $this->assertSame(1, StreamDestination::count());
    }

    public function test_cannot_bind_destination_to_other_tenants_endpoint(): void
    {
        $this->seedSystem();
        $a = $this->makeTenant('a');
        $b = $this->makeTenant('b');
        $userB = $this->makeUser($b);
        $keyA = StreamEndpoint::factory()->create(['tenant_id' => $a->id]);
        $this->actingAs($userB)->post('/admin/destinations', ['platform' => 'custom_rtmp', 'name' => 'x', 'rtmp_url' => 'rtmp://h/app', 'stream_key' => 'k', 'stream_endpoint_id' => $keyA->id])->assertSessionHasErrors('stream_endpoint_id');
    }
}
