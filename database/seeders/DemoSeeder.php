<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Streaming\StreamKeyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\StreamDestination;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local development only: creates a tenant, an admin and sample data. Never run in production. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped in production.');

            return;
        }

        $this->call([RoleSeeder::class, ProtectedPathSeeder::class, SettingsSeeder::class]);

        $tenant = Tenant::firstOrCreate(['slug' => 'ak-computer'], ['name' => 'AK Computer']);
        app(TenantContext::class)->set($tenant);

        $admin = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['tenant_id' => $tenant->id, 'name' => 'Akshay Kanani', 'password' => 'ChangeMe!12345', 'email_verified_at' => now()]
        );
        $admin->syncRole(Role::SUPER_ADMIN);

        $keys = app(StreamKeyService::class);
        $endpoint = $keys->create($tenant, $admin, 'Dwarka Event', 'AKDWK-EVENT-001');

        StreamDestination::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Local test RTMP'],
            ['stream_endpoint_id' => $endpoint->id, 'platform' => 'custom_rtmp', 'connection_method' => 'rtmp', 'rtmp_url' => 'rtmp://127.0.0.1:1935/test', 'is_enabled' => true]
        );
    }
}
