<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\DestinationFailed;
use App\Events\DestinationRecovered;
use App\Events\StreamStarted;
use App\Events\StreamStopped;
use App\Events\SystemAlert;
use Illuminate\Events\Dispatcher;

class StreamEventSubscriber
{
    public function __construct(private readonly Notifier $notifier) {}

    public function onStreamStarted(StreamStarted $e): void
    {
        $s = $e->session;
        $this->notifier->admins('stream.started', 'Stream started', ($s->title ?? 'Stream').' is receiving a live source.', 'info', $s->tenant_id, route('admin.live'));
    }

    public function onStreamStopped(StreamStopped $e): void
    {
        $s = $e->session;
        $this->notifier->admins('stream.stopped', 'Stream stopped', ($s->title ?? 'Stream').' ended after '.gmdate('H:i:s', $s->duration_seconds).'.', 'info', $s->tenant_id, route('admin.history.show', $s));
    }

    public function onDestinationFailed(DestinationFailed $e): void
    {
        $sd = $e->sessionDestination;
        $name = $sd->destination?->name ?? 'Destination';
        $this->notifier->admins('destination.failed', "$name failed", "$name stopped after ".$sd->retry_count.' retries: '.($sd->last_error ?? 'unknown error'), 'critical', $sd->tenant_id, route('admin.live'));
    }

    public function onDestinationRecovered(DestinationRecovered $e): void
    {
        $sd = $e->sessionDestination;
        $name = $sd->destination?->name ?? 'Destination';
        $this->notifier->admins('destination.recovered', "$name recovered", "$name reconnected and is live again.", 'info', $sd->tenant_id, route('admin.live'));
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
