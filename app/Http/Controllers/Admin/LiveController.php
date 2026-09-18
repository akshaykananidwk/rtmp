<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\EmailVerifier;
use App\Domain\Accounts\UsageService;
use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Http\Controllers\Controller;
use App\Models\StreamDestination;
use App\Models\StreamDestinationLog;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LiveController extends Controller
{
    public function __construct(private readonly DistributionService $distribution) {}

    public function index(): View
    {
        $this->authorize('viewAny', StreamSession::class);

        return view('admin.live', [
            'payload' => DashboardController::payload(),
            'destinations' => StreamDestination::orderBy('sort_order')->get(),
            'endpoints' => StreamEndpoint::where('is_enabled', true)->get(),
            'logs' => StreamDestinationLog::latest('id')->limit(50)->get()->reverse()->values(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $this->authorize('control', StreamSession::class);
        $data = $request->validate(['destination_ids' => ['nullable', 'array'], 'destination_ids.*' => ['string', 'exists:stream_destinations,id'], 'session_id' => ['nullable', 'string']]);

        $session = StreamSession::whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        if (! $session) {
            return back()->with('error', 'No incoming stream detected. Start streaming from OBS first, then press Start Distribution.');
        }

        // A monthly allowance is only real if it stops something; refuse here, where the
        // operator is present to read why, rather than failing silently in the supervisor.
        if (app(EmailVerifier::class)->blocks($request->user())) {
            return back()->with('error', 'Confirm your e-mail address before streaming — use the link we sent to '.$request->user()->email.'.');
        }

        $usage = app(UsageService::class);
        if ($usage->exceeded($request->user())) {
            return back()->with('error', $usage->exceededMessage());
        }

        $destinations = ! empty($data['destination_ids']) ? StreamDestination::whereIn('id', $data['destination_ids'])->get() : null;
        $created = $this->distribution->start($session, $destinations, $request->user());

        return back()->with('status', 'Distribution started to '.$created->count().' destination(s).');
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->authorize('control', StreamSession::class);
        $session = StreamSession::whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        if (! $session) {
            return back()->with('error', 'No active stream.');
        }
        $this->distribution->stop($session, $request->user());
        if ($request->boolean('kick')) {
            $endpoint = $session->endpoint;
            $endpoint && app(StreamEngineInterface::class)->kickPublisher('live/'.$endpoint->plainKey());
        }

        return back()->with('status', 'Live stream stopped.');
    }

    public function restartDestination(Request $request, StreamSessionDestination $sessionDestination): RedirectResponse
    {
        $this->authorize('control', $sessionDestination->session);
        $this->distribution->restartDestination($sessionDestination, $request->user());

        return back()->with('status', 'Restart requested.');
    }

    public function stopDestination(Request $request, StreamSessionDestination $sessionDestination): RedirectResponse
    {
        $this->authorize('control', $sessionDestination->session);
        $this->distribution->stopDestination($sessionDestination, $request->user());

        return back()->with('status', 'Destination stopped.');
    }

    public function logs(Request $request): JsonResponse
    {
        $this->authorize('logs', StreamSession::class);
        $after = (int) $request->query('after', 0);
        $rows = StreamDestinationLog::where('id', '>', $after)->orderBy('id')->limit(200)->get()
            ->map(fn ($l) => ['id' => $l->id, 'time' => $l->created_at->format('H:i:s'), 'level' => $l->level, 'event' => $l->event, 'message' => $l->message]);

        return response()->json(['logs' => $rows]);
    }

    public function streamTest(StreamEngineInterface $engine): View
    {
        $this->authorize('viewAny', StreamSession::class);
        $session = StreamSession::whereIn('status', ['detected', 'live'])->with('endpoint')->latest('started_at')->first();

        return view('admin.stream-test', [
            'session' => $session,
            'engineReachable' => $engine->isReachable(),
            'endpoints' => StreamEndpoint::where('is_enabled', true)->get(),
            'destinations' => StreamDestination::where('is_enabled', true)->orderBy('sort_order')->get(),
        ]);
    }
}
