<?php

declare(strict_types=1);

namespace App\Domain\Destinations\Connectors;

use App\Domain\Destinations\AbstractConnector;
use App\Domain\Destinations\OAuth\TwitchOAuthService;
use App\Domain\Destinations\PlatformDefinition;
use App\Models\StreamSessionDestination;

/**
 * Twitch: official Helix API. With OAuth (scope channel:read:stream_key) we fetch the
 * stream key automatically; otherwise the user pastes it from the Twitch dashboard.
 */
class TwitchConnector extends AbstractConnector
{
    public const INGEST = 'rtmp://live.twitch.tv/app';

    public function __construct(private readonly TwitchOAuthService $oauth) {}

    public function platform(): string
    {
        return 'twitch';
    }

    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(
            name: 'twitch',
            label: 'Twitch',
            connectionMethod: 'oauth',
            oauthSupported: true,
            officialApi: true,
            support: 'supported',
            description: 'Connect your Twitch account (official Helix API) or paste your stream key from the Creator Dashboard.',
            fields: [
                ['name' => 'rtmp_url', 'label' => 'Ingest URL', 'type' => 'text', 'required' => false, 'help' => 'Default: '.self::INGEST.' (see https://help.twitch.tv/s/twitch-ingest-recommendation for nearest server)'],
                ['name' => 'stream_key', 'label' => 'Stream Key (optional when account connected)', 'type' => 'password', 'required' => false],
            ],
            defaultRtmpUrl: self::INGEST,
            icon: '🎮',
        );
    }

    public function validate(): array
    {
        $d = $this->requireDestination();
        if ($d->platformAccount) {
            $key = $this->fetchKey();

            return $key ? ['ok' => true, 'message' => 'Twitch account connected; stream key retrieved'] : ['ok' => false, 'message' => 'Could not retrieve stream key from Twitch (re-connect account with channel:read:stream_key)'];
        }
        if (! $d->streamKey()) {
            return ['ok' => false, 'message' => 'Connect a Twitch account or provide a stream key'];
        }

        return $this->validateRtmpUrl($d->rtmp_url ?: self::INGEST);
    }

    public function rtmpTarget(StreamSessionDestination $sessionDestination): ?string
    {
        $d = $this->requireDestination();
        $key = $d->platformAccount ? ($this->fetchKey() ?? $d->streamKey()) : $d->streamKey();

        return $this->buildRtmpUrl($d->rtmp_url ?: self::INGEST, $key);
    }

    private function fetchKey(): ?string
    {
        $d = $this->requireDestination();
        try {
            return $this->oauth->streamKey($d->platformAccount);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getStreamInfo(?StreamSessionDestination $sessionDestination = null): array
    {
        $d = $this->requireDestination();

        return parent::getStreamInfo($sessionDestination) + [
            'watch_url' => $d->platformAccount?->meta['login'] ?? null ? 'https://twitch.tv/'.$d->platformAccount->meta['login'] : null,
        ];
    }
}
