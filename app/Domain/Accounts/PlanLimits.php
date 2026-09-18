<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Settings\SettingsService;
use App\Models\Role;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\User;

/**
 * How much a self-service account may create.
 *
 * Whoever runs the server is never limited by its own settings, so super admins are
 * exempt; everyone else is held to the numbers on Settings → Sign-ups.
 */
class PlanLimits
{
    public const STREAM_KEYS = 'max_stream_keys';

    public const DESTINATIONS = 'max_destinations';

    public function __construct(private readonly SettingsService $settings) {}

    public function max(string $what): int
    {
        $default = $what === self::STREAM_KEYS ? 3 : 10;

        return max(1, (int) $this->settings->get('registration', $what, $default));
    }

    public function exempt(?User $user): bool
    {
        return $user !== null && $user->hasRole(Role::SUPER_ADMIN);
    }

    public function used(string $what): int
    {
        // Tenant scope is already applied, so this counts the signed-in account only.
        return $what === self::STREAM_KEYS ? StreamEndpoint::count() : StreamDestination::count();
    }

    /** True when creating one more would exceed the account's allowance. */
    public function reached(string $what, ?User $user): bool
    {
        return ! $this->exempt($user) && $this->used($what) >= $this->max($what);
    }

    public function message(string $what): string
    {
        $label = $what === self::STREAM_KEYS ? 'stream keys' : 'destinations';

        return 'Your plan allows '.$this->max($what).' '.$label.'. Remove one first, or ask the administrator to raise the limit.';
    }
}
