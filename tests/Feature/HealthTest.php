<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Health\HealthService;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Models\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_run_persists_report_and_critical_checks_pass(): void
    {
        config(['akstream.streaming.engine' => 'none', 'akstream.disk.warning_percent' => 100, 'akstream.disk.critical_percent' => 101]);
        app()->forgetInstance(StreamEngineInterface::class);
        $run = app(HealthService::class)->run('manual');
        $this->assertTrue($run['critical_ok'], app(HealthService::class)->report($run));
        $this->assertCount(11, $run['results']);
        $this->assertSame(11, HealthCheck::where('run_id', $run['run_id'])->count());
        $report = app(HealthService::class)->report($run);
        $this->assertStringContainsString('Application', $report);
        $this->assertStringContainsString('Database', $report);
        $this->assertMatchesRegularExpression('/Database\s+PASS/', $report);
    }

    public function test_health_command_json_and_admin_pages(): void
    {
        config(['akstream.streaming.engine' => 'none', 'akstream.disk.warning_percent' => 100, 'akstream.disk.critical_percent' => 101]);
        [$tenant, $user] = $this->adminSetup();
        $code = Artisan::call('health:check', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(0, $code);
        $this->assertTrue($json['critical_ok']);
        $this->actingAs($user)->post('/admin/health/run')->assertRedirect();
        $this->actingAs($user)->get('/admin/health')->assertOk()->assertSee('Database');
        $this->actingAs($user)->get('/admin/health/database')->assertOk()->assertSee('diagnostics');
        $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);
    }
}
