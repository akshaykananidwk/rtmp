<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Destinations\ConnectorRegistry;
use App\Domain\Streaming\StreamLogger;
use App\Events\DestinationFailed;
use App\Models\StreamSessionDestination;
use App\Support\SecretMasker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Platform-side preparation (create YouTube broadcast / Facebook live video)
 * before the relay process pushes bytes. Plain RTMP destinations skip straight to pending.
 */
class PrepareDestinationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly string $sessionDestinationId) {}

    public function handle(ConnectorRegistry $connectors, StreamLogger $logger): void
    {
        $sd = StreamSessionDestination::withoutGlobalScopes()->with(['destination' => fn ($q) => $q->withoutGlobalScopes(), 'session' => fn ($q) => $q->withoutGlobalScopes()])->find($this->sessionDestinationId);
        if (! $sd || ! $sd->destination || $sd->status !== 'preparing' || ! $sd->wantsToRun()) {
            return;
        }

        try {
            $connector = $connectors->for($sd->destination);
            $connector->startStream($sd);
            $sd->refresh();
            $sd->forceFill(['status' => 'pending'])->save();
            $logger->info($sd->tenant_id, 'destination.prepared', $sd->destination->name.' prepared on platform', $sd->stream_session_id, $sd->stream_destination_id, array_diff_key($sd->platform_meta ?? [], ['_secret' => 1, '_page_token' => 1]));
        } catch (\Throwable $e) {
            $msg = SecretMasker::maskString($e->getMessage());
            $sd->forceFill(['status' => 'failed', 'last_error' => $msg, 'last_error_at' => now(), 'ended_at' => now()])->save();
            $sd->destination->forceFill(['status' => 'failed', 'last_error' => $msg, 'last_error_at' => now()])->save();
            $logger->error($sd->tenant_id, 'destination.failed', $sd->destination->name.' preparation failed: '.$msg, $sd->stream_session_id, $sd->stream_destination_id);
            event(new DestinationFailed($sd));
        }
    }
}
