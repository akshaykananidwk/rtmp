<?php

declare(strict_types=1);

namespace App\Domain\Destinations\Connectors;

use App\Domain\Destinations\AbstractConnector;
use App\Domain\Destinations\OAuth\MetaOAuthService;
use App\Domain\Destinations\PlatformDefinition;
use App\Models\StreamSessionDestination;
use Illuminate\Support\Facades\Crypt;

/**
 * Facebook Live on a Page via the official Graph API:
 *  POST /{page-id}/live_videos → secure_stream_url → push RTMPS → POST /{id}?end_live_video=true
 */
class FacebookConnector extends AbstractConnector
{
    public function __construct(private readonly MetaOAuthService $oauth) {}

    public function platform(): string
    {
        return 'facebook';
    }

    public function definition(): PlatformDefinition
    {
        return new PlatformDefinition(
            name: 'facebook',
            label: 'Facebook Page Live',
            connectionMethod: 'oauth',
            oauthSupported: true,
            officialApi: true,
            support: 'supported',
            description: 'Official Meta Graph API (Live Video API). Requires a Facebook Page you manage and app permissions pages_manage_posts + publish_video (App Review needed for production). Personal profiles/groups are NOT supported by the current official API.',
            fields: [
                ['name' => 'page_id', 'label' => 'Page', 'type' => 'page_select', 'required' => false],
                ['name' => 'title', 'label' => 'Live title', 'type' => 'text', 'required' => false],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
                ['name' => 'stream_key', 'label' => 'Manual stream key (fallback, optional)', 'type' => 'password', 'required' => false, 'help' => 'From Facebook Live Producer; used only when no account is connected. These keys are single-use and expire within hours — once one is spent, publishing fails with "Operation not permitted". Connect the account above instead and a fresh key is fetched for every broadcast.'],
            ],
            defaultRtmpUrl: 'rtmps://live-api-s.facebook.com:443/rtmp',
            icon: '📘',
            docsUrl: 'https://developers.facebook.com/docs/live-video-api',
            setupSteps: [
                'Press "Connect Facebook" above and approve the permissions — this creates the destination for you.',
                'If you manage more than one Page, choose which Page to go live on and save.',
                'Start streaming from OBS, then press START LIVE. The Live video is created on the Page through the official API.',
                'No stream key to copy: the key is fetched per broadcast and never shown or stored in plain text.',
            ],
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
                : ['ok' => false, 'message' => 'Connect a Facebook account or provide a manual stream key'];
        }
        if (! $d->option('page_id')) {
            return ['ok' => false, 'message' => 'Select a Page'];
        }
        try {
            $userToken = $this->oauth->accessToken($d->platformAccount);
            $this->oauth->pageAccessToken($userToken, (string) $d->option('page_id'));

            return ['ok' => true, 'message' => 'Facebook Page access OK'];
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
        $userToken = $this->oauth->accessToken($d->platformAccount);
        $pageId = (string) $d->option('page_id');
        $pageToken = $this->oauth->pageAccessToken($userToken, $pageId);

        $res = $this->oauth->http()->asForm()->post($this->oauth->graph($pageId.'/live_videos'), [
            'access_token' => $pageToken,
            'status' => 'LIVE_NOW',
            'title' => mb_substr((string) ($d->option('title') ?: ($sd->session?->title ?? 'Live')), 0, 250),
            'description' => (string) $d->option('description', ''),
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('Facebook: failed to create live video — '.($res->json('error.message') ?? 'HTTP '.$res->status()));
        }

        $secure = (string) ($res->json('secure_stream_url') ?: $res->json('stream_url'));
        $meta = $sd->platform_meta ?? [];
        $meta['live_video_id'] = $res->json('id');
        $meta['page_id'] = $pageId;
        $meta['watch_url'] = 'https://www.facebook.com/'.$pageId.'/videos/'.$res->json('id');
        $meta['_secret'] = Crypt::encryptString($secure);
        $meta['_page_token'] = Crypt::encryptString($pageToken);
        $sd->forceFill(['platform_meta' => $meta])->save();
    }

    public function stopStream(StreamSessionDestination $sd): void
    {
        $meta = $sd->platform_meta ?? [];
        if (empty($meta['live_video_id']) || empty($meta['_page_token'])) {
            return;
        }
        try {
            $this->oauth->http()->asForm()->post($this->oauth->graph($meta['live_video_id']), [
                'access_token' => Crypt::decryptString($meta['_page_token']),
                'end_live_video' => 'true',
            ]);
        } catch (\Throwable) {
        }
    }

    public function getStatus(?StreamSessionDestination $sd = null): array
    {
        $base = parent::getStatus($sd);
        $meta = $sd?->platform_meta ?? [];
        if (! empty($meta['live_video_id']) && ! empty($meta['_page_token'])) {
            try {
                $res = $this->oauth->http()->get($this->oauth->graph($meta['live_video_id']), ['fields' => 'status,live_views', 'access_token' => Crypt::decryptString($meta['_page_token'])]);
                $base['platform_status'] = $res->json('status');
                $base['viewers'] = $res->json('live_views');
            } catch (\Throwable) {
            }
        }

        return $base;
    }

    public function rtmpTarget(StreamSessionDestination $sd): ?string
    {
        $d = $this->requireDestination();
        if ($d->platformAccount) {
            $enc = $sd->platform_meta['_secret'] ?? null;

            return $enc ? Crypt::decryptString($enc) : null; // secure_stream_url already contains the key
        }

        return $this->buildRtmpUrl($d->rtmp_url ?: 'rtmps://live-api-s.facebook.com:443/rtmp', $d->streamKey());
    }
}
