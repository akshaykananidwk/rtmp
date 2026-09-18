<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\UsageService;
use App\Domain\Streaming\DistributionService;
use App\Http\Controllers\Controller;
use App\Models\StreamDestination;
use App\Models\StreamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(private readonly DistributionService $distribution) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StreamSession::class);

        return response()->json(StreamSession::with('endpoint:id,name')->withCount('destinations')->latest('started_at')->paginate(25));
    }

    public function show(StreamSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        return response()->json(['data' => $session->load(['endpoint:id,name', 'destinations.destination:id,name,platform'])]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->authorize('control', StreamSession::class);
        abort_unless($request->user()->tokenCan('control'), 403, 'Token lacks the control ability.');
        $data = $request->validate(['destination_ids' => ['nullable', 'array'], 'destination_ids.*' => ['string']]);

        $session = StreamSession::whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        if (! $session) {
            return response()->json(['message' => 'No incoming stream detected.'], 409);
        }

        // The same allowance as the panel, so the API is not a way around it.
        $usage = app(UsageService::class);
        if ($usage->exceeded($request->user())) {
            return response()->json(['message' => $usage->exceededMessage()], 402);
        }
        $destinations = ! empty($data['destination_ids']) ? StreamDestination::whereIn('id', $data['destination_ids'])->get() : null;
        $created = $this->distribution->start($session, $destinations, $request->user());

        return response()->json(['message' => 'Distribution started.', 'session_id' => $session->id, 'destinations' => $created->count()]);
    }

    public function stop(Request $request): JsonResponse
    {
        $this->authorize('control', StreamSession::class);
        abort_unless($request->user()->tokenCan('control'), 403, 'Token lacks the control ability.');
        $session = StreamSession::whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        if (! $session) {
            return response()->json(['message' => 'No active stream.'], 409);
        }
        $this->distribution->stop($session, $request->user());

        return response()->json(['message' => 'Stream stopped.']);
    }

    public function logs(StreamSession $session): JsonResponse
    {
        $this->authorize('logs', $session);

        return response()->json(['data' => $session->logs()->orderBy('id')->limit(500)->get(['id', 'level', 'event', 'message', 'created_at'])]);
    }
}
