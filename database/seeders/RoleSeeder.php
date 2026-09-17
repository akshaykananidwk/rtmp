<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboard' => ['dashboard.view'],
        'streams' => ['streams.view', 'streams.control', 'streams.logs'],
        'stream_keys' => ['stream_keys.view', 'stream_keys.manage'],
        'destinations' => ['destinations.view', 'destinations.manage', 'destinations.test'],
        'schedules' => ['schedules.view', 'schedules.manage'],
        'recordings' => ['recordings.view', 'recordings.download', 'recordings.delete'],
        'analytics' => ['analytics.view'],
        'users' => ['users.view', 'users.manage'],
        'backups' => ['backups.view', 'backups.manage', 'backups.restore'],
        'updates' => ['updates.view', 'updates.manage'],
        'health' => ['health.view'],
        'logs' => ['logs.view'],
        'settings' => ['settings.view', 'settings.manage'],
    ];

    public const ROLE_PERMISSIONS = [
        Role::SUPER_ADMIN => '*',
        Role::ADMIN => [
            'dashboard.view', 'streams.*', 'stream_keys.*', 'destinations.*', 'schedules.*', 'recordings.*',
            'analytics.view', 'users.*', 'backups.view', 'backups.manage', 'health.view', 'logs.view', 'settings.view', 'updates.view',
        ],
        Role::OPERATOR => [
            'dashboard.view', 'streams.view', 'streams.control', 'streams.logs', 'stream_keys.view', 'destinations.view', 'destinations.test',
            'schedules.view', 'schedules.manage', 'recordings.view', 'recordings.download', 'analytics.view', 'health.view', 'logs.view',
        ],
        Role::VIEWER => ['dashboard.view', 'streams.view', 'destinations.view', 'schedules.view', 'recordings.view', 'analytics.view', 'health.view'],
    ];

    public function run(): void
    {
        $roles = [
            [Role::SUPER_ADMIN, 'Super Admin', 100, 'Full access to everything including system settings and updates.'],
            [Role::ADMIN, 'Admin', 80, 'Manage streaming, users and destinations.'],
            [Role::OPERATOR, 'Operator', 50, 'Can start/stop streams but cannot change system settings.'],
            [Role::VIEWER, 'Viewer', 10, 'Read-only dashboard access.'],
        ];

        foreach ($roles as [$name, $label, $level, $desc]) {
            Role::updateOrCreate(['name' => $name], ['label' => $label, 'level' => $level, 'description' => $desc]);
        }

        $all = [];
        foreach (self::PERMISSIONS as $group => $perms) {
            foreach ($perms as $p) {
                $all[] = Permission::updateOrCreate(['name' => $p], ['group' => $group, 'label' => ucwords(str_replace(['.', '_'], ' ', $p))]);
            }
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $granted) {
            $role = Role::where('name', $roleName)->first();
            $ids = collect($all)->filter(function (Permission $p) use ($granted) {
                if ($granted === '*') {
                    return true;
                }
                foreach ($granted as $g) {
                    if ($g === $p->name || (str_ends_with($g, '.*') && str_starts_with($p->name, substr($g, 0, -1)))) {
                        return true;
                    }
                }

                return false;
            })->pluck('id')->all();
            $role->permissions()->sync($ids);
        }
    }
}
