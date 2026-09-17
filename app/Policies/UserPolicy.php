<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class UserPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(User $user, User $target): bool
    {
        return $this->sameTenant($user, $target) && $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    /** Admins cannot edit users with a higher role level than their own (no privilege escalation). */
    public function update(User $user, User $target): bool
    {
        return $this->sameTenant($user, $target) && $user->hasPermission('users.manage') && $user->highestRoleLevel() >= $target->highestRoleLevel();
    }

    public function delete(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->update($user, $target);
    }

    public function assignRole(User $user, string $role): bool
    {
        $level = (int) (Role::where('name', $role)->value('level') ?? 999);

        return $user->hasPermission('users.manage') && $user->highestRoleLevel() >= $level;
    }
}
