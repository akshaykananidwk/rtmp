<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StreamDestination;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class StreamDestinationPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('destinations.view');
    }

    public function view(User $user, StreamDestination $d): bool
    {
        return $this->sameTenant($user, $d) && $user->hasPermission('destinations.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('destinations.manage');
    }

    public function update(User $user, StreamDestination $d): bool
    {
        return $this->sameTenant($user, $d) && $user->hasPermission('destinations.manage');
    }

    public function delete(User $user, StreamDestination $d): bool
    {
        return $this->update($user, $d);
    }

    public function test(User $user, StreamDestination $d): bool
    {
        return $this->sameTenant($user, $d) && $user->hasPermission('destinations.test');
    }

    public function control(User $user, StreamDestination $d): bool
    {
        return $this->sameTenant($user, $d) && $user->hasPermission('streams.control');
    }
}
