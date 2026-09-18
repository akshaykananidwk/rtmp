<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Health\Checks\SupervisorCheck;
use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\StreamSessionService;
use App\Domain\Streaming\SupervisorStatus;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSessionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SupervisorStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_beat_means_running_and_an_old_one_does_not(): void
    {
        $status = app(SupervisorStatus::class);

        $this->assertNull($status->lastBeatAt());
        $this->assertFalse($status->isRunning());
        $this->assertStringContainsString('never reported in', (string) $status->problem());

        SupervisorStatus::beat('media-1');
        $this->assertTrue($status->isRunning());
        $this->assertSame('media-1', $status->nodeId());
        $this->assertNull($status->problem(), 'a running supervisor has nothing to report');

        Cache::put(SupervisorStatus::SHARED_KEY, ['node' => 'media-1', 'at' => now()->timestamp - (SupervisorStatus::STALE_AFTER_SECONDS + 5)], 600);
        $this->assertFalse($status->isRunning());
        $this->assertStringContainsString('Nothing is being sent', (string) $status->problem());
    }

    public function test_the_supervisor_command_reports_in_after_a_pass(): void
    {
        $this->assertFalse(app(SupervisorStatus::class)->isRunning());

        $this->artisan('stream:supervisor', ['--once' => true])->assertSuccessful();

        $this->assertTrue(app(SupervisorStatus::class)->isRunning(), 'a completed pass records a heartbeat');
    }

    public function test_health_fails_when_destinations_wait_on_a_stopped_supervisor(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);

        $check = app(SupervisorCheck::class);
        $this->assertTrue($check->critical());

        // Nothing waiting: worth a warning, but it is not breaking anything yet.
        $this->assertSame('warn', $check->run()->status);

        // Go live for real: the web side records the desired state and leaves the
        // destinations at "pending" — exactly the state the operator was stuck in.
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        app(DistributionService::class)->start($session, null, $user);

        $this->assertSame(['pending'], StreamSessionDestination::withoutGlobalScopes()->pluck('status')->unique()->values()->all());

        $result = $check->run();
        $this->assertSame('fail', $result->status);
        $this->assertStringContainsString('1 destination(s) are waiting', $result->message);
        $this->assertStringContainsString('systemctl restart akstream-supervisor', $result->message);

        SupervisorStatus::beat('media-1');
        $this->assertSame('pass', $check->run()->status);
    }

    public function test_the_live_page_says_so_when_the_supervisor_is_down(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);

        $this->get('/admin/live')->assertOk()->assertSee('Nothing is being sent to your destinations.');
        $this->getJson('/admin/dashboard/status')->assertOk()->assertJsonPath('supervisor.ok', false);

        SupervisorStatus::beat('media-1');
        $this->getJson('/admin/dashboard/status')->assertOk()->assertJsonPath('supervisor.ok', true)->assertJsonPath('supervisor.problem', null);
    }

    public function test_the_supervisor_refuses_to_start_and_says_why_when_ffmpeg_is_missing(): void
    {
        config(['akstream.streaming.ffmpeg' => '/nonexistent/ffmpeg-'.uniqid()]);

        $this->artisan('stream:supervisor', ['--once' => true])->assertFailed();

        $status = app(SupervisorStatus::class);
        $this->assertFalse($status->isRunning(), 'a refused start records no heartbeat');
        $this->assertNotEmpty($status->blockers());
        $this->assertStringContainsString('FFmpeg', (string) $status->problem());
    }

    public function test_the_panel_shows_the_reason_the_supervisor_gave(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);

        SupervisorStatus::recordBlockers(['PHP cannot start FFmpeg: proc_open is disabled.']);

        $this->getJson('/admin/dashboard/status')->assertOk()
            ->assertJsonPath('supervisor.ok', false)
            ->assertJsonPath('supervisor.problem', 'PHP cannot start FFmpeg: proc_open is disabled.');

        $this->get('/admin/live')->assertOk()->assertSee('proc_open is disabled');

        $result = app(SupervisorCheck::class)->run();
        $this->assertStringContainsString('proc_open is disabled', $result->message);

        // A clean start clears it, so a fixed server stops nagging.
        SupervisorStatus::recordBlockers([]);
        $this->assertSame([], app(SupervisorStatus::class)->blockers());
    }
}
