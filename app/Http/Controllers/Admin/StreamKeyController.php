<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Streaming\StreamKeyService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\StreamEndpoint;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StreamKeyController extends Controller
{
    public function __construct(private readonly StreamKeyService $keys) {}

    public function index(): View
    {
        $this->authorize('viewAny', StreamEndpoint::class);

        return view('admin.stream-keys.index', [
            'endpoints' => StreamEndpoint::with('user')->withCount('destinations')->orderBy('name')->paginate(20),
            'rtmpUrl' => $this->keys->publicRtmpUrl(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', StreamEndpoint::class);

        return view('admin.stream-keys.form', ['endpoint' => new StreamEndpoint, 'users' => User::forTenant(auth()->user()->tenant_id)->orderBy('name')->get()]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $this->authorize('create', StreamEndpoint::class);
        $data = $this->validated($request);

        $user = ! empty($data['user_id']) ? User::forTenant($request->user()->tenant_id)->findOrFail($data['user_id']) : $request->user();
        $endpoint = $this->keys->create($tenant->get(), $user, $data['name'], $data['slug'] ?? null, [
            'auto_distribute' => (bool) ($data['auto_distribute'] ?? false),
            'record_enabled' => (bool) ($data['record_enabled'] ?? false),
        ]);

        return redirect()->route('admin.obs-setup', $endpoint)->with('status', 'Stream key created. Copy it into OBS now.')->with('revealed_key', $endpoint->plainKey());
    }

    public function edit(StreamEndpoint $streamKey): View
    {
        $this->authorize('update', $streamKey);

        return view('admin.stream-keys.form', ['endpoint' => $streamKey, 'users' => User::forTenant(auth()->user()->tenant_id)->orderBy('name')->get()]);
    }

    public function update(Request $request, StreamEndpoint $streamKey): RedirectResponse
    {
        $this->authorize('update', $streamKey);
        $data = $this->validated($request, $streamKey);
        $streamKey->update([
            'name' => $data['name'],
            'user_id' => ! empty($data['user_id']) ? User::forTenant($request->user()->tenant_id)->findOrFail($data['user_id'])->id : $streamKey->user_id,
            'auto_distribute' => (bool) ($data['auto_distribute'] ?? false),
            'record_enabled' => (bool) ($data['record_enabled'] ?? false),
        ]);
        app(AuditLogger::class)->log('stream_key.updated', $streamKey);

        return redirect()->route('admin.stream-keys.index')->with('status', 'Stream key updated.');
    }

    public function destroy(StreamEndpoint $streamKey): RedirectResponse
    {
        $this->authorize('delete', $streamKey);
        $this->keys->revoke($streamKey);
        $streamKey->delete();

        return redirect()->route('admin.stream-keys.index')->with('status', 'Stream key deleted.');
    }

    public function regenerate(StreamEndpoint $streamKey): RedirectResponse
    {
        $this->authorize('update', $streamKey);
        $key = $this->keys->regenerate($streamKey);

        return redirect()->route('admin.obs-setup', $streamKey)->with('status', 'Stream key regenerated. Update OBS with the new key.')->with('revealed_key', $key);
    }

    public function revoke(StreamEndpoint $streamKey): RedirectResponse
    {
        $this->authorize('update', $streamKey);
        $this->keys->revoke($streamKey);

        return back()->with('status', 'Stream key revoked. Encoders using it can no longer publish.');
    }

    public function toggle(StreamEndpoint $streamKey): RedirectResponse
    {
        $this->authorize('update', $streamKey);
        $this->keys->setEnabled($streamKey, ! $streamKey->is_enabled);

        return back()->with('status', $streamKey->is_enabled ? 'Endpoint enabled.' : 'Endpoint disabled.');
    }

    /** Returns the plaintext key once for the copy button (audited, rate limited). */
    public function reveal(StreamEndpoint $streamKey): JsonResponse
    {
        $this->authorize('reveal', $streamKey);
        app(AuditLogger::class)->log('stream_key.revealed', $streamKey);

        return response()->json(['key' => $streamKey->plainKey()])->header('Cache-Control', 'no-store');
    }

    private function validated(Request $request, ?StreamEndpoint $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'auto_distribute' => ['nullable', 'boolean'],
            'record_enabled' => ['nullable', 'boolean'],
        ]);
    }
}
