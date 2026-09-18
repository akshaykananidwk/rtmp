<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invites a colleague into an existing account.
 *
 * The link carries the only copy of the token; the database keeps a SHA-256 of it, the
 * same way stream keys are handled, so a leaked database cannot be used to join accounts.
 */
class InvitationService
{
    public const LIFETIME_DAYS = 7;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{invitation: TeamInvitation, token: string} the token is never recoverable later */
    public function invite(Tenant $tenant, User $inviter, string $email, string $role): array
    {
        $email = strtolower(trim($email));
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($tenant, $inviter, $email, $role, $token): TeamInvitation {
            // Re-inviting the same address replaces the outstanding invite rather than
            // leaving two live links to the same account.
            TeamInvitation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return TeamInvitation::create([
                'tenant_id' => $tenant->id,
                'invited_by' => $inviter->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => self::hash($token),
                'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            ]);
        });

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function findByToken(string $token): ?TeamInvitation
    {
        $invitation = TeamInvitation::withoutGlobalScopes()->where('token_hash', self::hash($token))->first();

        return $invitation && $invitation->isPending() ? $invitation : null;
    }

    /** Roles an inviter may hand out: never above their own. */
    public function assignableRoles(User $inviter): array
    {
        return Role::where('level', '<=', $inviter->highestRoleLevel())
            ->where('name', '!=', Role::SUPER_ADMIN)
            ->orderByDesc('level')
            ->get()
            ->all();
    }

    public function canAssign(User $inviter, string $role): bool
    {
        $level = (int) (Role::where('name', $role)->value('level') ?? 999);

        return $role !== Role::SUPER_ADMIN && $inviter->highestRoleLevel() >= $level;
    }

    /** Creates the colleague's account inside the inviting tenant and consumes the invite. */
    public function accept(TeamInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            $user = User::create([
                'tenant_id' => $invitation->tenant_id,
                'name' => trim($name),
                'email' => $invitation->email,
                'password' => $password,
                'is_active' => true,
                // The address is proven by the fact they opened the emailed link.
                'email_verified_at' => now(),
            ]);
            $user->assignRole($invitation->role);

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            return $user;
        });
    }
}
