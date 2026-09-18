<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Destinations\ConnectorRegistry;
use App\Domain\Destinations\DestinationReachability;
use App\Jobs\TestDestinationJob;
use App\Models\StreamDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FFmpeg reports a blocked firewall, a bad TLS chain and a rejected stream key
 * identically. These pin the distinction the operator needs.
 */
class DestinationReachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hostname_that_does_not_exist_is_named_as_a_dns_problem(): void
    {
        $result = app(DestinationReachability::class)->check('rtmp://no-such-host.invalid/live');

        $this->assertFalse($result['ok']);
        $this->assertSame('dns', $result['stage']);
        $this->assertStringContainsString('does not resolve', $result['message']);
    }

    public function test_a_closed_port_is_named_as_a_firewall_problem(): void
    {
        // Nothing listens here, which is what a blocked outbound port looks like.
        $result = app(DestinationReachability::class)->check('rtmp://127.0.0.1:9/live');

        $this->assertFalse($result['ok']);
        $this->assertSame('tcp', $result['stage']);
        $this->assertStringContainsString('port 9', $result['message']);
        $this->assertStringContainsString('outbound firewall', $result['message']);
    }

    public function test_the_default_port_follows_the_scheme(): void
    {
        $plain = app(DestinationReachability::class)->check('rtmp://no-such-host.invalid/live');
        $secure = app(DestinationReachability::class)->check('rtmps://no-such-host.invalid/rtmp');

        $this->assertSame(1935, $plain['port']);
        $this->assertFalse($plain['tls']);
        $this->assertSame(443, $secure['port']);
        $this->assertTrue($secure['tls']);
    }

    public function test_an_explicit_port_wins(): void
    {
        $result = app(DestinationReachability::class)->check('rtmps://live-api-s.facebook.com:443/rtmp');

        $this->assertSame(443, $result['port']);
        $this->assertSame('live-api-s.facebook.com', $result['host']);
    }

    public function test_a_reachable_host_points_at_the_stream_key_instead(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($server === false) {
            $this->markTestSkipped('cannot open a local listening socket here');
        }
        $port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];

        $result = app(DestinationReachability::class)->check('rtmp://127.0.0.1:'.$port.'/live');
        fclose($server);

        $this->assertTrue($result['ok']);
        $this->assertSame('reachable', $result['stage']);
        // When the network is fine, the remaining explanation is the key — say so.
        $this->assertStringContainsString('rejecting the stream key', $result['message']);
    }

    public function test_testing_a_destination_now_fails_when_it_cannot_be_reached(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);

        $this->post('/admin/destinations', [
            'platform' => 'custom_rtmp', 'name' => 'Unreachable',
            'p' => ['custom_rtmp' => ['rtmp_url' => 'rtmp://no-such-host.invalid/live', 'stream_key' => 'k']],
        ])->assertSessionHasNoErrors();

        $destination = StreamDestination::withoutGlobalScopes()->firstOrFail();
        (new TestDestinationJob($destination->id))->handle(app(ConnectorRegistry::class));

        $destination->refresh();
        $this->assertSame('fail', $destination->last_test_result, 'a shape check alone used to pass this');
        $this->assertStringContainsString('does not resolve', (string) $destination->last_error);
    }
}
