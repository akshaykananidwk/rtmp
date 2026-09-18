<?php

declare(strict_types=1);

namespace App\Domain\Destinations\Connectors;

use App\Domain\Destinations\PlatformDefinition;

/**
 * LinkedIn Live's API is restricted to approved partners. The officially supported
 * self-serve path is "Custom stream (RTMP)" in LinkedIn's Live event UI, which gives
 * a RTMP URL + key the user pastes here.
 */
class LinkedInConnector extends CustomRtmpConnector
{
    public function platform(): string
    {
        return 'linkedin';
    }

    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(
            name: 'linkedin',
            label: 'LinkedIn Live (Custom RTMP)',
            connectionMethod: 'rtmp',
            oauthSupported: false,
            officialApi: false,
            support: 'partial',
            description: 'LinkedIn Live API is partner-only (NOT SUPPORTED BY CURRENT OFFICIAL API for self-serve apps). Create the event on LinkedIn, choose "Custom stream (RTMP)" and paste the URL + key here.',
            fields: [
                ['name' => 'rtmp_url', 'label' => 'RTMP URL', 'type' => 'text', 'required' => true],
                ['name' => 'stream_key', 'label' => 'Stream Key', 'type' => 'password', 'required' => true],
            ],
            icon: '💼',
            setupSteps: [
                'LinkedIn Live must be approved for your profile or page before any of this works — apply first.',
                'Once approved, create the live event on LinkedIn and choose a custom streaming tool.',
                'Copy the RTMP URL and stream key LinkedIn gives you and paste them below.',
                'Start streaming from OBS, then press START LIVE.',
            ],
        );
    }
}
