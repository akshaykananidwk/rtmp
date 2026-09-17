<?php

declare(strict_types=1);

namespace App\Domain\Destinations\OAuth;

use App\Domain\Settings\SettingsService;
use App\Models\PlatformToken;

/** Google OAuth 2.0 for the YouTube Data API v3 (official). */
class GoogleOAuthService extends AbstractOAuthService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function platform(): string
    {
        return 'youtube';
    }

    public function clientId(): ?string
    {
        return $this->settings->get('platforms', 'youtube_client_id', config('akstream.platforms.youtube.client_id')) ?: null;
    }

    public function clientSecret(): ?string
    {
        return $this->settings->get('platforms', 'youtube_client_secret', config('akstream.platforms.youtube.client_secret')) ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->clientId() && $this->clientSecret();
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', config('akstream.platforms.youtube.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $res = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Google token exchange failed: '.($res->json('error_description') ?? $res->status()));
        }

        return $res->json();
    }

    public function refresh(PlatformToken $token): void
    {
        $res = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
            'refresh_token' => $token->refreshToken(),
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);
        if (! $res->successful()) {
            $token->account?->forceFill(['status' => 'expired'])->save();
            throw new \RuntimeException('Google token refresh failed: '.($res->json('error_description') ?? $res->status()));
        }
        $token->setAccessToken($res->json('access_token'));
        $token->expires_at = now()->addSeconds((int) $res->json('expires_in', 3600));
        $token->save();
    }

    public function profile(string $accessToken): array
    {
        $res = $this->http($accessToken)->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true']);
        if (! $res->successful()) {
            throw new \RuntimeException('Could not read YouTube channel: '.($res->json('error.message') ?? $res->status()));
        }
        $items = $res->json('items') ?? [];
        if (! $items) {
            throw new \RuntimeException('This Google account has no YouTube channel.');
        }
        $ch = $items[0];

        return [
            'external_id' => $ch['id'],
            'name' => $ch['snippet']['title'] ?? 'YouTube channel',
            'avatar' => $ch['snippet']['thumbnails']['default']['url'] ?? null,
            'meta' => ['channels' => array_map(fn ($c) => ['id' => $c['id'], 'title' => $c['snippet']['title'] ?? ''], $items)],
        ];
    }
}
