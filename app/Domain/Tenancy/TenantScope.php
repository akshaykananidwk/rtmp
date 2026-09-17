<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope: every tenant-owned query is automatically constrained to the
 * current tenant. If no tenant is set (e.g. unauthenticated request), tenant
 * owned models return nothing rather than everything.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->has()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->id());

            return;
        }

        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            // Fail closed: no tenant => no rows.
            $builder->whereRaw('1 = 0');
        }
    }
}
