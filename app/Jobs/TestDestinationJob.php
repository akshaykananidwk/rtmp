<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Destinations\ConnectorRegistry;
use App\Models\StreamDestination;
use App\Support\SecretMasker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TestDestinationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $destinationId) {}

    public function handle(ConnectorRegistry $connectors): void
    {
        $d = StreamDestination::withoutGlobalScopes()->find($this->destinationId);
        if (! $d) {
            return;
        }
        try {
            $result = $connectors->for($d)->validate();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => SecretMasker::maskString($e->getMessage())];
        }
        $d->forceFill([
            'last_tested_at' => now(),
            'last_test_result' => $result['ok'] ? 'pass' : 'fail',
            'last_error' => $result['ok'] ? $d->last_error : $result['message'],
            'last_error_at' => $result['ok'] ? $d->last_error_at : now(),
        ])->save();
    }
}
