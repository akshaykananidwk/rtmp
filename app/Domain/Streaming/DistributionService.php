<?php

declare(strict_types=1);

namespace App\Domain\Streaming;

use App\Domain\Audit\AuditLogger;
use App\Domain\Destinations\ConnectorRegistry;
use App\Jobs\PrepareDestinationJob;
use App\Models\StreamDestination;
use App\Models\StreamSession;
use App\Models\StreamSessionDestination;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts / stops distribution of a live session to destinations.
 * Each destination is an independent relay: one failing never stops the others.
 */
class DistributionService
{
    public function __construct(
        private readonly StreamLogger $logger,
        private readonly AuditLogger $audit,
        private readonly ConnectorRegistry $connectors,
    ) {}

    /**
     * @param  Collection<int, StreamDestination>|null  $destinations  null = all enabled destinations of the endpoint (+ tenant-level ones)
     * @return Collection<int, StreamSessionDestination>
     */
    public function start(StreamSession $session, ?Collection $destinations = null, ?User $by = null): Collection
    {
        $destinations ??= $this->defaultDestinations($session);

        $created = collect();

        DB::transaction(function () use ($session, $destinations, &$created): void {
            foreach ($destinations as $destination) {
                if (! $destination->is_enabled) {
                    continue;
                }

                $existing = StreamSessionDestination::withoutGlobalScopes()
                    ->where('stream_session_id', $session->id)
                    ->where('stream_destination_id', $destination->id)
                    ->first();

                $initial = $this->connectors->for($destination)->requiresPreparation() ? 'preparing' : 'pending';

                if ($existing) {
                    $existing->forceFill(['desired_state' => 'running', 'status' => $initial, 'retry_count' => 0, 'next_retry_at' => null, 'ended_at' => null])->save();
                    $created->push($existing);

                    continue;
                }

                $created->push(StreamSessionDestination::withoutGlobalScopes()->create([
                    'tenant_id' => $session->tenant_id,
                    'stream_session_id' => $session->id,
                    'stream_destination_id' => $destination->id,
                    'desired_state' => 'running',
                    'status' => $initial,
                    'node_id' => $session->node_id,
                ]));
            }

            if ($session->status !== 'live') {
                $session->forceFill(['status' => 'live', 'distribution_started_at' => now()])->save();
            }
        });

        $this->logger->info($session->tenant_id, 'distribution.started', 'Distribution started to '.$created->count().' destination(s)', $session->id);
        $this->audit->log('stream.started', $session, ['destinations' => $created->count()], 'success', $by?->id);

        // Platform-side preparation (create YouTube broadcast / FB live video) happens in a queued job
        foreach ($created as $sd) {
            PrepareDestinationJob::dispatch($sd->id);
        }

        return $created;
    }

    public function stop(StreamSession $session, ?User $by = null): void
    {
        app(StreamSessionService::class)->end($session, 'stopped_by_'.($by?->name ?? 'system'));
        $this->audit->log('stream.stopped', $session, [], 'success', $by?->id);
    }

    public function stopDestination(StreamSessionDestination $sd, ?User $by = null): void
    {
        $sd->forceFill(['desired_state' => 'stopped'])->save();
        $this->logger->info($sd->tenant_id, 'destination.stop_requested', 'Stop requested for '.$sd->destination?->name, $sd->stream_session_id, $sd->stream_destination_id);
        $this->audit->log('destination.stopped', $sd->destination, [], 'success', $by?->id);
    }

    public function restartDestination(StreamSessionDestination $sd, ?User $by = null): void
    {
        $sd->forceFill(['desired_state' => 'running', 'status' => 'pending', 'retry_count' => 0, 'next_retry_at' => null, 'last_error' => null, 'ended_at' => null])->save();
        $this->logger->info($sd->tenant_id, 'destination.restart_requested', 'Restart requested for '.$sd->destination?->name, $sd->stream_session_id, $sd->stream_destination_id);
        $this->audit->log('destination.restarted', $sd->destination, [], 'success', $by?->id);
    }

    /** @return Collection<int, StreamDestination> */
    public function defaultDestinations(StreamSession $session): Collection
    {
        return StreamDestination::withoutGlobalScopes()
            ->where('tenant_id', $session->tenant_id)
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->where('stream_endpoint_id', $session->stream_endpoint_id)->orWhereNull('stream_endpoint_id'))
            ->orderBy('sort_order')
            ->get();
    }

    public function summary(StreamSession $session): array
    {
        $rows = $session->destinations()->withoutGlobalScopes()->get();

        return [
            'total' => $rows->count(),
            'live' => $rows->whereIn('status', ['live', 'connected'])->count(),
            'connecting' => $rows->whereIn('status', ['pending', 'connecting', 'reconnecting'])->count(),
            'failed' => $rows->where('status', 'failed')->count(),
            'stopped' => $rows->where('status', 'stopped')->count(),
        ];
    }
}
