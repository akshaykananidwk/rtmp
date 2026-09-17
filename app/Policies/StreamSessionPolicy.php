<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StreamSession;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class StreamSessionPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('streams.view');
    }

    public function view(User $user, StreamSession $s): bool
    {
        return $this->sameTenant($user, $s) && $user->hasPermission('streams.view');
    }

    public function control(User $user, ?StreamSession $s = null): bool
    {
        return ($s === null || $this->sameTenant($user, $s)) && $user->hasPermission('streams.control');
    }

    public function logs(User $user, ?StreamSession $s = null): bool
    {
        return ($s === null || $this->sameTenant($user, $s)) && $user->hasPermission('streams.logs');
    }
}
