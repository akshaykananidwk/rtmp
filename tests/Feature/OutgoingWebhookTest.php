<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Streaming\StreamSessionService;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Jobs\DeliverWebhookJob;
use App\Models\OutgoingWebhook;
use App\Models\StreamEndpoint;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutgoingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebhook(string $tenantId, array $events = ['stream.started'], string $url = 'https://hooks.example.com/ak'): array
    {
        $secret = 'test-secret-value';
        $webhook = new OutgoingWebhook;
        $webhook->forceFill([
            'tenant_id' => $tenantId, 'name' => 'CRM', 'url' => $url,
            'events' => $events, 'is_enabled' => true,
        ]);
        $webhook->setSecret($secret);
        $webhook->save();

        return [$webhook, $secret];
    }

    public function test_the_form_creates_a_webhook_and_shows_the_secret_once(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);

        $this->post('/admin/webhooks', [
            'name' => 'My CRM',
            'url' => 'https://hooks.example.com/ak',
            'events' => ['stream.started', 'stream.stopped'],
        ])->assertRedirect();

        $secret = session('webhook_secret');
        $this->assertNotEmpty($secret);

        $webhook = OutgoingWebhook::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['stream.started', 'stream.stopped'], $webhook->events);
        $this->assertSame($secret, $webhook->secret(), 'the stored secret must decrypt to what was shown');
        $this->assertStringNotContainsString($secret, (string) $webhook->secret_encrypted, 'it is encrypted at rest');

        // Shown once on the page you land on, then never again.
        $this->get('/admin/webhooks')->assertOk()->assertSee($secret);
        $this->get('/admin/webhooks')->assertOk()->assertDontSee($secret);
    }

    public function test_plain_http_and_unknown_events_are_refused(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);

        $this->from('/admin/webhooks')->post('/admin/webhooks', ['name' => 'x', 'url' => 'http://insecure.example.com/h', 'events' => ['stream.started']])
            ->assertSessionHasErrors('url');
        $this->from('/admin/webhooks')->post('/admin/webhooks', ['name' => 'x', 'url' => 'https://ok.example.com/h', 'events' => ['not.a.real.event']])
            ->assertSessionHasErrors('events.0');

        $this->assertSame(0, OutgoingWebhook::withoutGlobalScopes()->count());
    }

    public function test_going_live_queues_a_delivery_only_for_subscribers_of_that_event(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        Queue::fake();

        $this->makeWebhook($tenant->id, ['stream.started']);
        $this->makeWebhook($tenant->id, ['destination.failed']);

        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        app(StreamSessionService::class)->onSourceReady($endpoint);

        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_another_accounts_webhook_is_never_called(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $other = $this->makeTenant('other');
        Queue::fake();

        $this->makeWebhook($other->id, ['stream.started']);

        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);
        app(StreamSessionService::class)->onSourceReady($endpoint);

        Queue::assertNotPushed(DeliverWebhookJob::class);
    }

    public function test_a_delivery_is_signed_over_the_exact_body_that_is_sent(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        [$webhook, $secret] = $this->makeWebhook($tenant->id);

        Http::fake(['https://hooks.example.com/*' => Http::response('', 200)]);

        (new DeliverWebhookJob($webhook->id, 'stream.started', ['session_id' => 'S1']))->handle();

        Http::assertSent(function ($request) use ($secret) {
            $body = $request->body();
            $expected = WebhookDispatcher::signature($secret, $body);

            // A receiver recomputes this over the raw body; anything else fails to verify.
            return hash_equals($expected, $request->header('X-AK-Signature')[0])
                && $request->header('X-AK-Event')[0] === 'stream.started'
                && str_contains($body, '"session_id":"S1"');
        });

        $webhook->refresh();
        $this->assertSame(200, $webhook->last_status);
        $this->assertSame(0, $webhook->consecutive_failures);
        $this->assertTrue(WebhookDelivery::withoutGlobalScopes()->firstOrFail()->succeeded);
    }

    public function test_a_failing_endpoint_is_recorded_and_eventually_switched_off(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        [$webhook] = $this->makeWebhook($tenant->id);

        Http::fake(['https://hooks.example.com/*' => Http::response('nope', 500)]);

        (new DeliverWebhookJob($webhook->id, 'stream.started', []))->handle();

        $webhook->refresh();
        $this->assertSame(1, $webhook->consecutive_failures);
        $this->assertSame(500, $webhook->last_status);
        $this->assertFalse(WebhookDelivery::withoutGlobalScopes()->firstOrFail()->succeeded);
        $this->assertTrue($webhook->is_enabled, 'one failure is not enough to give up');

        // A permanently dead endpoint stops being called rather than retried forever.
        $webhook->forceFill(['consecutive_failures' => OutgoingWebhook::FAILURE_LIMIT - 1])->save();
        (new DeliverWebhookJob($webhook->id, 'stream.started', []))->handle();

        $webhook->refresh();
        $this->assertFalse($webhook->is_enabled);
        $this->assertNotNull($webhook->disabled_at);
    }

    public function test_re_enabling_clears_the_strikes(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        [$webhook] = $this->makeWebhook($tenant->id);
        $webhook->forceFill(['is_enabled' => false, 'consecutive_failures' => OutgoingWebhook::FAILURE_LIMIT])->save();

        $this->post('/admin/webhooks/'.$webhook->id.'/toggle')->assertRedirect();

        $webhook->refresh();
        $this->assertTrue($webhook->is_enabled);
        $this->assertSame(0, $webhook->consecutive_failures, 'otherwise it would switch itself off again at once');
    }

    public function test_a_disabled_webhook_is_not_delivered_to(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        [$webhook] = $this->makeWebhook($tenant->id);
        $webhook->forceFill(['is_enabled' => false])->save();

        Http::fake();
        (new DeliverWebhookJob($webhook->id, 'stream.started', []))->handle();

        Http::assertNothingSent();
    }
}
