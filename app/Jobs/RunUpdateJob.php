<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Updates\UpdateManager;
use App\Models\Update;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunUpdateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $updateId) {}

    public function uniqueId(): string
    {
        return 'system-update';
    }

    public function handle(UpdateManager $manager): void
    {
        @set_time_limit(0);
        $update = Update::find($this->updateId);
        if ($update && $update->state === 'checking') {
            $manager->run($update);
        }
    }
}
