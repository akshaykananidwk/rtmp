<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Streaming\StreamKeyService;
use App\Models\StreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_is_hashed_encrypted_and_resolvable(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $svc = app(StreamKeyService::class);
        $endpoint = $svc->create($tenant, $user, 'Temple Live', 'AKDWK-TEMPLE-001');

        $plain = $endpoint->plainKey();
        $this->assertStringStartsWith('AKDWK-', $plain);
        $this->assertSame(StreamKeyService::hash($plain), $endpoint->key_hash);
        $this->assertStringNotContainsString($plain, $endpoint->key_encrypted);
        $this->assertSame('AKDWK-TEMPLE-001', $endpoint->slug);
        $this->assertSame($endpoint->id, $svc->findByKey($plain)?->id);
        $this->assertNull($svc->findByKey('wrong'));

        $old = $plain;
        $new = $svc->regenerate($endpoint);
        $this->assertNotSame($old, $new);
        $this->assertNull($svc->findByKey($old));
        $this->assertSame($endpoint->id, $svc->findByKey($new)?->id);

        $svc->revoke($endpoint);
        $this->assertFalse($endpoint->fresh()->isUsable());
        $this->assertArrayNotHasKey('key_encrypted', $endpoint->toArray());
    }

    public function test_slug_uniqueness_per_tenant(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $svc = app(StreamKeyService::class);
        $a = $svc->create($tenant, $user, 'Event', 'AKDWK-EVENT-001');
        $b = $svc->create($tenant, $user, 'Event', 'AKDWK-EVENT-001');
        $this->assertNotSame($a->slug, $b->slug);
        $this->assertSame(2, StreamEndpoint::count());
    }
}
