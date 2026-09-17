<?php

declare(strict_types=1);

namespace App\Domain\Destinations\Connectors;

use App\Domain\Destinations\AbstractConnector;
use App\Domain\Destinations\OAuth\GoogleOAuthService;
use App\Domain\Destinations\PlatformDefinition;
use App\Models\StreamSessionDestination;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;

/**
 * YouTube Live via the official YouTube Data API v3:
 *  liveBroadcasts.insert → liveStreams.insert → liveBroadcasts.bind → push RTMP → liveBroadcasts.transition(live)
 */
class YouTubeConnector extends AbstractConnector
{
    private const API = 'https://www.googleapis.com/youtube/v3/';

    public function __construct(private readonly GoogleOAuthService $oauth) {}

    public function platform(): string
    {
        return 'youtube';
    }

    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(
            name: 'youtube',
            label: 'YouTube Live',
            connectionMethod: 'oauth',
            oauthSupported: true,
            officialApi: true,
            support: 'supported',
            description: 'Official YouTube Data API v3. Creates a broadcast and stream on your channel each time you go live (or reuses a broadcast you select), then transitions it to LIVE automatically. Channel must have live streaming enabled.',
            fields: [
                ['name' => 'title', 'label' => 'Broadcast title', 'type' => 'text', 'required' => false],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
                ['name' => 'privacy', 'label' => 'Privacy', 'type' => 'select', 'options' => ['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Private']],
                ['name' => 'category_id', 'label' => 'Category ID', 'type' => 'text', 'required' => false, 'help' => 'e.g. 22 = People & Blogs'],
                ['name' => 'broadcast_id', 'label' => 'Existing broadcast ID (optional)', 'type' => 'text', 'required' => false, 'help' => 'Leave empty to auto-create a broadcast each session'],
                ['name' => 'scheduled_start', 'label' => 'Scheduled start (optional, ISO 8601)', 'type' => 'text', 'required' => false],
                ['name' => 'stream_key', 'label' => 'Manual stream key (fallback, optional)', 'type' => 'password', 'required' => false, 'help' => 'Only used when no account is connected: rtmp://a.rtmp.youtube.com/live2'],
            ],
            defaultRtmpUrl: 'rtmp://a.rtmp.youtube.com/live2',
            icon: '▶️',
            docsUrl: 'https://developers.google.com/youtube/v3/live/getting-started',
        );
    }

    public function requiresPreparation(): bool
    {
        return $this->destination?->platformAccount !== null;
    }

    public function validate(): array
    {
        $d = $this->requireDestination();
        if (! $d->platformAccount) {
            return $d->streamKey()
                ? ['ok' => true, 'message' => 'Manual stream key configured (no API automation)']
                : ['ok' => false, 'message' => 'Connect a YouTube account or provide a manual stream key'];
        }

        try {
            $token = $this->oauth->accessToken($d->platformAccount);
            $res = $this->api($token)->get(self::API.'liveBroadcasts', ['part' => 'id', 'mine' => 'true', 'maxResults' => 1]);
            if ($res->status() === 403) {
                return ['ok' => false, 'message' => 'Live streaming is not enabled on this channel or API access denied: '.$res->json('error.message')];
            }
            if (! $res->successful()) {
                return ['ok' => false, 'message' => 'YouTube API error: '.($res->json('error.message') ?? $res->status())];
            }

            return ['ok' => true, 'message' => 'YouTube account OK, live streaming available'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function startStream(StreamSessionDestination $sd): void
    {
        $d = $this->requireDestination();
        if (! $d->platformAccount) {
            return;
        }
        $token = $this->oauth->accessToken($d->platformAccount);
        $meta = $sd->platform_meta ?? [];

        $broadcastId = $d->option('broadcast_id') ?: ($meta['broadcast_id'] ?? null);
        if (! $broadcastId) {
            $res = $this->api($token)->post(self::API.'liveBroadcasts?part=snippet,status,contentDetails', [
                'snippet' => [
                    'title' => mb_substr((string) ($d->option('title') ?: ($sd->session?->title ?? 'Live Stream')), 0, 100),
                    'description' => (string) $d->option('description', ''),
                    'scheduledStartTime' => $d->option('scheduled_start') ?: now()->toIso8601String(),
                ],
                'status' => ['privacyStatus' => $d->option('privacy', 'public'), 'selfDeclaredMadeForKids' => false],
                'contentDetails' => ['enableAutoStart' => true, 'enableAutoStop' => true, 'monitorStream' => ['enableMonitorStream' => false]],
            ]);
            $this->assert($res, 'create broadcast');
            $broadcastId = $res->json('id');
            $meta['broadcast_id'] = $broadcastId;
            $meta['auto_created'] = true;
        }

        // Stream (ingestion) — reuse cached one for this destination if it still exists
        $streamId = $meta['stream_id'] ?? $d->option('_stream_id');
        $ingest = null;
        if ($streamId) {
            $res = $this->api($token)->get(self::API.'liveStreams', ['part' => 'cdn,status', 'id' => $streamId]);
            $item = $res->json('items.0');
            if ($item) {
                $ingest = $item['cdn']['ingestionInfo'] ?? null;
            } else {
                $streamId = null;
            }
        }
        if (! $streamId) {
            $res = $this->api($token)->post(self::API.'liveStreams?part=snippet,cdn,contentDetails', [
                'snippet' => ['title' => 'AK Computer One Live Everywhere'],
                'cdn' => ['frameRate' => 'variable', 'ingestionType' => 'rtmp', 'resolution' => 'variable'],
                'contentDetails' => ['isReusable' => true],
            ]);
            $this->assert($res, 'create stream');
            $streamId = $res->json('id');
            $ingest = $res->json('cdn.ingestionInfo');
            $d->options = array_merge($d->options ?? [], ['_stream_id' => $streamId]);
            $d->save();
        }

        $res = $this->api($token)->post(self::API.'liveBroadcasts/bind?'.http_build_query(['part' => 'id,contentDetails', 'id' => $broadcastId, 'streamId' => $streamId]));
        $this->assert($res, 'bind stream');

        $meta['stream_id'] = $streamId;
        $meta['ingest_url'] = $ingest['ingestionAddress'] ?? 'rtmp://a.rtmp.youtube.com/live2';
        $meta['watch_url'] = 'https://youtube.com/watch?v='.$broadcastId;
        $meta['stream_name_hint'] = isset($ingest['streamName']) ? substr((string) $ingest['streamName'], -4) : null;

        // Keep the secret stream name encrypted on the row, never in plain JSON
        $sd->forceFill(['platform_meta' => $meta])->save();
        $this->storeSecret($sd, (string) ($ingest['streamName'] ?? ''));
    }

    public function onRelayLive(StreamSessionDestination $sd): void
    {
        $d = $this->requireDestination();
        if (! $d->platformAccount || empty($sd->platform_meta['broadcast_id']) || ! empty($sd->platform_meta['transitioned'])) {
            return;
        }
        try {
            $token = $this->oauth->accessToken($d->platformAccount);
            // enableAutoStart handles the transition; we also try explicitly (ignored if already live)
            $res = $this->api($token)->post(self::API.'liveBroadcasts/transition?'.http_build_query(['part' => 'status', 'id' => $sd->platform_meta['broadcast_id'], 'broadcastStatus' => 'live']));
            if ($res->successful() || $res->status() === 403) {
                $sd->forceFill(['platform_meta' => array_merge($sd->platform_meta, ['transitioned' => true])])->save();
            }
        } catch (\Throwable) {
            // non-fatal
        }
    }

    public function stopStream(StreamSessionDestination $sd): void
    {
        $d = $this->requireDestination();
        if (! $d->platformAccount || empty($sd->platform_meta['broadcast_id']) || empty($sd->platform_meta['auto_created'])) {
            return;
        }
        try {
            $token = $this->oauth->accessToken($d->platformAccount);
            $this->api($token)->post(self::API.'liveBroadcasts/transition?'.http_build_query(['part' => 'status', 'id' => $sd->platform_meta['broadcast_id'], 'broadcastStatus' => 'complete']));
        } catch (\Throwable) {
        }
    }

    public function getStatus(?StreamSessionDestination $sd = null): array
    {
        $base = parent::getStatus($sd);
        $d = $this->requireDestination();
        if ($d->platformAccount && ! empty($sd?->platform_meta['broadcast_id'])) {
            try {
                $token = $this->oauth->accessToken($d->platformAccount);
                $res = $this->api($token)->get(self::API.'liveBroadcasts', ['part' => 'status', 'id' => $sd->platform_meta['broadcast_id']]);
                $base['platform_status'] = $res->json('items.0.status.lifeCycleStatus');
            } catch (\Throwable) {
            }
        }

        return $base;
    }

    public function rtmpTarget(StreamSessionDestination $sd): ?string
    {
        $d = $this->requireDestination();
        if ($d->platformAccount) {
            $secret = $this->readSecret($sd);

            return $this->buildRtmpUrl($sd->platform_meta['ingest_url'] ?? 'rtmp://a.rtmp.youtube.com/live2', $secret);
        }

        return $this->buildRtmpUrl($d->rtmp_url ?: 'rtmp://a.rtmp.youtube.com/live2', $d->streamKey());
    }

    private function api(string $token)
    {
        return $this->oauth->http($token);
    }

    private function assert(Response $res, string $what): void
    {
        if (! $res->successful()) {
            throw new \RuntimeException("YouTube: failed to $what — ".($res->json('error.message') ?? ('HTTP '.$res->status())));
        }
    }

    private function storeSecret(StreamSessionDestination $sd, string $secret): void
    {
        $meta = $sd->platform_meta ?? [];
        $meta['_secret'] = $secret === '' ? null : Crypt::encryptString($secret);
        $sd->forceFill(['platform_meta' => $meta])->save();
    }

    private function readSecret(StreamSessionDestination $sd): ?string
    {
        $enc = $sd->platform_meta['_secret'] ?? null;

        return $enc ? Crypt::decryptString($enc) : null;
    }
}
