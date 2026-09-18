<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\OutgoingWebhook;

/**
 * Sends account events to endpoints the customer owns.
 *
 * Every event is queued, never delivered inside the request that caused it: a slow or
 * dead endpoint must not hold up starting a stream.
 */
class WebhookDispatcher
{
    /** The events an account can subscribe to, with what each one means. */
    public const EVENTS = [
        'stream.started' => 'A live source arrived and the stream began',
        'stream.stopped' => 'The stream ended',
        'destination.live' => 'A destination started receiving video',
        'destination.failed' => 'A destination gave up after its retries',
        'destination.recovered' => 'A destination reconnected after failing',
    ];

    public function dispatch(string $event, ?string $tenantId, array $payload): int
    {
        if ($tenantId === null || ! array_key_exists($event, self::EVENTS)) {
            return 0;
        }

        $sent = 0;

        foreach (OutgoingWebhook::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_enabled', true)->get() as $webhook) {
            if (! $webhook->listensFor($event)) {
                continue;
            }

            DeliverWebhookJob::dispatch($webhook->id, $event, $payload);
            $sent++;
        }

        return $sent;
    }

    /**
     * The exact bytes that get signed and sent.
     *
     * Kept here so the signature the receiver verifies is computed from the same string
     * that goes over the wire — re-encoding it anywhere else would break verification.
     */
    public static function body(string $event, array $payload): string
    {
        return json_encode([
            'event' => $event,
            'sent_at' => now()->toIso8601String(),
            'data' => $payload,
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public static function signature(string $secret, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }
}
