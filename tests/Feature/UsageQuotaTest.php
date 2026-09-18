<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\UsageService;
use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\StreamSessionService;
use App\Models\Role;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function endedSession($tenant, int $minutes, ?\DateTimeInterface $startedAt = null): StreamSession
    {
        $startedAt ??= now()->subHour();

        return StreamSession::create([
            'tenant_id' => $tenant->id,
            'stream_endpoint_id' => StreamEndpoint::factory()->create(['tenant_id' => $tenant->id])->id,
            'status' => 'ended',
            'started_at' => $startedAt,
            'ended_at' => (clone $startedAt)->modify('+'.$minutes.' minutes'),
            'duration_seconds' => $minutes * 60,
            'bytes_sent' => 1024 * 1024,
        ]);
    }

    public function test_minutes_are_counted_for_this_month_only(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);

        $this->endedSession($tenant, 30);
        $this->endedSession($tenant, 15);
        // Last month does not count against this month's allowance.
        $this->endedSession($tenant, 500, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $usage = app(UsageService::class);
        $this->assertSame(45, $usage->minutesUsed());
        $this->assertSame(2, $usage->summary()['streams']);
    }

    public function test_a_running_stream_counts_as_it_goes(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);

        StreamSession::create([
            'tenant_id' => $tenant->id,
            'stream_endpoint_id' => StreamEndpoint::factory()->create(['tenant_id' => $tenant->id])->id,
            'status' => 'live',
            'started_at' => now()->subMinutes(20),
        ]);

        // Otherwise one endless stream would never be measured until it stopped.
        $this->assertSame(20, app(UsageService::class)->minutesUsed());
    }

    public function test_going_live_is_refused_once_the_allowance_is_gone(): void
    {
        [$tenant, $user] = $this->adminSetup(Role::ACCOUNT_OWNER);
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        app(SettingsService::class)->set('registration', UsageService::MONTHLY_MINUTES, 60);

        $this->endedSession($tenant, 61);

        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        app(StreamSessionService::class)->onSourceReady($endpoint);

        $this->post('/admin/live/start')->assertRedirect();
        $this->assertSame(0, StreamSessionDestination::withoutGlobalScopes()->count(), 'nothing should have been started');
        $this->assertStringContainsString('streaming minutes', (string) session('error'));
    }

    public function test_the_allowance_does_not_apply_to_the_server_owner_or_when_unset(): void
    {
        [$tenant, $owner] = $this->adminSetup(Role::SUPER_ADMIN);
        $this->actingAs($owner);
        $this->actAsTenant($tenant);
        app(SettingsService::class)->set('registration', UsageService::MONTHLY_MINUTES, 10);
        $this->endedSession($tenant, 99);

        $usage = app(UsageService::class);
        $this->assertFalse($usage->exceeded($owner), 'whoever runs the server is not billed by it');

        app(SettingsService::class)->set('registration', UsageService::MONTHLY_MINUTES, 0);
        $this->assertFalse($usage->exceeded(null), '0 means unlimited');
        $this->assertNull($usage->summary()['minutes_left']);
    }

    public function test_the_api_cannot_be_used_to_step_around_the_allowance(): void
    {
        [$tenant, $user] = $this->adminSetup(Role::ACCOUNT_OWNER);
        $this->actAsTenant($tenant);
        app(SettingsService::class)->set('registration', UsageService::MONTHLY_MINUTES, 30);
        $this->endedSession($tenant, 45);

        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        app(StreamSessionService::class)->onSourceReady($endpoint);

        $token = $user->createToken('cli', ['control'])->plainTextToken;
        $this->postJson('/api/v1/streams/start', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(402);

        $this->assertSame(0, StreamSessionDestination::withoutGlobalScopes()->count());
    }

    public function test_usage_is_per_account(): void
    {
        [$tenant] = $this->adminSetup();
        $other = $this->makeTenant('other');
        $this->endedSession($other, 120);

        $this->actAsTenant($tenant);
        $this->assertSame(0, app(UsageService::class)->minutesUsed(), 'another account\'s streaming is not ours');
    }
}
