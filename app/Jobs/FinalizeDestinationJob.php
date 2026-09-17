<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Destinations\ConnectorRegistry;
use App\Models\StreamSessionDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Ends platform-side broadcasts after the session stopped. */
class FinalizeDestinationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $sessionDestinationId) {}

    public function handle(ConnectorRegistry $connectors): void
    {
        $sd = StreamSessionDestination::withoutGlobalScopes()->with(['destination' => fn ($q) => $q->withoutGlobalScopes()])->find($this->sessionDestinationId);
        if (! $sd || ! $sd->destination) {
            return;
        }
        try {
            $connectors->for($sd->destination)->stopStream($sd);
        } catch (\Throwable) {
            // best effort
        }
        $sd->destination->forceFill(['status' => $sd->status === 'failed' ? 'failed' : 'idle'])->save();
    }
}
