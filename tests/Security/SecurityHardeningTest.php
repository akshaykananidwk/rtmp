<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\ErrorLog;
use App\Models\StreamDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_xss_payload_in_destination_name_is_escaped(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        StreamDestination::factory()->create(['tenant_id' => $tenant->id, 'name' => '<script>alert(1)</script>']);
        $r = $this->actingAs($user)->get('/admin/destinations');
        $r->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_sql_injection_in_login_does_not_bypass(): void
    {
        $this->adminSetup();
        $this->post('/login', ['email' => "' OR 1=1 --", 'password' => "' OR '1'='1"])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_csrf_is_enforced(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->app['env'] = 'production';
        $this->actingAs($user)->withMiddleware()->post('/admin/live/stop', [], ['HTTP_ACCEPT' => 'text/html'])->assertStatus(419);
        $this->app['env'] = 'testing';
    }

    public function test_path_traversal_in_protected_path_is_rejected(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user)->post('/admin/updates/protected-paths', ['path' => '../../etc'])->assertSessionHasErrors('path');
        $this->assertDatabaseMissing('update_protected_paths', ['path' => '../../etc']);
    }

    public function test_ssrf_repository_url_is_rejected(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user)->post('/admin/updates/settings', ['repository' => 'http://169.254.169.254/latest', 'branch' => 'main'])->assertSessionHasErrors('repository');
        $this->actingAs($user)->post('/admin/updates/settings', ['repository' => 'owner/repo', 'branch' => '../x'])->assertSessionHasErrors('branch');
    }

    public function test_invalid_rtmp_url_and_executable_upload_rejected(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user)->post('/admin/destinations', ['platform' => 'custom_rtmp', 'name' => 'x', 'rtmp_url' => 'javascript:alert(1)', 'stream_key' => 'k'])->assertSessionHasErrors('rtmp_url');
        $this->actingAs($user)->post('/admin/destinations', ['platform' => 'custom_rtmp', 'name' => 'x', 'rtmp_url' => 'http://evil/x', 'stream_key' => 'k'])->assertSessionHasErrors('rtmp_url');
        $file = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;');
        $this->actingAs($user)->post('/admin/settings/general', ['app_name' => 'AK', 'timezone' => 'UTC', 'logo' => $file])->assertSessionHasErrors('logo');
    }

    public function test_500_errors_show_reference_and_never_stack_trace(): void
    {
        [$tenant, $user] = $this->adminSetup();
        config(['app.debug' => false]);
        Route::get('/__boom', fn () => throw new \RuntimeException('secret token ghp_abcdefghijklmnopqrstuvwxyz1234567890 leaked'))->middleware('web');
        $r = $this->withoutExceptionHandling(false)->actingAs($user)->get('/__boom');
        $r->assertStatus(500)->assertSee('Reference ID')->assertSee('ERR-')->assertDontSee('RuntimeException')->assertDontSee('ghp_abcdef');
        $log = ErrorLog::first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('ghp_abcdef', $log->message);
    }

    public function test_installer_is_locked_after_install(): void
    {
        $lock = storage_path('app/installed.lock');
        $had = file_exists($lock);
        file_put_contents($lock, '{}');
        try {
            $this->get('/install')->assertNotFound();
            $this->get('/install/database')->assertNotFound();
        } finally {
            if (! $had) {
                unlink($lock);
            }
        }
    }

    public function test_engine_hooks_require_secret(): void
    {
        config(['akstream.streaming.engine_secret' => 'top-secret']);
        $this->postJson('/api/internal/engine/auth', ['action' => 'publish', 'path' => 'live/x'])->assertStatus(401);
        $this->postJson('/api/internal/engine/auth', ['action' => 'publish', 'path' => 'live/x', 'secret' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/internal/engine/auth', ['action' => 'publish', 'path' => 'live/unknown'], ['X-Engine-Secret' => 'top-secret'])->assertStatus(401);
    }

    public function test_api_never_exposes_stream_keys(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $d = StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        $token = $user->createToken('t', ['read'])->plainTextToken;
        $r = $this->getJson('/api/v1/destinations', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertStringNotContainsString('stream_key_encrypted', $r->getContent());
        $this->assertStringNotContainsString($d->streamKey(), $r->getContent());
        $this->assertStringContainsString('stream_key_hint', $r->getContent());
    }
}
