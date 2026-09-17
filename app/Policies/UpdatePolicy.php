<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Update;
use App\Models\User;

class UpdatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('updates.view');
    }

    public function view(User $user, Update $u): bool
    {
        return $user->hasPermission('updates.view');
    }

    public function manage(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermission('updates.manage');
    }
}
