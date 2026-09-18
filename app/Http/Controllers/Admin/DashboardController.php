<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Health\HealthService;
use App\Domain\Storage\DiskMonitor;
use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\SupervisorStatus;
use App\Http\Controllers\Controller;
use App\Models\ScheduledStream;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics, HealthService $health, DiskMonitor $disk): View
    {
        $this->authorize('dashboard.view');

        return view('admin.dashboard', [
            'payload' => $this->payload(),
            'analytics' => $analytics->overview(7),
            'health' => $health->latest(),
            'disk' => $disk->usage(),
            'upcoming' => ScheduledStream::whereIn('status', ['scheduled', 'waiting_for_source'])->orderBy('scheduled_at')->limit(5)->get(),
            'recent' => StreamSession::where('status', 'ended')->latest('started_at')->limit(5)->get(),
        ]);
    }

    public function status(): JsonResponse
    {
        $this->authorize('dashboard.view');

        return response()->json($this->payload());
    }

    /** Real-time payload shared by dashboard + live page polling. */
    public static function payload(): array
    {
        $session = StreamSession::whereIn('status', ['detected', 'live'])->with('endpoint')->latest('started_at')->first();
        $summary = $session ? app(DistributionService::class)->summary($session) : ['total' => 0, 'live' => 0, 'connecting' => 0, 'failed' => 0, 'stopped' => 0];
        $destinations = $session
            ? $session->destinations()->with('destination')->get()->map(fn ($sd) => [
                'id' => $sd->id,
                'name' => $sd->destination?->name,
                'platform' => $sd->destination?->platform,
                'status' => $sd->status,
                'desired_state' => $sd->desired_state,
                'retry_count' => $sd->retry_count,
                'next_retry_at' => $sd->next_retry_at?->toIso8601String(),
                'last_error' => $sd->last_error,
                'last_error_at' => $sd->last_error_at?->diffForHumans(),
                'last_success_at' => $sd->last_success_at?->diffForHumans(),
                'bitrate' => $sd->outgoing_bitrate_kbps,
                'watch_url' => $sd->platform_meta['watch_url'] ?? null,
            ])->values()
            : collect();

        $supervisor = app(SupervisorStatus::class);

        $viewers = null;
        if ($session) {
            $sum = 0;
            $any = false;
            foreach ($session->destinations as $sd) {
                if (isset($sd->platform_meta['viewers'])) {
                    $sum += (int) $sd->platform_meta['viewers'];
                    $any = true;
                }
            }
            $viewers = $any ? $sum : null;
        }

        return [
            'live' => $session !== null,
            'session' => $session ? [
                'id' => $session->id,
                'title' => $session->title,
                'endpoint' => $session->endpoint?->name,
                'status' => $session->status,
                'started_at' => $session->started_at?->toIso8601String(),
                'duration' => $session->liveDuration(),
                'incoming_bitrate' => $session->incoming_bitrate_kbps,
                'outgoing_bitrate' => $session->outgoing_bitrate_kbps,
                'resolution' => $session->resolution,
                'fps' => $session->fps,
                'video_codec' => $session->video_codec,
                'audio_codec' => $session->audio_codec,
                'recording' => $session->recording_enabled,
                'distributing' => $session->distribution_started_at !== null,
            ] : null,
            'summary' => $summary,
            'destinations' => $destinations,
            // Nothing reaches a platform while this is down, and the destinations give no
            // clue — they just stay "pending" — so the panel has to say it out loud.
            'supervisor' => ['ok' => $supervisor->isRunning(), 'problem' => $supervisor->problem()],
            'viewers' => $viewers,
            'counts' => [
                'endpoints' => StreamEndpoint::where('is_enabled', true)->count(),
                'destinations' => StreamDestination::where('is_enabled', true)->count(),
            ],
            'server_time' => now()->toIso8601String(),
        ];
    }
}
