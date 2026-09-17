<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Audit\AuditLogger;
use App\Domain\Streaming\DistributionService;
use App\Domain\Streaming\StreamLogger;
use App\Domain\Streaming\StreamSessionService;
use App\Models\ScheduledStream;
use App\Models\StreamSession;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ScheduleService
{
    public function __construct(
        private readonly DistributionService $distribution,
        private readonly StreamSessionService $sessions,
        private readonly StreamLogger $logger,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, User $by): ScheduledStream
    {
        return DB::transaction(function () use ($data, $by) {
            $schedule = ScheduledStream::create(array_merge(Arr::except($data, ['destination_ids']), ['created_by' => $by->id, 'status' => 'scheduled']));
            $schedule->destinations()->sync($data['destination_ids'] ?? []);
            $this->audit->log('schedule.created', $schedule, ['title' => $schedule->title]);

            return $schedule;
        });
    }

    public function update(ScheduledStream $schedule, array $data): ScheduledStream
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule->update(Arr::except($data, ['destination_ids']));
            if (array_key_exists('destination_ids', $data)) {
                $schedule->destinations()->sync($data['destination_ids'] ?? []);
            }
            $this->audit->log('schedule.updated', $schedule);

            return $schedule;
        });
    }

    public function cancel(ScheduledStream $schedule): void
    {
        $schedule->update(['status' => 'cancelled']);
        $this->audit->log('schedule.cancelled', $schedule);
    }

    /**
     * Called every minute by the scheduler. Auto-starts distribution for due schedules whose
     * source is publishing; auto-stops sessions past their auto_stop_at.
     */
    public function runDue(): array
    {
        $started = 0;
        $stopped = 0;
        $missed = 0;

        $due = ScheduledStream::withoutGlobalScopes()
            ->whereIn('status', ['scheduled', 'waiting_for_source'])
            ->where('auto_start', true)
            ->where('scheduled_at', '<=', now())
            ->with(['endpoint' => fn ($q) => $q->withoutGlobalScopes(), 'destinations' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();

        foreach ($due as $schedule) {
            $endpoint = $schedule->endpoint;
            $session = $endpoint?->sessions()->withoutGlobalScopes()->whereIn('status', ['detected', 'live'])->latest('started_at')->first();

            if (! $session) {
                // Source not yet publishing: wait up to 30 minutes, then mark missed
                if ($schedule->scheduled_at->addMinutes(30)->isPast()) {
                    $schedule->update(['status' => 'missed']);
                    $this->logger->warning($schedule->tenant_id, 'schedule.missed', 'Scheduled stream "'.$schedule->title.'" missed: no incoming source');
                    $missed++;
                } elseif ($schedule->status !== 'waiting_for_source') {
                    $schedule->update(['status' => 'waiting_for_source']);
                    $this->logger->info($schedule->tenant_id, 'schedule.waiting', 'Schedule "'.$schedule->title.'" is due; waiting for OBS source on '.$endpoint?->name);
                }

                continue;
            }

            $session->forceFill(['scheduled_stream_id' => $schedule->id, 'title' => $schedule->title, 'recording_enabled' => $session->recording_enabled || $schedule->recording_enabled])->save();
            $this->distribution->start($session, $schedule->destinations);
            $schedule->update(['status' => 'live', 'stream_session_id' => $session->id, 'started_at' => now()]);
            $this->logger->info($schedule->tenant_id, 'schedule.started', 'Scheduled stream "'.$schedule->title.'" auto-started', $session->id);
            $started++;
        }

        $toStop = ScheduledStream::withoutGlobalScopes()->where('status', 'live')->where('auto_stop', true)->whereNotNull('auto_stop_at')->where('auto_stop_at', '<=', now())->get();
        foreach ($toStop as $schedule) {
            $session = StreamSession::withoutGlobalScopes()->find($schedule->stream_session_id);
            if ($session && $session->isActive()) {
                $this->sessions->end($session, 'schedule_auto_stop');
            }
            $schedule->update(['status' => 'completed', 'ended_at' => now()]);
            $stopped++;
        }

        // Mark live schedules whose session already ended
        foreach (ScheduledStream::withoutGlobalScopes()->where('status', 'live')->get() as $schedule) {
            $session = StreamSession::withoutGlobalScopes()->find($schedule->stream_session_id);
            if ($session && ! $session->isActive()) {
                $schedule->update(['status' => 'completed', 'ended_at' => $session->ended_at ?? now()]);
            }
        }

        return compact('started', 'stopped', 'missed');
    }
}
