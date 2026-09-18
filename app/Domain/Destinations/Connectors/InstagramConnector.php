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
                ['name' => 'stream_key', 'label' => 'Stream Key', 'type' => 'password', 'required' => true, 'help' => 'Single-use: Live Producer issues a new key for every broadcast, so paste a fresh one each time. A spent key fails with "Operation not permitted".'],
            ],
            icon: '📸',
            docsUrl: 'https://help.instagram.com/',
            setupSteps: [
                'On a phone or at instagram.com, make sure the account is a Professional account (Creator or Business) — Live Producer is not offered on personal accounts.',
                'Open instagram.com on a computer, sign in, then choose Create → Live. If "Live Producer" is not offered, Instagram has not enabled it for this account and there is no supported way around that.',
                'Live Producer shows a Stream URL and a Stream Key. Copy them.',
                'Paste the Stream URL into RTMPS URL below and the Stream Key into Stream Key, then save.',
                'Start streaming from OBS, press START LIVE here, and then press "Go live" in Live Producer — Instagram will not publish until you press it there.',
            ],
        );
    }
}
