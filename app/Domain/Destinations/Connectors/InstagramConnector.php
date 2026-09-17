<?php

declare(strict_types=1);

namespace App\Domain\Destinations\Connectors;

use App\Domain\Destinations\PlatformDefinition;

/**
 * Instagram Live has NO official public API for creating a live broadcast.
 * The only officially supported path is the "Live Producer" in the Instagram web app
 * (professional accounts) which hands out a RTMPS URL + key that the user pastes here.
 * We therefore do NOT claim one-click Instagram Live; this is a labelled RTMP destination.
 */
class InstagramConnector extends CustomRtmpConnector
{
    public function platform(): string
    {
        return 'instagram';
    }

    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(
            name: 'instagram',
            label: 'Instagram (Live Producer RTMP)',
            connectionMethod: 'rtmp',
            oauthSupported: false,
            officialApi: false,
            support: 'not_supported_by_api',
            description: 'NOT SUPPORTED BY CURRENT OFFICIAL API for one-click Live. Paste the RTMPS URL and key from Instagram Live Producer (instagram.com → Create → Live → "Live Producer"). Available to eligible professional accounts only.',
            fields: [
                ['name' => 'rtmp_url', 'label' => 'RTMPS URL (from Live Producer)', 'type' => 'text', 'required' => true, 'help' => 'Usually rtmps://edgetee-upload-...facebook.com:443/rtmp'],
                ['name' => 'stream_key', 'label' => 'Stream Key', 'type' => 'password', 'required' => true],
            ],
            icon: '📸',
            docsUrl: 'https://help.instagram.com/',
        );
    }
}
