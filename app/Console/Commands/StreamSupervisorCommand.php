<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Streaming\Relay\RelaySupervisor;
use App\Domain\Streaming\SupervisorStatus;
use Illuminate\Console\Command;

class StreamSupervisorCommand extends Command
{
    protected $signature = 'stream:supervisor {--once : Run a single reconciliation pass} {--interval=2 : Seconds between passes}';

    protected $description = 'Long-running relay supervisor: starts/stops FFmpeg relays per destination, records, collects stats. Run under systemd/supervisor.';

    private bool $shouldStop = false;

    public function handle(RelaySupervisor $supervisor): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
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
