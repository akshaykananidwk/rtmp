<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Overlay;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class OverlayPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('overlays.view');
    }

    public function view(User $user, Overlay $overlay): bool
    {
        return $this->sameTenant($user, $overlay) && $user->hasPermission('overlays.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('overlays.manage');
    }

    public function update(User $user, Overlay $overlay): bool
    {
        return $this->sameTenant($user, $overlay) && $user->hasPermission('overlays.manage');
    }

    public function delete(User $user, Overlay $overlay): bool
    {
        return $this->update($user, $overlay);
    }
}
