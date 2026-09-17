<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Destinations\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\DestinationRequest;
use App\Jobs\TestDestinationJob;
use App\Models\PlatformAccount;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DestinationController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $connectors, private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('viewAny', StreamDestination::class);

        return view('admin.destinations.index', [
            'destinations' => StreamDestination::with(['endpoint', 'platformAccount'])->orderBy('sort_order')->orderBy('name')->get(),
            'accounts' => PlatformAccount::with('token')->get(),
            'definitions' => $this->connectors->definitions(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', StreamDestination::class);

        return $this->form(new StreamDestination(['platform' => request('platform', 'custom_rtmp'), 'is_enabled' => true]));
    }

    public function store(DestinationRequest $request): RedirectResponse
    {
        $this->authorize('create', StreamDestination::class);
        $destination = DB::transaction(function () use ($request) {
            $d = new StreamDestination;
            $this->fill($d, $request->validated());
            $d->save();
            $this->audit->log('destination.created', $d, ['platform' => $d->platform, 'name' => $d->name]);

            return $d;
        });
        TestDestinationJob::dispatch($destination->id);

        return redirect()->route('admin.destinations.index')->with('status', 'Destination added. Connection test queued.');
    }

    public function edit(StreamDestination $destination): View
    {
        $this->authorize('update', $destination);

        return $this->form($destination);
    }

    public function update(DestinationRequest $request, StreamDestination $destination): RedirectResponse
    {
        $this->authorize('update', $destination);
        DB::transaction(function () use ($request, $destination): void {
            $this->fill($destination, $request->validated());
            $destination->save();
            $this->audit->log('destination.updated', $destination);
        });

        return redirect()->route('admin.destinations.index')->with('status', 'Destination updated.');
    }

    public function destroy(StreamDestination $destination): RedirectResponse
    {
        $this->authorize('delete', $destination);
        $destination->delete();
        $this->audit->log('destination.deleted', $destination, ['name' => $destination->name]);

        return redirect()->route('admin.destinations.index')->with('status', 'Destination removed.');
    }

    public function test(StreamDestination $destination): RedirectResponse
    {
        $this->authorize('test', $destination);
        TestDestinationJob::dispatchSync($destination->id);
        $destination->refresh();

        return back()->with($destination->last_test_result === 'pass' ? 'status' : 'error', $destination->last_test_result === 'pass' ? 'Test passed for '.$destination->name : 'Test failed: '.$destination->last_error);
    }

    public function toggle(StreamDestination $destination): RedirectResponse
    {
        $this->authorize('update', $destination);
        $destination->update(['is_enabled' => ! $destination->is_enabled, 'status' => $destination->is_enabled ? 'disabled' : 'idle']);
        $this->audit->log($destination->is_enabled ? 'destination.enabled' : 'destination.disabled', $destination);

        return back()->with('status', $destination->name.' '.($destination->is_enabled ? 'enabled' : 'disabled').'.');
    }

    private function form(StreamDestination $destination): View
    {
        return view('admin.destinations.form', [
            'destination' => $destination,
            'definitions' => $this->connectors->definitions(),
            'definition' => $this->connectors->definitions()[$destination->platform] ?? null,
            'endpoints' => StreamEndpoint::orderBy('name')->get(),
            'accounts' => PlatformAccount::where('platform', $destination->platform)->get(),
        ]);
    }

    private function fill(StreamDestination $d, array $data): void
    {
        $definition = $this->connectors->definitions()[$data['platform']];
        $d->platform = $data['platform'];
        $d->name = $data['name'];
        $d->stream_endpoint_id = $data['stream_endpoint_id'] ?? null;
        $d->platform_account_id = $definition->oauthSupported ? ($data['platform_account_id'] ?? null) : null;
        $d->account_name = $d->platform_account_id ? PlatformAccount::find($d->platform_account_id)?->name : ($data['account_name'] ?? null);
        $d->connection_method = $d->platform_account_id ? 'oauth' : 'rtmp';
        $d->rtmp_url = ($data['rtmp_url'] ?? null) ?: $definition->defaultRtmpUrl;
        if (! empty($data['stream_key'])) {
            $d->setStreamKey($data['stream_key']);
        }
        $d->is_enabled = (bool) ($data['is_enabled'] ?? true);
        $d->sort_order = (int) ($data['sort_order'] ?? 0);
        $options = $d->options ?? [];
        foreach ($definition->fields as $f) {
            if (in_array($f['name'], ['rtmp_url', 'stream_key'], true)) {
                continue;
            }
            if (array_key_exists('opt_'.$f['name'], $data)) {
                $options[$f['name']] = $data['opt_'.$f['name']];
            }
        }
        $d->options = $options;
        if (! $d->exists) {
            $d->status = 'idle';
        }
    }
}
