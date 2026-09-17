<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Tenancy\TenantContext;
use App\Models\Recording;
use App\Models\StreamDestinationLog;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use Illuminate\Support\Facades\Cache;

class AnalyticsService
{
    public function overview(int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay();
        $tenant = app(TenantContext::class)->id();

        return Cache::remember("analytics.overview.$tenant.$days", 60, function () use ($since, $days) {
            $sessions = StreamSession::where('created_at', '>=', $since);
            $sd = StreamSessionDestination::where('created_at', '>=', $since);

            $daily = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = now()->subDays($i)->format('Y-m-d');
                $daily[$d] = ['date' => $d, 'streams' => 0, 'duration' => 0, 'bytes' => 0, 'errors' => 0];
            }
            foreach ((clone $sessions)->get(['started_at', 'duration_seconds', 'bytes_received', 'bytes_sent', 'created_at']) as $s) {
                $d = ($s->started_at ?? $s->created_at)->format('Y-m-d');
                if (isset($daily[$d])) {
                    $daily[$d]['streams']++;
                    $daily[$d]['duration'] += $s->duration_seconds;
                    $daily[$d]['bytes'] += $s->bytes_received + $s->bytes_sent;
                }
            }
            foreach (StreamDestinationLog::where('level', 'error')->where('created_at', '>=', $since)->get(['created_at']) as $l) {
                $d = $l->created_at->format('Y-m-d');
                if (isset($daily[$d])) {
                    $daily[$d]['errors']++;
                }
            }

            $byPlatform = StreamSessionDestination::query()
                ->join('stream_destinations', 'stream_destinations.id', '=', 'stream_session_destinations.stream_destination_id')
                ->where('stream_session_destinations.created_at', '>=', $since)
                ->selectRaw('stream_destinations.platform as platform, count(*) as total, sum(case when stream_session_destinations.last_success_at is not null then 1 else 0 end) as succeeded, sum(case when stream_session_destinations.status = ? then 1 else 0 end) as failed', ['failed'])
                ->groupBy('stream_destinations.platform')
                ->get()->map(fn ($r) => ['platform' => $r->platform, 'total' => (int) $r->total, 'succeeded' => (int) $r->succeeded, 'failed' => (int) $r->failed])->values()->all();

            return [
                'days' => $days,
                'total_streams' => (clone $sessions)->count(),
                'total_duration' => (int) (clone $sessions)->sum('duration_seconds'),
                'total_bytes' => (int) (clone $sessions)->sum('bytes_received') + (int) (clone $sessions)->sum('bytes_sent'),
                'destinations_ok' => (clone $sd)->whereNotNull('last_success_at')->count(),
                'destinations_failed' => (clone $sd)->where('status', 'failed')->count(),
                'recording_bytes' => (int) Recording::whereNull('deleted_at')->sum('size_bytes'),
                'recording_count' => Recording::count(),
                'daily' => array_values($daily),
                'by_platform' => $byPlatform,
                'avg_bitrate' => (int) (clone $sessions)->where('incoming_bitrate_kbps', '>', 0)->avg('incoming_bitrate_kbps'),
            ];
        });
    }

    public static function humanBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i >= 2 ? 2 : 0).' '.$units[$i];
    }

    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
    }
}
