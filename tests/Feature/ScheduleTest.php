<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Scheduling\ScheduleService;
use App\Domain\Streaming\StreamSessionService;
use App\Models\ScheduledStream;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['akstream.streaming.engine' => 'none']);
    }

    public function test_schedule_crud_and_auto_start_stop(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $d = StreamDestination::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->post('/admin/schedules', [
            'title' => 'Dwarka Live Event', 'stream_endpoint_id' => $endpoint->id, 'date' => '2026-09-18', 'time' => '19:00', 'timezone' => 'Asia/Kolkata',
            'destination_ids' => [$d->id], 'auto_start' => 1, 'auto_stop' => 1, 'auto_stop_time' => '22:00', 'recording_enabled' => 1,
        ])->assertRedirect('/admin/schedules');

        $s = ScheduledStream::first();
        $this->assertSame('2026-09-18 13:30:00', $s->scheduled_at->toDateTimeString()); // 19:00 IST = 13:30 UTC
        $this->assertSame('2026-09-18 16:30:00', $s->auto_stop_at->toDateTimeString());
        $this->assertSame([$d->id], $s->destinations()->pluck('stream_destinations.id')->all());
        $this->actingAs($user)->get('/admin/schedules')->assertOk()->assertSee('Dwarka Live Event');

        // Not yet due
        $this->travelTo('2026-09-18 13:00:00');
        $r = app(ScheduleService::class)->runDue();
        $this->assertSame(0, $r['started']);

        // Due but source not publishing → waiting
        $this->travelTo('2026-09-18 13:31:00');
        app(ScheduleService::class)->runDue();
        $this->assertSame('waiting_for_source', $s->fresh()->status);

        // Source arrives → auto start
        $session = app(StreamSessionService::class)->onSourceReady($endpoint);
        $r = app(ScheduleService::class)->runDue();
        $this->assertSame(1, $r['started']);
        $s->refresh();
        $this->assertSame('live', $s->status);
        $this->assertSame($session->id, $s->stream_session_id);
        $this->assertSame('Dwarka Live Event', $session->fresh()->title);
        $this->assertTrue($session->fresh()->recording_enabled);
        $this->assertSame(1, $session->destinations()->count());

        // Auto stop
        $this->travelTo('2026-09-18 16:31:00');
        $r = app(ScheduleService::class)->runDue();
        $this->assertSame(1, $r['stopped']);
        $this->assertSame('completed', $s->fresh()->status);
        $this->assertSame('ended', $session->fresh()->status);
    }

    public function test_missed_schedule_when_no_source(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        $s = ScheduledStream::create(['tenant_id' => $tenant->id, 'stream_endpoint_id' => $endpoint->id, 'title' => 'x', 'scheduled_at' => now()->subMinutes(31), 'timezone' => 'UTC', 'auto_start' => true]);
        $r = app(ScheduleService::class)->runDue();
        $this->assertSame(1, $r['missed']);
        $this->assertSame('missed', $s->fresh()->status);
    }
}
