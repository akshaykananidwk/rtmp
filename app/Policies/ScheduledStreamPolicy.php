<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ScheduledStream;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class ScheduledStreamPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('schedules.view');
    }

    public function view(User $user, ScheduledStream $s): bool
    {
        return $this->sameTenant($user, $s) && $user->hasPermission('schedules.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('schedules.manage');
    }

    public function update(User $user, ScheduledStream $s): bool
    {
        return $this->sameTenant($user, $s) && $user->hasPermission('schedules.manage');
    }

    public function delete(User $user, ScheduledStream $s): bool
    {
        return $this->update($user, $s);
    }
}
