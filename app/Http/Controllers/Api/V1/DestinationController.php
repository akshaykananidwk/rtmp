<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Destinations\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\DestinationRequest;
use App\Jobs\TestDestinationJob;
use App\Models\StreamDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function __construct(private readonly ConnectorRegistry $connectors, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', StreamDestination::class);

        return response()->json(['data' => StreamDestination::orderBy('sort_order')->get()->map(fn ($d) => $this->serialize($d))]);
    }

    public function show(StreamDestination $destination): JsonResponse
    {
        $this->authorize('view', $destination);

        return response()->json(['data' => $this->serialize($destination)]);
    }

    public function store(DestinationRequest $request): JsonResponse
    {
        $this->authorize('create', StreamDestination::class);
        abort_unless($request->user()->tokenCan('manage'), 403, 'Token lacks the manage ability.');
        $d = new StreamDestination;
        $this->fill($d, $request->validated());
        $d->save();
        $this->audit->log('destination.created', $d, ['via' => 'api']);

        return response()->json(['data' => $this->serialize($d)], 201);
    }

    public function update(DestinationRequest $request, StreamDestination $destination): JsonResponse
    {
        $this->authorize('update', $destination);
        abort_unless($request->user()->tokenCan('manage'), 403, 'Token lacks the manage ability.');
        $this->fill($destination, $request->validated());
        $destination->save();
        $this->audit->log('destination.updated', $destination, ['via' => 'api']);

        return response()->json(['data' => $this->serialize($destination)]);
    }

    public function destroy(Request $request, StreamDestination $destination): JsonResponse
    {
        $this->authorize('delete', $destination);
        abort_unless($request->user()->tokenCan('manage'), 403, 'Token lacks the manage ability.');
        $destination->delete();
        $this->audit->log('destination.deleted', $destination, ['via' => 'api']);

        return response()->json(['message' => 'Deleted.']);
    }

    public function test(StreamDestination $destination): JsonResponse
    {
        $this->authorize('test', $destination);
        TestDestinationJob::dispatchSync($destination->id);
        $destination->refresh();

        return response()->json(['result' => $destination->last_test_result, 'message' => $destination->last_test_result === 'pass' ? 'OK' : $destination->last_error]);
    }

    private function fill(StreamDestination $d, array $data): void
    {
        $definition = $this->connectors->definitions()[$data['platform']];
        $d->platform = $data['platform'];
        $d->name = $data['name'];
        $d->stream_endpoint_id = $data['stream_endpoint_id'] ?? null;
        $d->platform_account_id = $definition->oauthSupported ? ($data['platform_account_id'] ?? null) : null;
        $d->connection_method = $d->platform_account_id ? 'oauth' : 'rtmp';
        $d->rtmp_url = ($data['rtmp_url'] ?? null) ?: $definition->defaultRtmpUrl;
        if (! $d->exists) {
            $d->status = 'idle';
        }
        if (! empty($data['stream_key'])) {
            $d->setStreamKey($data['stream_key']);
        }
        $d->is_enabled = (bool) ($data['is_enabled'] ?? true);
        $d->sort_order = (int) ($data['sort_order'] ?? 0);
        $options = $d->options ?? [];
        foreach ($data as $k => $v) {
            if (str_starts_with($k, 'opt_')) {
                $options[substr($k, 4)] = $v;
            }
        }
        $d->options = $options;
    }

    /** Never exposes the stream key or encrypted fields. */
    private function serialize(StreamDestination $d): array
    {
        return [
            'id' => $d->id,
            'platform' => $d->platform,
            'name' => $d->name,
            'account_name' => $d->account_name,
            'connection_method' => $d->connection_method,
            'rtmp_url' => $d->rtmp_url,
            'stream_key_hint' => $d->maskedKey(),
            'is_enabled' => $d->is_enabled,
            'status' => $d->displayStatus(),
            'last_error' => $d->last_error,
            'last_error_at' => $d->last_error_at,
            'last_tested_at' => $d->last_tested_at,
            'last_test_result' => $d->last_test_result,
            'last_success_at' => $d->last_success_at,
            'options' => collect($d->options ?? [])->except(['_stream_id'])->all(),
        ];
    }
}
