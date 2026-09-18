<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\StreamKeyService;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a self-service account: its own tenant, an owner, and a stream key ready to use.
 *
 * Every account is a separate tenant, so one customer can never see another's keys,
 * destinations or recordings — the tenant scope enforces that everywhere else.
 */
class RegistrationService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly StreamKeyService $keys,
    ) {}

    /** Whether the public sign-up form is available at all. */
    public function isOpen(): bool
    {
        return (bool) $this->settings->get('registration', 'open', true);
    }

    /** Owners of a new account get admin rights over their own tenant, never the server. */
    public function ownerRole(): string
    {
        return Role::ADMIN;
    }

    /**
     * @param  array{name:string,email:string,password:string,business_name?:string|null}  $data
     */
    public function register(array $data): User
    {
        $email = strtolower(trim($data['email']));
        $businessName = trim((string) ($data['business_name'] ?? '')) ?: trim($data['name']);

        return DB::transaction(function () use ($data, $email, $businessName): User {
            $tenant = Tenant::create([
                'name' => $businessName,
                'slug' => $this->uniqueSlug($businessName),
                'plan' => (string) $this->settings->get('registration', 'default_plan', 'free'),
                'is_active' => true,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => trim($data['name']),
                'email' => $email,
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->assignRole($this->ownerRole());

            // A brand new account with nothing to stream to is a dead end, so it starts with
            // a working key: the OBS Setup page has something real to show immediately.
            $this->keys->create($tenant, $user, 'My stream');

            return $user;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'account';
        $slug = $base;

        for ($i = 2; Tenant::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
