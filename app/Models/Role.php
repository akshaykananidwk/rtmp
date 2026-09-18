<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    /**
     * Owner of a self-service account: full control of their own tenant, none of the
     * server. Kept separate from ADMIN, whose holders are staff the operator vetted and
     * who therefore keep settings, backups and updates.
     */
    public const ACCOUNT_OWNER = 'account_owner';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

    protected $fillable = ['name', 'label', 'level', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
