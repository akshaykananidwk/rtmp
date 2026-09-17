<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Streaming\StreamSessionService;
use Illuminate\Console\Command;

class StreamSyncCommand extends Command
{
    protected $signature = 'stream:sync';

    protected $description = 'Reconcile stream sessions with the media server (fallback for missed engine hooks)';

    public function handle(StreamSessionService $service): int
    {
        $r = $service->syncWithEngine();
        $this->line(json_encode($r));

        return self::SUCCESS;
    }
}
