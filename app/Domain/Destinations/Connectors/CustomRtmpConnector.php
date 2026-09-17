<?php

declare(strict_types=1);

namespace App\Domain\Destinations\Connectors;

use App\Domain\Destinations\AbstractConnector;
use App\Domain\Destinations\PlatformDefinition;
use App\Models\StreamSessionDestination;

class CustomRtmpConnector extends AbstractConnector
{
    public function platform(): string
    {
        return 'custom_rtmp';
    }

    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(
            name: 'custom_rtmp',
            label: 'Custom RTMP',
            connectionMethod: 'rtmp',
            oauthSupported: false,
            officialApi: true,
            support: 'supported',
            description: 'Push to any RTMP/RTMPS server (Vimeo, Dailymotion, Kick, Restream, a private media server...).',
            fields: [
                ['name' => 'rtmp_url', 'label' => 'RTMP URL', 'type' => 'text', 'required' => true, 'help' => 'e.g. rtmp://live.example.com/app'],
                ['name' => 'stream_key', 'label' => 'Stream Key', 'type' => 'password', 'required' => true],
            ],
            icon: '📡',
        );
    }

    public function validate(): array
    {
        $d = $this->requireDestination();
        $r = $this->validateRtmpUrl($d->rtmp_url);
        if (! $r['ok']) {
            return $r;
        }
        if (! $d->streamKey()) {
            return ['ok' => false, 'message' => 'Stream key is required'];
        }

        return ['ok' => true, 'message' => 'Configuration valid'];
    }

    public function rtmpTarget(StreamSessionDestination $sessionDestination): ?string
    {
        $d = $this->requireDestination();

        return $this->buildRtmpUrl($d->rtmp_url, $d->streamKey());
    }
}
