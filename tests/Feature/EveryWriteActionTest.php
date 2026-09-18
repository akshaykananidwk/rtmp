<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\StreamSessionService;
use App\Models\ErrorLog;
use App\Models\Overlay;
use App\Models\Role;
use App\Models\ScheduledStream;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSessionDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises every form the panel offers, as a super admin, and checks nothing 5xx's and
 * nothing lands in the error log. Companion to EveryPageTest: that one opens the pages,
 * this one presses the buttons.
 */
class EveryWriteActionTest extends TestCase
{
    use RefreshDatabase;

    private $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'akstream.streaming.engine' => 'none',
            'akstream.streaming.ffmpeg' => base_path('tests/Fixtures/fake-ffmpeg.sh'),
        ]);
        [$this->tenant, $this->user] = $this->adminSetup();
        $this->actingAs($this->user);
        $this->actAsTenant($this->tenant);
    }

    private function ok(string $method, string $uri, array $data = []): void
    {
        $response = $this->call($method, $uri, $data);
        $this->assertLessThan(500, $response->getStatusCode(), strtoupper($method).' '.$uri.' returned '.$response->getStatusCode());
    }

    public function test_stream_key_actions(): void
    {
        $this->ok('post', '/admin/stream-keys', ['name' => 'Studio key']);
        $key = StreamEndpoint::withoutGlobalScopes()->firstOrFail();

        $this->ok('put', '/admin/stream-keys/'.$key->id, ['name' => 'Studio key renamed']);
        $this->ok('post', '/admin/stream-keys/'.$key->id.'/toggle');
        $this->ok('post', '/admin/stream-keys/'.$key->id.'/toggle');
        $this->ok('post', '/admin/stream-keys/'.$key->id.'/reveal');
        $this->ok('post', '/admin/stream-keys/'.$key->id.'/regenerate');
        $this->ok('post', '/admin/stream-keys/'.$key->id.'/revoke');
        $this->ok('delete', '/admin/stream-keys/'.$key->id);

        $this->assertNoErrorsLogged();
    }

    public function test_destination_and_overlay_actions(): void
    {
        $this->ok('post', '/admin/destinations', [
            'platform' => 'custom_rtmp', 'name' => 'Relay',
            'p' => ['custom_rtmp' => ['rtmp_url' => 'rtmp://a.example.com/live', 'stream_key' => 'k']],
        ]);
        $destination = StreamDestination::withoutGlobalScopes()->firstOrFail();
        $this->ok('post', '/admin/destinations/'.$destination->id.'/toggle');
        $this->ok('post', '/admin/destinations/'.$destination->id.'/test');

        $this->ok('post', '/admin/overlays', ['name' => 'Bar', 'resolution' => '1920x1080', 'bitrate_kbps' => 4500, 'fps' => 30, 'preset' => 'veryfast']);
        $overlay = Overlay::withoutGlobalScopes()->firstOrFail();
        $key = StreamEndpoint::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->ok('put', '/admin/overlays/'.$overlay->id, ['name' => 'Bar 2', 'resolution' => '1280x720', 'bitrate_kbps' => 2500, 'fps' => 30, 'preset' => 'ultrafast']);
        $this->ok('post', '/admin/overlays/assign', ['overlay_id' => $overlay->id, 'stream_endpoint_id' => $key->id]);
        $this->ok('delete', '/admin/overlays/'.$overlay->id);
        $this->ok('delete', '/admin/destinations/'.$destination->id);

        $this->assertNoErrorsLogged();
    }

    public function test_schedule_actions(): void
    {
        $key = StreamEndpoint::factory()->create(['tenant_id' => $this->tenant->id]);
        $payload = [
            'title' => 'Evening show',
            'date' => now()->addDay()->format('Y-m-d'),
            'time' => '19:30',
            'stream_endpoint_id' => $key->id,
            'timezone' => 'Asia/Kolkata',
        ];

        $this->ok('post', '/admin/schedules', $payload);
        $schedule = ScheduledStream::withoutGlobalScopes()->firstOrFail();
        $this->ok('put', '/admin/schedules/'.$schedule->id, array_merge($payload, ['title' => 'Evening show 2']));
        $this->ok('post', '/admin/schedules/'.$schedule->id.'/cancel');
        $this->ok('delete', '/admin/schedules/'.$schedule->id);

        $this->assertNoErrorsLogged();
    }

    public function test_live_control_actions(): void
    {
        $key = StreamEndpoint::factory()->create(['tenant_id' => $this->tenant->id]);
        StreamDestination::factory()->create(['tenant_id' => $this->tenant->id]);
        $session = app(StreamSessionService::class)->onSourceReady($key);
        app(DistributionService::class)->start($session, null, $this->user);
        $sd = StreamSessionDestination::withoutGlobalScopes()->firstOrFail();

        $this->ok('post', '/admin/live/start');
        $this->ok('post', '/admin/live/destination/'.$sd->id.'/restart');
        $this->ok('post', '/admin/live/destination/'.$sd->id.'/stop');
        $this->ok('post', '/admin/live/stop');

        $this->assertNoErrorsLogged();
    }

    public function test_user_and_profile_actions(): void
    {
        $this->ok('post', '/admin/users', [
            'name' => 'Operator One', 'email' => 'op1@example.com',
            'password' => 'Tr0ub4dor&3-AK-9x', 'password_confirmation' => 'Tr0ub4dor&3-AK-9x',
            'role' => Role::OPERATOR, 'timezone' => 'Asia/Kolkata', 'is_active' => '1',
        ]);
        $created = User::withoutGlobalScopes()->where('email', 'op1@example.com')->firstOrFail();
        $this->ok('put', '/admin/users/'.$created->id, ['name' => 'Operator Uno', 'email' => 'op1@example.com', 'role' => Role::VIEWER, 'timezone' => 'Asia/Kolkata', 'is_active' => '1']);
        $this->ok('delete', '/admin/users/'.$created->id);

        $this->ok('put', '/admin/profile', ['name' => 'Akshay K', 'email' => $this->user->email, 'timezone' => 'Asia/Kolkata']);
        $this->ok('put', '/admin/profile/password', ['current_password' => 'password', 'password' => 'N3w!Passw0rd-AK-7z', 'password_confirmation' => 'N3w!Passw0rd-AK-7z']);
        $this->ok('post', '/admin/profile/api-token', ['name' => 'CLI token']);
        $this->ok('post', '/admin/notifications/read');

        $this->assertNoErrorsLogged();
    }

    public function test_settings_save_for_every_group(): void
    {
        $groups = [
            'general' => ['app_name' => 'AK COMPUTER', 'timezone' => 'Asia/Kolkata', 'contact_email' => 'a@example.com'],
            'streaming' => ['rtmp_host' => 'rtmp://stream.example.com/live', 'default_bitrate' => 4500, 'default_resolution' => '1920x1080', 'retry_count' => 5],
            'registration' => ['open' => '1', 'default_plan' => 'free', 'max_stream_keys' => 3, 'max_destinations' => 10],
            'recording' => ['format' => 'mp4', 'resolution' => 'source', 'retention_days' => 30, 'max_size_mb' => 4096],
            'storage' => ['driver' => 'local', 'retention_days' => 30],
            'mail' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'a@example.com', 'from_name' => 'AK'],
            'security' => ['session_timeout' => 120, 'login_max_attempts' => 5],
            'platforms' => [],
            'notifications' => [],
            'backups' => ['frequency' => 'daily', 'retention_days' => 14],
        ];

        foreach ($groups as $group => $payload) {
            $this->ok('post', '/admin/settings/'.$group, $payload);
        }

        $this->assertNoErrorsLogged();
    }

    public function test_maintenance_actions(): void
    {
        $this->ok('post', '/admin/health/run');
        $this->ok('post', '/admin/updates/check');
        $this->ok('post', '/admin/updates/protected-paths', ['path' => 'storage/custom']);
        $this->ok('post', '/admin/updates/settings', ['auto_check' => '1']);

        $this->assertNoErrorsLogged();
    }

    private function assertNoErrorsLogged(): void
    {
        $logged = ErrorLog::withoutGlobalScopes()->get();

        $this->assertCount(0, $logged, 'actions logged errors: '.$logged->pluck('message')->implode(' | '));
    }
}
