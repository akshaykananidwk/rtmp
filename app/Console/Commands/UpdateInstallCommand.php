<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Updates\UpdateManager;
use Illuminate\Console\Command;

class UpdateInstallCommand extends Command
{
    protected $signature = 'update:install {--sha= : Specific commit to install} {--recover : Recover an interrupted update first}';

    protected $description = 'Run the full backup → download → verify → install → migrate → health-check → activate pipeline';

    public function handle(UpdateManager $manager): int
    {
        if ($this->option('recover')) {
            $r = $manager->recover();
            $this->info($r ? 'Recovered update '.$r->id.' → '.$r->state : 'Nothing to recover');
        }
        $update = $manager->begin(null, $this->option('sha') ?: null);
        $this->info('Update '.$update->id.' started');
        $update = $manager->run($update);
        foreach ($update->logs as $log) {
            $this->line(sprintf('[%s] %-12s %s', $log->created_at->format('H:i:s'), $log->step, $log->message));
        }
        $this->line('Result: '.$update->status);

        return $update->state === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
