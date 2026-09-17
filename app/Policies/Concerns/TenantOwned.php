<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait TenantOwned
{
    protected function sameTenant(User $user, Model $model): bool
    {
        return isset($model->tenant_id) && $model->tenant_id === $user->tenant_id;
    }
}
