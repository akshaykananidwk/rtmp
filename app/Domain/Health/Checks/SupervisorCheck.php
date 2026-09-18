<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use App\Domain\Streaming\SupervisorStatus;
use App\Models\StreamSessionDestination;

/**
 * The relay supervisor is what actually pushes video to the platforms. Without it a stream
 * looks live in the panel while every destination sits at "pending", so this check is
 * critical: it is the difference between "on air" and "nobody can see you".
 */
class SupervisorCheck implements HealthCheckInterface
{
    public function __construct(private readonly SupervisorStatus $status) {}

    public function name(): string
    {
        return 'supervisor';
    }

    public function label(): string
    {
        return 'Relay Supervisor';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $since = $this->status->secondsSinceBeat();
        $details = [
            'last_heartbeat_s_ago' => $since,
            'node' => $this->status->nodeId(),
            'start_command' => 'systemctl start akstream-supervisor',
        ];

        if ($this->status->isRunning()) {
            return CheckResult::pass($this->name(), 'Reconciling (last pass '.$since.'s ago)', $details);
        }

        try {
            $waiting = StreamSessionDestination::withoutGlobalScopes()
                ->whereIn('status', ['pending', 'connecting', 'reconnecting'])
                ->whereHas('session', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', ['detected', 'live']))
                ->count();
        } catch (\Throwable) {
            $waiting = 0;
        }

        $details['destinations_waiting'] = $waiting;
        $message = ($this->status->problem() ?? 'The relay supervisor is not running.')
            .' Start it with: systemctl start akstream-supervisor';

        // Stuck destinations mean it is failing right now, not merely idle.
        return $waiting > 0
            ? CheckResult::fail($this->name(), $waiting.' destination(s) are waiting. '.$message, $details)
            : CheckResult::warn($this->name(), $message, $details);
    }
}
