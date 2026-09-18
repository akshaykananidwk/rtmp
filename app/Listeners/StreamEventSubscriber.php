<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Events\DestinationFailed;
use App\Events\DestinationRecovered;
use App\Events\StreamStarted;
use App\Events\StreamStopped;
use App\Events\SystemAlert;
use Illuminate\Events\Dispatcher;

class StreamEventSubscriber
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function onStreamStarted(StreamStarted $e): void
    {
        $s = $e->session;
        $this->notifier->admins('stream.started', 'Stream started', ($s->title ?? 'Stream').' is receiving a live source.', 'info', $s->tenant_id, route('admin.live'));
        $this->webhooks->dispatch('stream.started', $s->tenant_id, [
            'session_id' => $s->id,
            'title' => $s->title,
            'started_at' => $s->started_at?->toIso8601String(),
        ]);
    }

    public function onStreamStopped(StreamStopped $e): void
    {
        $s = $e->session;
        $this->notifier->admins('stream.stopped', 'Stream stopped', ($s->title ?? 'Stream').' ended after '.gmdate('H:i:s', $s->duration_seconds).'.', 'info', $s->tenant_id, route('admin.history.show', $s));
        $this->webhooks->dispatch('stream.stopped', $s->tenant_id, [
            'session_id' => $s->id,
            'title' => $s->title,
            'duration_seconds' => $s->duration_seconds,
            'ended_at' => $s->ended_at?->toIso8601String(),
        ]);
    }

    public function onDestinationFailed(DestinationFailed $e): void
    {
        $sd = $e->sessionDestination;
        $name = $sd->destination?->name ?? 'Destination';
        $this->notifier->admins('destination.failed', "$name failed", "$name stopped after ".$sd->retry_count.' retries: '.($sd->last_error ?? 'unknown error'), 'critical', $sd->tenant_id, route('admin.live'));
        // No stream key or RTMP target here: the receiver gets what happened, not secrets.
        $this->webhooks->dispatch('destination.failed', $sd->tenant_id, [
            'session_id' => $sd->stream_session_id,
            'destination' => $name,
            'platform' => $sd->destination?->platform,
            'retries' => $sd->retry_count,
            'error' => $sd->last_error,
        ]);
    }

    public function onDestinationRecovered(DestinationRecovered $e): void
    {
        $sd = $e->sessionDestination;
        $name = $sd->destination?->name ?? 'Destination';
        $this->notifier->admins('destination.recovered', "$name recovered", "$name reconnected and is live again.", 'info', $sd->tenant_id, route('admin.live'));
        $this->webhooks->dispatch('destination.recovered', $sd->tenant_id, [
            'session_id' => $sd->stream_session_id,
            'destination' => $name,
            'platform' => $sd->destination?->platform,
        ]);
    }

    public function onSystemAlert(SystemAlert $e): void
    {
        $this->notifier->admins($e->type, $e->title, $e->message, $e->level, null, $e->meta['url'] ?? null, $e->meta);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            StreamStarted::class => 'onStreamStarted',
            StreamStopped::class => 'onStreamStopped',
            DestinationFailed::class => 'onDestinationFailed',
            DestinationRecovered::class => 'onDestinationRecovered',
            SystemAlert::class => 'onSystemAlert',
        ];
    }
}
