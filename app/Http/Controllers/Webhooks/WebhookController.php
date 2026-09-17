<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Destinations\OAuth\MetaOAuthService;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Support\SecretMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /** Meta webhooks: GET verification challenge + POST with X-Hub-Signature-256. */
    public function meta(Request $request, MetaOAuthService $meta): Response
    {
        if ($request->isMethod('get')) {
            $expected = (string) (app(SettingsService::class)->get('platforms', 'meta_webhook_verify_token', config('akstream.platforms.facebook.webhook_verify_token')) ?? '');
            if ($expected !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($expected, (string) $request->query('hub_verify_token'))) {
                return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
            }

            return response('Forbidden', 403);
        }

        $valid = $meta->verifyWebhookSignature($request->getContent(), $request->header('X-Hub-Signature-256'));
        $this->store('facebook', $request, $valid ? 'verified' : 'invalid');
        if (! $valid) {
            return response('Invalid signature', 401);
        }

        return response('OK');
    }

    /** YouTube push notifications (PubSubHubbub): GET hub.challenge + POST Atom feed with optional X-Hub-Signature. */
    public function youtube(Request $request): Response
    {
        if ($request->isMethod('get')) {
            if ($request->query('hub_mode') && $request->query('hub_challenge')) {
                return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
            }

            return response('Forbidden', 403);
        }

        $secret = (string) (app(SettingsService::class)->get('platforms', 'youtube_webhook_secret') ?? '');
        $status = 'unsupported';
        if ($secret !== '') {
            $sig = (string) $request->header('X-Hub-Signature', '');
            $expected = 'sha1='.hash_hmac('sha1', $request->getContent(), $secret);
            $status = hash_equals($expected, $sig) ? 'verified' : 'invalid';
        }
        $this->store('youtube', $request, $status);
        if ($status === 'invalid') {
            return response('Invalid signature', 401);
        }

        return response('OK');
    }

    public function platform(Request $request, string $name): Response
    {
        if (! preg_match('/^[a-z0-9_-]{2,32}$/', $name)) {
            return response('Not found', 404);
        }
        if ($request->isMethod('get')) {
            return response('OK');
        }
        $this->store($name, $request, 'unsupported');

        return response('OK');
    }

    private function store(string $platform, Request $request, string $signatureStatus): void
    {
        $payload = $request->isJson() ? $request->json()->all() : ['raw' => mb_substr($request->getContent(), 0, 10000)];
        try {
            WebhookEvent::create([
                'platform' => $platform,
                'event_type' => (string) ($payload['object'] ?? $payload['entry'][0]['changes'][0]['field'] ?? $request->header('X-Event-Type', 'unknown')),
                'signature_status' => $signatureStatus,
                'payload' => SecretMasker::maskArray($payload),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webhook store failed: '.$e->getMessage());
        }
    }
}
