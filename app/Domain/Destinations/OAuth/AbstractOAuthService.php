<?php

declare(strict_types=1);

namespace App\Domain\Destinations\OAuth;

use App\Models\PlatformAccount;
use App\Models\PlatformToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class AbstractOAuthService
{
    abstract public function platform(): string;

    abstract public function isConfigured(): bool;

    abstract public function authorizationUrl(string $state, string $redirectUri): string;

    /** Exchange code → [access_token, refresh_token, expires_in, scope]. */
    abstract public function exchangeCode(string $code, string $redirectUri): array;

    abstract public function refresh(PlatformToken $token): void;

    /** Fetch profile (external_id, name, avatar, meta). */
    abstract public function profile(string $accessToken): array;

    public function http(?string $accessToken = null): PendingRequest
    {
        $req = Http::timeout(20)->acceptJson();

        return $accessToken ? $req->withToken($accessToken) : $req;
    }

    /** Persist account + tokens (encrypted) after a successful OAuth callback. */
    public function storeAccount(Tenant $tenant, ?User $user, array $tokenData): PlatformAccount
    {
        $profile = $this->profile($tokenData['access_token']);

        return DB::transaction(function () use ($tenant, $user, $tokenData, $profile) {
            $account = PlatformAccount::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'platform' => $this->platform(), 'external_id' => $profile['external_id']],
                ['user_id' => $user?->id, 'name' => $profile['name'], 'avatar_url' => $profile['avatar'] ?? null, 'meta' => $profile['meta'] ?? [], 'status' => 'connected']
            );

            // Reconnecting an account that was disconnected earlier brings the row back.
            // deleted_at cannot go through the array above: it is not fillable, and passing
            // it there aborted the whole connection with a mass-assignment error.
            if ($account->trashed()) {
                $account->restore();
            }

            $token = new PlatformToken(['platform_account_id' => $account->id, 'scopes' => $tokenData['scope'] ?? null]);
            $token->setAccessToken($tokenData['access_token']);
            $token->setRefreshToken($tokenData['refresh_token'] ?? null);
            $token->expires_at = isset($tokenData['expires_in']) ? now()->addSeconds((int) $tokenData['expires_in']) : null;
            $token->save();

            $account->tokens()->where('id', '!=', $token->id)->delete();

            return $account;
        });
    }

    /** Valid access token for an account; refreshes when needed. */
    public function accessToken(PlatformAccount $account): string
    {
        $token = $account->token;
        if (! $token) {
            throw new \RuntimeException('Account has no token; please reconnect.');
        }
        if ($token->isExpired() && $token->refreshToken()) {
            $this->refresh($token);
        } elseif ($token->isExpired()) {
            $account->forceFill(['status' => 'expired'])->save();
            throw new \RuntimeException('Access token expired; please reconnect the account.');
        }

        return $token->accessToken();
    }
}
