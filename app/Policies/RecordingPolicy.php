<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recording;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

class RecordingPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('recordings.view');
    }

    public function view(User $user, Recording $r): bool
    {
        return $this->sameTenant($user, $r) && $user->hasPermission('recordings.view');
    }

    public function download(User $user, Recording $r): bool
    {
        return $this->sameTenant($user, $r) && $user->hasPermission('recordings.download');
    }

    public function delete(User $user, Recording $r): bool
    {
        return $this->sameTenant($user, $r) && $user->hasPermission('recordings.delete');
    }
}
