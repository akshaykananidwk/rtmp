<?php

declare(strict_types=1);

namespace App\Domain\Streaming;

use App\Models\StreamDestinationLog;
use App\Support\SecretMasker;
use Illuminate\Support\Facades\Log;

/**
 * Writes the "Live Logs" timeline. Kept lightweight: one row per meaningful
 * event (never per second). Old rows are pruned by the scheduler.
 */
class StreamLogger
{
    public function info(string $tenantId, string $event, string $message, ?string $sessionId = null, ?string $destinationId = null, array $context = []): void
    {
        $this->write('info', $tenantId, $event, $message, $sessionId, $destinationId, $context);
    }

    public function warning(string $tenantId, string $event, string $message, ?string $sessionId = null, ?string $destinationId = null, array $context = []): void
    {
        $this->write('warning', $tenantId, $event, $message, $sessionId, $destinationId, $context);
    }

    public function error(string $tenantId, string $event, string $message, ?string $sessionId = null, ?string $destinationId = null, array $context = []): void
    {
        $this->write('error', $tenantId, $event, $message, $sessionId, $destinationId, $context);
    }

    private function write(string $level, string $tenantId, string $event, string $message, ?string $sessionId, ?string $destinationId, array $context): void
    {
        $message = mb_substr(SecretMasker::maskString($message), 0, 1000);

        try {
            StreamDestinationLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'stream_session_id' => $sessionId,
                'stream_destination_id' => $destinationId,
                'level' => $level,
                'event' => $event,
                'message' => $message,
                'context' => SecretMasker::maskArray($context) ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stream log write failed: '.$e->getMessage());
        }

        Log::channel('streaming')->log($level === 'warning' ? 'warning' : ($level === 'error' ? 'error' : 'info'), "[$event] $message", ['session' => $sessionId, 'destination' => $destinationId]);
    }
}
