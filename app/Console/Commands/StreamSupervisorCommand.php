<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Streaming\Relay\RelaySupervisor;
use App\Domain\Streaming\Relay\SupervisorRequirements;
use App\Domain\Streaming\SupervisorStatus;
use Illuminate\Console\Command;

class StreamSupervisorCommand extends Command
{
    protected $signature = 'stream:supervisor {--once : Run a single reconciliation pass} {--interval=2 : Seconds between passes}';

    protected $description = 'Long-running relay supervisor: starts/stops FFmpeg relays per destination, records, collects stats. Run under systemd/supervisor.';

    private bool $shouldStop = false;

    public function handle(RelaySupervisor $supervisor): int
    {
        // Check before touching anything: a disabled function here is a fatal error that
        // systemd would retry forever, leaving only "pending" destinations as the symptom.
        if ($blockers = SupervisorRequirements::blockers()) {
            foreach ($blockers as $blocker) {
                $this->error($blocker);
            }
            SupervisorStatus::recordBlockers($blockers);

            return self::FAILURE;
        }

        SupervisorStatus::recordBlockers([]);

        foreach (SupervisorRequirements::warnings() as $warning) {
            $this->warn($warning);
        }

        // Every piece is checked, including the constants: pcntl_async_signals can be allowed
        // while pcntl_signal is disabled, and calling it then kills the supervisor outright.
        if (SupervisorRequirements::canHandleSignals()) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            if (defined('SIGINT')) {
                pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
            }
        }

        $this->info('Relay supervisor started on node '.$supervisor->nodeId());
        $interval = max(1, (int) $this->option('interval'));

        do {
            try {
                $supervisor->tick();
                SupervisorStatus::beat($supervisor->nodeId());
            } catch (\Throwable $e) {
                $this->error('tick failed: '.$e->getMessage());
                report($e);
            }
            if ($this->option('once')) {
                break;
            }
            sleep($interval);
        } while (! $this->shouldStop);

        $supervisor->shutdown();
        $this->info('Relay supervisor stopped');

        return self::SUCCESS;
    }
}
