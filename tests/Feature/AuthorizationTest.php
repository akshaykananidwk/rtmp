<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_is_read_only(): void
    {
        [$tenant, $viewer] = $this->adminSetup(Role::VIEWER);
        $this->actingAs($viewer);
        $this->get('/admin')->assertOk();
        $this->get('/admin/destinations')->assertOk();
        $this->get('/admin/destinations/create')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/admin/updates')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->post('/admin/live/start')->assertForbidden();
        $this->post('/admin/backups', ['type' => 'full'])->assertForbidden();
    }

    public function test_operator_can_control_streams_but_not_settings(): void
    {
        [$tenant, $op] = $this->adminSetup(Role::OPERATOR);
        $this->actingAs($op);
        $this->post('/admin/live/start')->assertRedirect(); // no stream → redirect back with error, but not forbidden
        $this->get('/admin/settings')->assertForbidden();
        $this->post('/admin/settings/general', ['app_name' => 'x', 'timezone' => 'UTC'])->assertForbidden();
        $this->get('/admin/updates')->assertForbidden();
        $this->get('/admin/stream-keys/create')->assertForbidden();
        $this->get('/admin/schedules/create')->assertOk();
    }

    public function test_admin_cannot_escalate_to_super_admin(): void
    {
        [$tenant, $admin] = $this->adminSetup(Role::ADMIN);
        $this->actingAs($admin);
        $r = $this->post('/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Str0ng!Password#2026', 'password_confirmation' => 'Str0ng!Password#2026', 'timezone' => 'UTC', 'role' => Role::SUPER_ADMIN]);
        $r->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);

        $r = $this->post('/admin/users', ['name' => 'Y', 'email' => 'y@example.com', 'password' => 'Str0ng!Password#2026', 'password_confirmation' => 'Str0ng!Password#2026', 'timezone' => 'UTC', 'role' => Role::OPERATOR]);
        $r->assertRedirect('/admin/users');
        $this->assertDatabaseHas('users', ['email' => 'y@example.com']);
    }

    public function test_admin_cannot_edit_super_admin(): void
    {
        [$tenant, $admin] = $this->adminSetup(Role::ADMIN);
        $super = $this->makeUser($tenant, Role::SUPER_ADMIN);
        $this->actingAs($admin)->get('/admin/users/'.$super->id.'/edit')->assertForbidden();
        $this->actingAs($admin)->delete('/admin/users/'.$super->id)->assertForbidden();
    }

    public function test_super_admin_has_everything(): void
    {
        [$tenant, $su] = $this->adminSetup();
        $this->actingAs($su);
        foreach (['/admin', '/admin/live', '/admin/stream-test', '/admin/destinations', '/admin/schedules', '/admin/recordings', '/admin/analytics', '/admin/history', '/admin/stream-keys', '/admin/obs-setup', '/admin/users', '/admin/backups', '/admin/updates', '/admin/health', '/admin/logs', '/admin/logs/audit', '/admin/logs/errors', '/admin/settings', '/admin/cron-setup', '/admin/profile', '/admin/notifications'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
