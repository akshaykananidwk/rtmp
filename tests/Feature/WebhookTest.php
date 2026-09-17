<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Settings\SettingsService;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_verification_and_signature(): void
    {
        $s = app(SettingsService::class);
        $s->set('platforms', 'meta_app_secret', 'app-secret');
        $s->set('platforms', 'meta_webhook_verify_token', 'verify-me');

        $this->get('/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();

        $payload = json_encode(['object' => 'page', 'entry' => [['changes' => [['field' => 'live_videos', 'value' => ['status' => 'live']]]]]]);
        $sig = 'sha256='.hash_hmac('sha256', $payload, 'app-secret');
        $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $payload)->assertOk();
        $this->assertDatabaseHas('webhook_events', ['platform' => 'facebook', 'signature_status' => 'verified', 'event_type' => 'page']);

        $this->call('POST', '/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $payload)->assertStatus(401);
        $this->assertDatabaseHas('webhook_events', ['platform' => 'facebook', 'signature_status' => 'invalid']);
    }

    public function test_youtube_hub_challenge_and_generic_platform(): void
    {
        $this->get('/webhooks/youtube?hub_mode=subscribe&hub_challenge=abc')->assertOk()->assertSee('abc');
        $this->call('POST', '/webhooks/platform/twitch', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"type":"stream.online"}')->assertOk();
        $this->assertSame(1, WebhookEvent::where('platform', 'twitch')->count());
        $this->post('/webhooks/platform/../etc')->assertNotFound();
    }
}
