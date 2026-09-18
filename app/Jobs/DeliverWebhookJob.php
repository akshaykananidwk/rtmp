<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Webhooks\WebhookDispatcher;
use App\Models\OutgoingWebhook;
use App\Models\WebhookDelivery;
use App\Support\SecretMasker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        private readonly string $webhookId,
        private readonly string $event,
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        $webhook = OutgoingWebhook::withoutGlobalScopes()->find($this->webhookId);

        if (! $webhook || ! $webhook->is_enabled) {
            return;
        }

        $body = WebhookDispatcher::body($this->event, $this->payload);
        $started = microtime(true);
        $status = null;
        $error = null;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'AK-Computer-Webhook/1',
                'X-AK-Event' => $this->event,
                // Receivers verify this over the raw body to prove the call came from us.
                'X-AK-Signature' => WebhookDispatcher::signature($webhook->secret(), $body),
            ])->timeout(10)->withBody($body, 'application/json')->post($webhook->url);

            $status = $response->status();
            $succeeded = $response->successful();
            if (! $succeeded) {
                $error = 'HTTP '.$status;
            }
        } catch (\Throwable $e) {
            $succeeded = false;
            $error = SecretMasker::maskString($e->getMessage());
        }

        WebhookDelivery::create([
            'outgoing_webhook_id' => $webhook->id,
            'tenant_id' => $webhook->tenant_id,
            'event' => $this->event,
            'status_code' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'attempt' => $this->attempts(),
            'succeeded' => $succeeded,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
        ]);

        if ($succeeded) {
            $webhook->forceFill([
                'consecutive_failures' => 0,
                'last_delivered_at' => now(),
                'last_status' => $status,
                'last_error' => null,
            ])->save();

            return;
        }

        $failures = $webhook->consecutive_failures + 1;
        $changes = ['consecutive_failures' => $failures, 'last_status' => $status, 'last_error' => mb_substr((string) $error, 0, 500)];

        // A permanently dead endpoint is switched off rather than retried forever.
        if ($failures >= OutgoingWebhook::FAILURE_LIMIT) {
            $changes['is_enabled'] = false;
            $changes['disabled_at'] = now();
        }

        $webhook->forceFill($changes)->save();

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
        }
    }
}
