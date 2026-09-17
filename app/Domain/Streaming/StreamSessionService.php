<?php

declare(strict_types=1);

namespace App\Domain\Streaming;

use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Events\StreamStarted;
use App\Events\StreamStopped;
use App\Jobs\FinalizeDestinationJob;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use Illuminate\Support\Facades\DB;

/**
 * Handles the incoming-source lifecycle (encoder connected / disconnected)
 * as reported by the streaming engine hooks.
 */
class StreamSessionService
{
    public function __construct(
        private readonly StreamEngineInterface $engine,
        private readonly StreamLogger $logger,
        private readonly StreamProbe $probe,
        private readonly SettingsService $settings,
        private readonly DistributionService $distribution,
    ) {}

    /** Called when the engine reports the path is publishing. Idempotent. */
    public function onSourceReady(StreamEndpoint $endpoint, ?string $nodeId = null): StreamSession
    {
        $existing = $endpoint->sessions()->withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        if ($existing) {
            return $existing;
        }

        $session = DB::transaction(function () use ($endpoint, $nodeId) {
            $session = StreamSession::withoutGlobalScopes()->create([
                'tenant_id' => $endpoint->tenant_id,
                'stream_endpoint_id' => $endpoint->id,
                'title' => $endpoint->name.' – '.now()->format('d M Y H:i'),
                'status' => 'detected',
                'node_id' => $nodeId ?? config('akstream.streaming.node_id'),
                'started_at' => now(),
                'recording_enabled' => $endpoint->record_enabled || $this->settings->bool('streaming', 'recording_enabled'),
            ]);

            $endpoint->forceFill(['status' => 'live', 'last_seen_at' => now()])->save();

            return $session;
        });

        $this->logger->info($endpoint->tenant_id, 'stream.detected', 'Incoming stream detected on '.$endpoint->name, $session->id);

        $this->applyProbe($session, $endpoint);

        event(new StreamStarted($session));

        $auto = $endpoint->auto_distribute || $this->settings->bool('streaming', 'auto_distribution', (bool) config('akstream.streaming.auto_distribution'));
        if ($auto) {
            $this->distribution->start($session, null);
        }

        return $session;
    }

    /** Called when the engine reports the publisher left. */
    public function onSourceEnded(StreamEndpoint $endpoint): ?StreamSession
    {
        $session = $endpoint->sessions()->withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        $endpoint->forceFill(['status' => 'offline', 'last_seen_at' => now()])->save();

        if (! $session) {
            return null;
        }

        $this->end($session, 'source_disconnected');

        return $session;
    }

    public function end(StreamSession $session, string $reason = 'manual'): void
    {
        DB::transaction(function () use ($session): void {
            $session->forceFill([
                'status' => 'ended',
                'ended_at' => now(),
                'duration_seconds' => $session->liveDuration(),
            ])->save();

            $session->destinations()->withoutGlobalScopes()->update(['desired_state' => 'stopped']);
            $session->endpoint()->withoutGlobalScopes()->update(['status' => 'offline']);
        });

        foreach ($session->destinations()->withoutGlobalScopes()->pluck('id') as $sdId) {
            FinalizeDestinationJob::dispatch($sdId)->delay(now()->addSeconds(10));
        }

        $this->logger->info($session->tenant_id, 'stream.ended', 'Stream ended ('.$reason.') after '.gmdate('H:i:s', $session->duration_seconds), $session->id);

        event(new StreamStopped($session));
    }

    public function applyProbe(StreamSession $session, StreamEndpoint $endpoint): void
    {
        if (! $this->engine->isReachable()) {
            return;
        }

        $info = $this->probe->probe($this->engine->internalSourceUrl('live/'.$endpoint->plainKey()));
        if (! $info) {
            return;
        }

        $session->forceFill([
            'resolution' => $info['resolution'],
            'fps' => $info['fps'],
            'video_codec' => $info['video_codec'],
            'audio_codec' => $info['audio_codec'],
            'incoming_bitrate_kbps' => $info['bitrate_kbps'] ?? $session->incoming_bitrate_kbps,
        ])->save();

        $this->logger->info($session->tenant_id, 'stream.probed', sprintf('Source: %s @ %s fps, %s/%s', $info['resolution'] ?? '?', $info['fps'] ?? '?', $info['video_codec'] ?? '?', $info['audio_codec'] ?? '?'), $session->id);
    }

    /** Reconcile DB sessions with the engine's actual path list (fallback when hooks are missed). */
    public function syncWithEngine(): array
    {
        if (! $this->engine->isReachable()) {
            return ['reachable' => false];
        }

        $paths = $this->engine->listPaths();
        $started = 0;
        $ended = 0;
        $keys = app(StreamKeyService::class);

        // Sessions that the DB thinks are live but the engine no longer publishes
        foreach (StreamSession::withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->with('endpoint')->get() as $session) {
            $endpoint = $session->endpoint()->withoutGlobalScopes()->first();
            if (! $endpoint) {
                continue;
            }
            $path = 'live/'.$endpoint->plainKey();
            if (! isset($paths[$path]) || ! $paths[$path]['ready']) {
                $this->onSourceEnded($endpoint);
                $ended++;
            }
        }

        // Paths publishing that the DB does not know about
        foreach ($paths as $name => $info) {
            if (! $info['ready'] || ! str_starts_with($name, 'live/')) {
                continue;
            }
            $endpoint = $keys->findByKey(substr($name, 5));
            if ($endpoint && $endpoint->isUsable() && ! $endpoint->sessions()->withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->exists()) {
                $this->onSourceReady($endpoint);
                $started++;
            }
        }

        return ['reachable' => true, 'started' => $started, 'ended' => $ended];
    }
}
