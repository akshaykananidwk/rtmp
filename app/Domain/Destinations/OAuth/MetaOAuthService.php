<?php

declare(strict_types=1);

namespace App\Domain\Destinations\OAuth;

use App\Domain\Settings\SettingsService;
use App\Models\PlatformToken;

/** Facebook Login + Graph API (official Meta APIs). */
class MetaOAuthService extends AbstractOAuthService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function platform(): string
    {
        return 'facebook';
    }

    public function graph(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('akstream.platforms.facebook.graph_version', 'v21.0').'/'.ltrim($path, '/');
    }

    public function appId(): ?string
    {
        return $this->settings->get('platforms', 'meta_app_id', config('akstream.platforms.facebook.app_id')) ?: null;
    }

    public function appSecret(): ?string
    {
        return $this->settings->get('platforms', 'meta_app_secret', config('akstream.platforms.facebook.app_secret')) ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->appId() && $this->appSecret();
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://www.facebook.com/'.config('akstream.platforms.facebook.graph_version', 'v21.0').'/dialog/oauth?'.http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', config('akstream.platforms.facebook.scopes')),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $res = $this->http()->get($this->graph('oauth/access_token'), [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Meta token exchange failed: '.($res->json('error.message') ?? $res->status()));
        }
        $short = $res->json();

        // Exchange for a long-lived token (~60 days)
        $long = $this->http()->get($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $short['access_token'],
        ]);

        $data = $long->successful() ? $long->json() : $short;
        $data['expires_in'] = $data['expires_in'] ?? 5183944;

        return $data;
    }

    public function refresh(PlatformToken $token): void
    {
        // Meta long-lived user tokens cannot be refreshed silently; user must re-authenticate.
        $token->account?->forceFill(['status' => 'expired'])->save();
        throw new \RuntimeException('Facebook token expired; please reconnect the account.');
    }

    public function profile(string $accessToken): array
    {
        $me = $this->http($accessToken)->get($this->graph('me'), ['fields' => 'id,name,picture']);
        if (! $me->successful()) {
            throw new \RuntimeException('Could not read Facebook profile: '.($me->json('error.message') ?? $me->status()));
        }

        $pages = $this->http($accessToken)->get($this->graph('me/accounts'), ['fields' => 'id,name,access_token,category', 'limit' => 100]);
        $pageList = [];
        foreach ($pages->json('data') ?? [] as $p) {
            // Page tokens are stored encrypted inside the token record, not in meta.
            $pageList[] = ['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'] ?? null];
        }

        return [
            'external_id' => $me->json('id'),
            'name' => $me->json('name'),
            'avatar' => $me->json('picture.data.url'),
            'meta' => ['pages' => $pageList],
        ];
    }

    /** Page access token for a page the user manages (derived from the user token, never stored in plain text). */
    public function pageAccessToken(string $userToken, string $pageId): string
    {
        $res = $this->http($userToken)->get($this->graph($pageId), ['fields' => 'access_token']);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new \RuntimeException('Could not get page token: '.($res->json('error.message') ?? 'missing pages_manage_posts permission'));
        }

        return $res->json('access_token');
    }

    public function verifyWebhookSignature(string $payload, ?string $header): bool
    {
        if (! $header || ! $this->appSecret() || ! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $payload, $this->appSecret());

        return hash_equals($expected, $header);
    }
}
