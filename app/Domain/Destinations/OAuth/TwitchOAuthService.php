<?php

declare(strict_types=1);

namespace App\Domain\Destinations\OAuth;

use App\Domain\Settings\SettingsService;
use App\Models\PlatformAccount;
use App\Models\PlatformToken;

class TwitchOAuthService extends AbstractOAuthService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function platform(): string
    {
        return 'twitch';
    }

    public function clientId(): ?string
    {
        return $this->settings->get('platforms', 'twitch_client_id', config('akstream.platforms.twitch.client_id')) ?: null;
    }

    public function clientSecret(): ?string
    {
        return $this->settings->get('platforms', 'twitch_client_secret', config('akstream.platforms.twitch.client_secret')) ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->clientId() && $this->clientSecret();
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://id.twitch.tv/oauth2/authorize?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'channel:read:stream_key user:read:email',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $res = $this->http()->asForm()->post('https://id.twitch.tv/oauth2/token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Twitch token exchange failed: '.($res->json('message') ?? $res->status()));
        }

        return $res->json();
    }

    public function refresh(PlatformToken $token): void
    {
        $res = $this->http()->asForm()->post('https://id.twitch.tv/oauth2/token', [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refreshToken(),
        ]);
        if (! $res->successful()) {
            $token->account?->forceFill(['status' => 'expired'])->save();
            throw new \RuntimeException('Twitch token refresh failed');
        }
        $token->setAccessToken($res->json('access_token'));
        $token->setRefreshToken($res->json('refresh_token'));
        $token->expires_at = now()->addSeconds((int) $res->json('expires_in', 3600));
        $token->save();
    }

    public function profile(string $accessToken): array
    {
        $res = $this->http($accessToken)->withHeaders(['Client-Id' => $this->clientId()])->get('https://api.twitch.tv/helix/users');
        $u = $res->json('data.0');
        if (! $u) {
            throw new \RuntimeException('Could not read Twitch user');
        }

        return ['external_id' => $u['id'], 'name' => $u['display_name'], 'avatar' => $u['profile_image_url'] ?? null, 'meta' => ['login' => $u['login']]];
    }

    public function streamKey(PlatformAccount $account): ?string
    {
        $token = $this->accessToken($account);
        $res = $this->http($token)->withHeaders(['Client-Id' => $this->clientId()])->get('https://api.twitch.tv/helix/streams/key', ['broadcaster_id' => $account->external_id]);

        return $res->json('data.0.stream_key');
    }
}
