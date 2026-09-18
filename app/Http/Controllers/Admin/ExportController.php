<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\AnalyticsService;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\StreamSession;
use App\Support\SecretMasker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads of the data an account already sees on screen.
 *
 * Rows are streamed rather than assembled in memory, so a long history does not depend
 * on how much memory PHP happens to have.
 */
class ExportController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function history(): StreamedResponse
    {
        $this->authorize('viewAny', StreamSession::class);
        $this->audit->log('export.history');

        return $this->csv('stream-history', ['Started', 'Ended', 'Title', 'Stream key', 'Status', 'Duration (s)', 'Resolution', 'FPS', 'Received', 'Sent'], function () {
            foreach (StreamSession::with('endpoint')->latest('started_at')->cursor() as $session) {
                yield [
                    $session->started_at?->toDateTimeString(),
                    $session->ended_at?->toDateTimeString(),
                    $session->title,
                    $session->endpoint?->name,
                    $session->status,
                    $session->duration_seconds,
                    $session->resolution,
                    $session->fps,
                    $session->bytes_received,
                    $session->bytes_sent,
                ];
            }
        });
    }

    public function audit(): StreamedResponse
    {
        $this->authorize('logs.view');
        $this->audit->log('export.audit');

        $user = request()->user();

        return $this->csv('audit-log', ['When', 'User', 'Action', 'Result', 'Subject', 'IP', 'Details'], function () use ($user) {
            $query = ActivityLog::with('user')
                ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
                ->latest('id');

            foreach ($query->cursor() as $row) {
                yield [
                    $row->created_at?->toDateTimeString(),
                    $row->user?->email,
                    $row->action,
                    $row->result,
                    $row->subject_type ? class_basename($row->subject_type).' '.$row->subject_id : null,
                    $row->ip_address,
                    // Audit details can quote whatever triggered them, so mask before export.
                    SecretMasker::maskString(json_encode($row->context ?? [])),
                ];
            }
        });
    }

    public function analytics(Request $request, AnalyticsService $analytics): StreamedResponse
    {
        $this->authorize('analytics.view');
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $data = $analytics->overview($days);
        $this->audit->log('export.analytics', null, ['days' => $days]);

        return $this->csv('analytics-'.$days.'-days', ['Date', 'Streams', 'Duration (s)', 'Bytes', 'Errors'], function () use ($data) {
            foreach ($data['daily'] ?? [] as $day) {
                yield [$day['date'], $day['streams'], $day['duration'], $day['bytes'], $day['errors']];
            }
        });
    }

    private function csv(string $name, array $headers, callable $rows): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            // Excel reads UTF-8 correctly only with a byte-order mark.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
