<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('backups.view');
    }

    public function view(User $user, Backup $b): bool
    {
        return $user->hasPermission('backups.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('backups.manage');
    }

    public function download(User $user, Backup $b): bool
    {
        return $user->hasPermission('backups.manage');
    }

    public function delete(User $user, Backup $b): bool
    {
        return $user->hasPermission('backups.manage');
    }

    public function restore(User $user, Backup $b): bool
    {
        return $user->isSuperAdmin() || $user->hasPermission('backups.restore');
    }
}
