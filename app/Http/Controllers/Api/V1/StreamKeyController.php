<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Streaming\StreamKeyService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\StreamEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreamKeyController extends Controller
{
    public function index(StreamKeyService $keys): JsonResponse
    {
        $this->authorize('viewAny', StreamEndpoint::class);

        return response()->json(['rtmp_url' => $keys->publicRtmpUrl(), 'data' => StreamEndpoint::get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'slug' => $e->slug, 'key_hint' => $e->maskedKey(), 'is_enabled' => $e->is_enabled, 'status' => $e->status, 'last_seen_at' => $e->last_seen_at])]);
    }

    public function store(Request $request, StreamKeyService $keys, TenantContext $tenant): JsonResponse
    {
        $this->authorize('create', StreamEndpoint::class);
        abort_unless($request->user()->tokenCan('manage'), 403, 'Token lacks the manage ability.');
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $endpoint = $keys->create($tenant->get(), $request->user(), $data['name']);

        // Plaintext returned exactly once at creation
        return response()->json(['data' => ['id' => $endpoint->id, 'name' => $endpoint->name, 'rtmp_url' => $keys->publicRtmpUrl(), 'stream_key' => $endpoint->plainKey()]], 201)->header('Cache-Control', 'no-store');
    }
}
