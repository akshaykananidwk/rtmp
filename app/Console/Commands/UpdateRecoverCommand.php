<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Updates\UpdateManager;
use Illuminate\Console\Command;

class UpdateRecoverCommand extends Command
{
    protected $signature = 'update:recover';

    protected $description = 'Detect an interrupted update and roll it back safely';

    public function handle(UpdateManager $manager): int
    {
        $r = $manager->recover();
        $this->info($r ? 'Update '.$r->id.' is now: '.$r->status : 'No interrupted update found');

        return self::SUCCESS;
    }
}
