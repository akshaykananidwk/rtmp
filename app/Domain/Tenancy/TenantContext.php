<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Tenant;

/**
 * Holds the tenant for the current request / job / console run.
 * Never resolved from user input: it is derived from the authenticated user
 * (or explicitly set by trusted system code such as jobs and the supervisor).
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    /** Run a callback with a specific tenant set, restoring the previous one afterwards. */
    public function runAs(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;
        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
