<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Models\StreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command itself publishes a real broadcast, which needs a media server. These cover
 * the paths that must behave without one: refusing clearly, and never leaking the key.
 */
class StreamSelfTestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['akstream.streaming.engine' => 'none']);
        app()->forgetInstance(StreamEngineInterface::class);
    }

    public function test_it_says_what_to_do_when_there_is_no_stream_key(): void
    {
        $this->seedSystem();

        $this->artisan('stream:selftest', ['--seconds' => 5])
            ->expectsOutputToContain('No enabled stream key found')
            ->assertFailed();
    }

    public function test_it_reports_each_check_and_never_prints_the_stream_key(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $plain = $endpoint->plainKey();

        // With the null engine nothing can actually publish, so this must fail — cleanly.
        config(['akstream.streaming.ffmpeg' => '/nonexistent/ffmpeg']);

        $this->artisan('stream:selftest', ['--seconds' => 5])
            ->expectsOutputToContain('Stream key: Studio (key hidden)')
            ->expectsOutputToContain('PHP can run FFmpeg')
            ->doesntExpectOutputToContain($plain)
            ->assertFailed();
    }

    public function test_a_named_key_that_does_not_exist_is_refused(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        StreamEndpoint::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);

        $this->artisan('stream:selftest', ['--key' => 'no-such-key'])
            ->expectsOutputToContain('matching "no-such-key"')
            ->assertFailed();
    }
}
