<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ProtectedPathSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function seedSystem(): void
    {
        (new RoleSeeder)->run();
        (new ProtectedPathSeeder)->run();
        (new SettingsSeeder)->run();
    }

    protected function makeTenant(string $slug = 'ak'): Tenant
    {
        return Tenant::firstOrCreate(['slug' => $slug], ['name' => strtoupper($slug).' Tenant']);
    }

    protected function makeUser(Tenant $tenant, string $role = Role::SUPER_ADMIN, array $attrs = []): User
    {
        if (! Role::count()) {
            $this->seedSystem();
        }
        $user = User::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.$tenant->slug.'-'.uniqid().'@example.com',
            'password' => 'Str0ng!Password#2026',
            'email_verified_at' => now(),
        ], $attrs));
        $user->assignRole($role);

        return $user->fresh();
    }

    /** Set the tenant context the way the middleware would for console/service tests. */
    protected function actAsTenant(Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant);
    }

    protected function adminSetup(string $role = Role::SUPER_ADMIN): array
    {
        $this->seedSystem();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, $role);

        return [$tenant, $user];
    }
}
