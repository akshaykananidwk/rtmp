<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Derives the tenant strictly from the authenticated user. Never from input. */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previous = $this->context->get();
        $user = $request->user();
        if ($user) {
            $tenant = Tenant::find($user->tenant_id);
            if (! $tenant || ! $tenant->is_active || ! $user->is_active) {
                auth()->logout();
                abort(403, 'Account is disabled.');
            }
            $this->context->set($tenant);
        }

        try {
            return $next($request);
        } finally {
            $this->context->set($previous);
        }
    }
}
