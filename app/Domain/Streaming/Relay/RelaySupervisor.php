<?php

declare(strict_types=1);

namespace App\Domain\Streaming\Relay;

use App\Domain\Destinations\ConnectorRegistry;
use App\Domain\Overlays\OverlayRenderer;
use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Domain\Streaming\StreamLogger;
use App\Domain\Streaming\StreamSessionService;
use App\Events\DestinationFailed;
use App\Events\DestinationRecovered;
use App\Models\Overlay;
use App\Models\Recording;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use App\Support\SecretMasker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Long-running reconciler (run under systemd/supervisor as `php artisan stream:supervisor`).
 *
 * Desired state lives in the database (stream_session_destinations.desired_state), so any
 * number of web servers can request changes and any media node can execute them.
 * Each destination is an independent FFmpeg process with exponential-backoff retries.
 */
class RelaySupervisor
{
    /** @var array<string, FfmpegRelayProcess> keyed by session-destination id */
    private array $relays = [];

    /** @var array<string, FfmpegRelayProcess> keyed by session id */
    private array $recorders = [];

    /** @var array<string, FfmpegRelayProcess> branding (overlay) encoders, keyed by session id */
    private array $branders = [];

    /** @var array<string, array{bytes:int, at:float}> */
    private array $ingestStats = [];

    private array $startedAt = [];

    /** Endpoints resolved during the current tick, so one pass does not re-query per relay. */
    private array $endpointCache = [];

    public function __construct(
        private readonly StreamEngineInterface $engine,
        private readonly StreamLogger $logger,
        private readonly ConnectorRegistry $connectors,
        private readonly string $nodeId,
    ) {}

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    /** One reconciliation pass. Safe to call repeatedly. */
    public function tick(): void
    {
        $this->endpointCache = [];
        $this->reconcileBranding();
        $this->reconcileDestinations();
        $this->reconcileRecordings();
        $this->collectIngestStats();
    }

    public function shutdown(): void
    {
        foreach ($this->relays as $id => $relay) {
            $relay->stop();
            StreamSessionDestination::withoutGlobalScopes()->where('id', $id)->update(['status' => 'stopped', 'pid' => null, 'ended_at' => now()]);
        }
        foreach ($this->recorders as $sessionId => $rec) {
            $rec->stop();
            $this->finalizeRecording($sessionId);
        }
        foreach ($this->branders as $brander) {
            $brander->stop();
        }
        $this->relays = [];
        $this->recorders = [];
        $this->branders = [];
    }

    // ---------------------------------------------------------------- destinations

    private function reconcileDestinations(): void
    {
        $rows = StreamSessionDestination::withoutGlobalScopes()
            ->with(['destination' => fn ($q) => $q->withoutGlobalScopes(), 'session' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereHas('session', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', ['detected', 'live']))
            ->where(fn ($q) => $q->where('node_id', $this->nodeId)->orWhereNull('node_id'))
            ->get();

        $wanted = [];

        foreach ($rows as $sd) {
            $wanted[$sd->id] = true;
            $running = isset($this->relays[$sd->id]) && $this->relays[$sd->id]->isRunning();

            if (! $sd->wantsToRun()) {
                if (isset($this->relays[$sd->id])) {
                    $this->relays[$sd->id]->stop();
                    unset($this->relays[$sd->id], $this->startedAt[$sd->id]);
                }
                if ($sd->status !== 'stopped') {
                    $sd->forceFill(['status' => 'stopped', 'pid' => null, 'ended_at' => now()])->save();
                    $this->logger->info($sd->tenant_id, 'destination.stopped', ($sd->destination?->name ?? 'Destination').' stopped', $sd->stream_session_id, $sd->stream_destination_id);
                }

                continue;
            }

            if ($running) {
                // The overlay encoder usually goes live a few seconds after the relays start.
                // A relay holds whichever stream it was given, so without this it would keep
                // copying the un-branded source and the overlay would never reach the platform.
                $desired = $this->sourceFor($sd->session, $this->endpointFor($sd->session));

                if ($this->relays[$sd->id]->sourceUrl() !== $desired) {
                    $this->relays[$sd->id]->stop();
                    unset($this->relays[$sd->id], $this->startedAt[$sd->id]);
                    $this->logger->info(
                        $sd->tenant_id,
                        'destination.source_changed',
                        ($sd->destination?->name ?? 'Destination').(str_contains($desired, 'branded/') ? ' switching to the overlay stream' : ' switching back to the original stream'),
                        $sd->stream_session_id,
                        $sd->stream_destination_id,
                    );
                    $this->spawn($sd);

                    continue;
                }

                $this->updateRunning($sd);

                continue;
            }

            // Process is not running (never started, or exited)
            if (isset($this->relays[$sd->id])) {
                $this->handleExit($sd, $this->relays[$sd->id]);
                unset($this->relays[$sd->id], $this->startedAt[$sd->id]);

                continue;
            }

            if ($sd->status === 'failed') {
                continue; // gave up until operator restarts
            }

            if ($sd->next_retry_at && $sd->next_retry_at->isFuture()) {
                continue;
            }

            if ($sd->status === 'preparing') {
                continue; // platform-side API preparation still in progress
            }

            $this->spawn($sd);
        }

        // Kill relays whose rows disappeared / session ended
        foreach ($this->relays as $id => $relay) {
            if (! isset($wanted[$id])) {
                $relay->stop();
                unset($this->relays[$id], $this->startedAt[$id]);
                StreamSessionDestination::withoutGlobalScopes()->where('id', $id)->update(['status' => 'stopped', 'pid' => null, 'ended_at' => now()]);
            }
        }
    }

    private function spawn(StreamSessionDestination $sd): void
    {
        $destination = $sd->destination;
        $session = $sd->session;
        if (! $destination || ! $session) {
            return;
        }

        try {
            $connector = $this->connectors->for($destination);
            $target = $connector->rtmpTarget($sd);
        } catch (\Throwable $e) {
            $this->fail($sd, 'Cannot resolve target: '.$e->getMessage());

            return;
        }

        if ($target === null) {
            // Say which of the two things is missing; "no target configured" left the
            // operator guessing at the one moment they could least afford to.
            $definition = $this->connectors->definitions()[$destination->platform] ?? null;
            $this->fail($sd, $definition?->oauthSupported
                ? 'Nothing to publish to: connect a '.$definition->label.' account, or paste a stream key on this destination.'
                : 'Nothing to publish to: this destination has no stream key.');

            return;
        }

        $source = $this->sourceFor($session, $this->endpointFor($session));

        $relay = new FfmpegRelayProcess($sd->id, 'relay', $source, $target);
        try {
            $relay->start();
        } catch (\Throwable $e) {
            $this->scheduleRetry($sd, 'Failed to start ffmpeg: '.$e->getMessage());

            return;
        }

        $this->relays[$sd->id] = $relay;
        $this->startedAt[$sd->id] = microtime(true);

        $sd->forceFill([
            'status' => $sd->retry_count > 0 ? 'reconnecting' : 'connecting',
            'node_id' => $this->nodeId,
            'pid' => $relay->pid(),
            'started_at' => $sd->started_at ?? now(),
            'ended_at' => null,
        ])->save();

        $this->logger->info($sd->tenant_id, 'destination.connecting', $destination->name.($sd->retry_count > 0 ? ' reconnecting (attempt '.($sd->retry_count + 1).')' : ' connecting'), $sd->stream_session_id, $sd->stream_destination_id);
        $destination->forceFill(['status' => $sd->retry_count > 0 ? 'reconnecting' : 'connecting'])->save();
    }

    private function updateRunning(StreamSessionDestination $sd): void
    {
        $relay = $this->relays[$sd->id];
        $relay->poll();

        $uptime = microtime(true) - ($this->startedAt[$sd->id] ?? microtime(true));
        $changes = ['bytes_sent' => $relay->bytesOut(), 'outgoing_bitrate_kbps' => $relay->bitrateKbps()];

        if ($sd->status !== 'live' && ($relay->bytesOut() > 65536 || $uptime > 6)) {
            $wasRecovering = $sd->retry_count > 0;
            $changes += ['status' => 'live', 'last_success_at' => now(), 'retry_count' => 0, 'next_retry_at' => null, 'last_error' => null];
            $this->logger->info($sd->tenant_id, 'destination.live', ($sd->destination?->name ?? 'Destination').' connected', $sd->stream_session_id, $sd->stream_destination_id);
            $sd->destination?->forceFill(['status' => 'live', 'last_success_at' => now(), 'last_error' => null])->save();
            if ($wasRecovering) {
                event(new DestinationRecovered($sd));
            }
            $this->connectors->for($sd->destination)->onRelayLive($sd);
        } elseif ($sd->status === 'live') {
            $changes['last_success_at'] = now();
        }

        $sd->forceFill($changes)->save();
    }

    private function handleExit(StreamSessionDestination $sd, FfmpegRelayProcess $relay): void
    {
        $relay->poll();
        $error = $relay->lastError();
        $this->scheduleRetry($sd, $error);
    }

    private function scheduleRetry(StreamSessionDestination $sd, string $error): void
    {
        $max = (int) app(SettingsService::class)->get('streaming', 'retry_count', config('akstream.streaming.max_retries', 5));
        $backoff = (array) config('akstream.streaming.backoff', [5, 15, 30, 60]);
        $attempt = $sd->retry_count + 1;

        if ($attempt > $max) {
            $this->fail($sd, $error);

            return;
        }

        $delay = (int) ($backoff[min($attempt, count($backoff)) - 1] ?? end($backoff));

        $sd->forceFill([
            'status' => 'reconnecting',
            'pid' => null,
            'retry_count' => $attempt,
            'next_retry_at' => now()->addSeconds($delay),
            'last_error' => $error,
            'last_error_at' => now(),
        ])->save();

        $sd->destination?->forceFill(['status' => 'reconnecting', 'last_error' => $error, 'last_error_at' => now()])->save();
        $this->logger->warning($sd->tenant_id, 'destination.reconnecting', sprintf('%s disconnected: %s — retry %d/%d in %ds', $sd->destination?->name ?? 'Destination', $error, $attempt, $max, $delay), $sd->stream_session_id, $sd->stream_destination_id);
    }

    private function fail(StreamSessionDestination $sd, string $error): void
    {
        $error = SecretMasker::maskString($error);
        $sd->forceFill(['status' => 'failed', 'pid' => null, 'last_error' => $error, 'last_error_at' => now(), 'ended_at' => now(), 'next_retry_at' => null])->save();
        $sd->destination?->forceFill(['status' => 'failed', 'last_error' => $error, 'last_error_at' => now()])->save();
        $this->logger->error($sd->tenant_id, 'destination.failed', ($sd->destination?->name ?? 'Destination').' failed: '.$error, $sd->stream_session_id, $sd->stream_destination_id);
        event(new DestinationFailed($sd));
    }

    // ---------------------------------------------------------------- branding (overlays)

    /**
     * Where relays and the recorder read from: the branded stream when an overlay is
     * live, otherwise the raw ingest path. One encode feeds every destination.
     */
    private function endpointFor(StreamSession $session): ?StreamEndpoint
    {
        if (! array_key_exists($session->id, $this->endpointCache)) {
            $this->endpointCache[$session->id] = $session->endpoint()->withoutGlobalScopes()->first();
        }

        return $this->endpointCache[$session->id];
    }

    private function sourceFor(StreamSession $session, ?StreamEndpoint $endpoint): string
    {
        if ($endpoint === null) {
            return $this->engine->internalSourceUrl('live/unknown');
        }

        if ($session->branding_status === 'live') {
            return $this->engine->internalSourceUrl($this->brandedPath($endpoint));
        }

        return $this->engine->internalSourceUrl('live/'.$endpoint->plainKey());
    }

    private function brandedPath(StreamEndpoint $endpoint): string
    {
        return 'branded/'.$endpoint->plainKey();
    }

    private function reconcileBranding(): void
    {
        $sessions = StreamSession::withoutGlobalScopes()
            ->whereIn('status', ['detected', 'live'])
            ->whereNotNull('overlay_id')
            ->where(fn ($q) => $q->where('node_id', $this->nodeId)->orWhereNull('node_id'))
            ->get();

        $active = [];

        foreach ($sessions as $session) {
            $active[$session->id] = true;

            if (isset($this->branders[$session->id])) {
                $brander = $this->branders[$session->id];
                $brander->poll();

                if ($brander->isRunning()) {
                    if ($session->branding_status !== 'live' && $brander->bytesOut() > 65536) {
                        $session->forceFill(['branding_status' => 'live'])->save();
                        $this->logger->info($session->tenant_id, 'overlay.live', 'Overlay is being rendered into the stream', $session->id);
                    }

                    continue;
                }

                // The encoder exited – report it and let the next tick restart it
                $error = $brander->lastError();
                if ($note = $brander->slownessNote()) {
                    $error .= ' — '.$note;
                }
                unset($this->branders[$session->id]);
                $session->forceFill(['branding_status' => 'failed'])->save();
                $this->logger->error($session->tenant_id, 'overlay.failed', 'Overlay encoder stopped: '.$error, $session->id);

                continue;
            }

            $this->startBranding($session);
        }

        foreach ($this->branders as $sessionId => $brander) {
            if (! isset($active[$sessionId])) {
                $brander->stop();
                unset($this->branders[$sessionId]);
                StreamSession::withoutGlobalScopes()->where('id', $sessionId)->update(['branding_status' => null]);
            }
        }
    }

    private function startBranding(StreamSession $session): void
    {
        $endpoint = $session->endpoint()->withoutGlobalScopes()->first();
        $overlay = Overlay::withoutGlobalScopes()->find($session->overlay_id);

        if (! $endpoint || ! $overlay) {
            $session->forceFill(['overlay_id' => null, 'branding_status' => null])->save();

            return;
        }

        $renderer = app(OverlayRenderer::class);
        $built = $renderer->build($overlay);

        if ($built === null) {
            $session->forceFill(['branding_status' => null, 'overlay_id' => null])->save();
            $this->logger->warning($session->tenant_id, 'overlay.empty', 'Overlay "'.$overlay->name.'" has nothing to draw – streaming without it', $session->id);

            return;
        }

        if ($renderer->font() === null) {
            $this->logger->warning($session->tenant_id, 'overlay.no_font', 'No TrueType font found for text overlays – install fonts-dejavu or set OVERLAY_FONT', $session->id);
        }

        $source = $this->engine->internalSourceUrl('live/'.$endpoint->plainKey());
        $target = $this->engine->internalSourceUrl($this->brandedPath($endpoint));

        $brander = new FfmpegRelayProcess('brand-'.$session->id, 'brand', $source, $target, $renderer->encodeArguments($overlay, $built));

        try {
            $brander->start();
        } catch (\Throwable $e) {
            $session->forceFill(['branding_status' => 'failed'])->save();
            $this->logger->error($session->tenant_id, 'overlay.failed', 'Could not start the overlay encoder: '.$e->getMessage(), $session->id);

            return;
        }

        $this->branders[$session->id] = $brander;
        $session->forceFill(['branding_status' => 'starting'])->save();
        $this->logger->info($session->tenant_id, 'overlay.starting', 'Rendering overlay "'.$overlay->name.'" ('.$overlay->resolution.' @ '.$overlay->bitrate_kbps.' kbps)', $session->id);
    }

    // ---------------------------------------------------------------- recording

    private function reconcileRecordings(): void
    {
        $sessions = StreamSession::withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->where('recording_enabled', true)
            ->where(fn ($q) => $q->where('node_id', $this->nodeId)->orWhereNull('node_id'))->get();

        $active = [];
        foreach ($sessions as $session) {
            $active[$session->id] = true;
            if (isset($this->recorders[$session->id])) {
                $rec = $this->recorders[$session->id];
                $rec->poll();
                if (! $rec->isRunning()) {
                    $this->finalizeRecording($session->id, $rec->lastError());
                    unset($this->recorders[$session->id]);
                    $session->forceFill(['recording_enabled' => false])->save();
                }

                continue;
            }
            $this->startRecording($session);
        }

        foreach ($this->recorders as $sessionId => $rec) {
            if (! isset($active[$sessionId])) {
                $rec->stop();
                $this->finalizeRecording($sessionId);
                unset($this->recorders[$sessionId]);
            }
        }
    }

    private function startRecording(StreamSession $session): void
    {
        $endpoint = $session->endpoint()->withoutGlobalScopes()->first();
        if (! $endpoint) {
            return;
        }
        $disk = (string) config('akstream.recording.disk', 'local');
        $dir = trim((string) config('akstream.recording.path', 'recordings'), '/').'/'.$session->tenant_id;
        Storage::disk($disk)->makeDirectory($dir);
        $rel = $dir.'/'.now()->format('Ymd-His').'-'.substr($session->id, -6).'.mp4';
        $abs = Storage::disk($disk)->path($rel);

        $source = $this->sourceFor($session, $endpoint);
        $rec = new FfmpegRelayProcess($session->id, 'record', $source, $abs);
        try {
            $rec->start();
        } catch (\Throwable $e) {
            $this->logger->error($session->tenant_id, 'recording.failed', 'Recording could not start: '.$e->getMessage(), $session->id);
            $session->forceFill(['recording_enabled' => false])->save();

            return;
        }
        $this->recorders[$session->id] = $rec;

        Recording::withoutGlobalScopes()->create([
            'tenant_id' => $session->tenant_id,
            'stream_session_id' => $session->id,
            'title' => $session->title ?? $endpoint->name,
            'disk' => $disk,
            'path' => $rel,
            'format' => 'mp4',
            'resolution' => $session->resolution,
            'status' => 'recording',
            'started_at' => now(),
            'expires_at' => now()->addDays((int) config('akstream.recording.retention_days', 30)),
        ]);
        $session->forceFill(['recording_path' => $rel])->save();
        $this->logger->info($session->tenant_id, 'recording.started', 'Recording started', $session->id);
    }

    private function finalizeRecording(string $sessionId, ?string $error = null): void
    {
        $recording = Recording::withoutGlobalScopes()->where('stream_session_id', $sessionId)->where('status', 'recording')->latest()->first();
        if (! $recording) {
            return;
        }
        $disk = Storage::disk($recording->disk);
        $size = $disk->exists($recording->path) ? $disk->size($recording->path) : 0;
        $recording->forceFill([
            'status' => $size > 0 ? 'completed' : 'failed',
            'size_bytes' => $size,
            'ended_at' => now(),
            'duration_seconds' => $recording->started_at ? (int) $recording->started_at->diffInSeconds(now()) : 0,
        ])->save();
        $this->logger->info($recording->tenant_id, 'recording.stopped', 'Recording saved ('.number_format($size / 1048576, 1).' MB)'.($error ? ' – '.$error : ''), $sessionId);
    }

    // ---------------------------------------------------------------- ingest stats

    private function collectIngestStats(): void
    {
        static $lastRun = 0.0;
        if (microtime(true) - $lastRun < (int) config('akstream.streaming.stats_interval', 5)) {
            return;
        }
        $lastRun = microtime(true);

        if (! $this->engine->isReachable()) {
            return;
        }

        $paths = $this->engine->listPaths();
        $sessions = StreamSession::withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->get();

        foreach ($sessions as $session) {
            $endpoint = $session->endpoint()->withoutGlobalScopes()->first();
            if (! $endpoint) {
                continue;
            }
            $path = 'live/'.$endpoint->plainKey();
            if (! isset($paths[$path])) {
                continue;
            }
            $bytes = $paths[$path]['bytes_received'];
            $prev = $this->ingestStats[$session->id] ?? null;
            $kbps = $session->incoming_bitrate_kbps;
            if ($prev) {
                $dt = max(0.001, microtime(true) - $prev['at']);
                $kbps = (int) round((($bytes - $prev['bytes']) * 8 / 1000) / $dt);
            }
            $this->ingestStats[$session->id] = ['bytes' => $bytes, 'at' => microtime(true)];

            $outgoing = (int) StreamSessionDestination::withoutGlobalScopes()->where('stream_session_id', $session->id)->where('status', 'live')->sum('outgoing_bitrate_kbps');
            $bytesSent = (int) StreamSessionDestination::withoutGlobalScopes()->where('stream_session_id', $session->id)->sum('bytes_sent');

            $session->forceFill([
                'bytes_received' => $bytes,
                'incoming_bitrate_kbps' => max(0, $kbps),
                'outgoing_bitrate_kbps' => $outgoing,
                'bytes_sent' => $bytesSent,
                'duration_seconds' => $session->liveDuration(),
            ])->save();

            if (! $session->resolution) {
                try {
                    app(StreamSessionService::class)->applyProbe($session, $endpoint);
                } catch (\Throwable $e) {
                    Log::debug('probe failed: '.$e->getMessage());
                }
            }
        }
    }
}
