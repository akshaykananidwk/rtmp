<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\WebhookDispatcher;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhookJob;
use App\Models\OutgoingWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('viewAny', OutgoingWebhook::class);

        return view('admin.webhooks.index', [
            'webhooks' => OutgoingWebhook::withCount('deliveries')->latest()->get(),
            'events' => WebhookDispatcher::EVENTS,
            'recent' => WebhookDelivery::with('webhook')->latest('id')->limit(25)->get(),
        ]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $this->authorize('create', OutgoingWebhook::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Only outward HTTPS calls; no scheme tricks and no plain HTTP off-box.
            'url' => ['required', 'url:https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_keys(WebhookDispatcher::EVENTS))],
        ]);

        $secret = Str::random(40);
        $webhook = new OutgoingWebhook;
        $webhook->forceFill([
            'tenant_id' => $tenant->id(),
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => array_values($data['events']),
            'is_enabled' => true,
        ]);
        $webhook->setSecret($secret);
        $webhook->save();

        $this->audit->log('webhook.created', $webhook, ['url' => $data['url'], 'events' => $data['events']]);

        return back()
            ->with('status', 'Webhook created. Copy the signing secret now — it is not shown again.')
            ->with('webhook_secret', $secret);
    }

    public function toggle(OutgoingWebhook $webhook): RedirectResponse
    {
        $this->authorize('update', $webhook);

        $webhook->forceFill([
            'is_enabled' => ! $webhook->is_enabled,
            // Re-enabling by hand clears the strikes, otherwise it would switch off at once.
            'consecutive_failures' => 0,
            'disabled_at' => $webhook->is_enabled ? now() : null,
        ])->save();

        return back()->with('status', $webhook->name.' '.($webhook->is_enabled ? 'enabled' : 'disabled').'.');
    }

    public function test(OutgoingWebhook $webhook): RedirectResponse
    {
        $this->authorize('update', $webhook);

        DeliverWebhookJob::dispatch($webhook->id, 'stream.started', [
            'session_id' => 'test-delivery',
            'title' => 'Test delivery from '.config('akstream.brand.name', 'AK COMPUTER'),
            'test' => true,
        ]);

        return back()->with('status', 'Test delivery queued — check the log below in a moment.');
    }

    public function destroy(OutgoingWebhook $webhook): RedirectResponse
    {
        $this->authorize('delete', $webhook);

        $webhook->delete();
        $this->audit->log('webhook.deleted', $webhook, ['name' => $webhook->name]);

        return back()->with('status', 'Webhook removed.');
    }
}
