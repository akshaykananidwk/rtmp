<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StreamEndpoint;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class StreamEndpointPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('stream_keys.view');
    }

    public function view(User $user, StreamEndpoint $endpoint): bool
    {
        return $this->sameTenant($user, $endpoint) && $user->hasPermission('stream_keys.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('stream_keys.manage');
    }

    public function update(User $user, StreamEndpoint $endpoint): bool
    {
        return $this->sameTenant($user, $endpoint) && $user->hasPermission('stream_keys.manage');
    }

    public function delete(User $user, StreamEndpoint $endpoint): bool
    {
        return $this->update($user, $endpoint);
    }

    /** Reveal / copy the plaintext key. */
    public function reveal(User $user, StreamEndpoint $endpoint): bool
    {
        return $this->sameTenant($user, $endpoint) && ($user->hasPermission('stream_keys.manage') || ($user->isOperator() && $endpoint->user_id === $user->id));
    }
}
