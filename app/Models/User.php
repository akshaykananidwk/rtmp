<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUlids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'phone', 'timezone', 'is_active', 'email_verified_at'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Users are tenant-owned but NOT globally scoped (authentication resolves them by e-mail before a tenant is known). */
    public function scopeForTenant(Builder $query, ?string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return Collection<int, string> */
    public function roleNames(): Collection
    {
        return $this->relationLoaded('roles') ? $this->roles->pluck('name') : $this->roles()->pluck('name');
    }

    public function hasRole(string ...$roles): bool
    {
        return $this->roleNames()->intersect($roles)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN, Role::ADMIN);
    }

    public function isOperator(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN, Role::ADMIN, Role::OPERATOR);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->roles()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->exists();
    }

    public function highestRoleLevel(): int
    {
        return (int) ($this->roles()->max('level') ?? 0);
    }

    public function assignRole(string $role): void
    {
        $model = Role::where('name', $role)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$model->id]);
    }

    public function syncRole(string $role): void
    {
        $model = Role::where('name', $role)->firstOrFail();
        $this->roles()->sync([$model->id]);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
