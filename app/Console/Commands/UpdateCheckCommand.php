<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Updates\UpdateChecker;
use App\Events\SystemAlert;
use Illuminate\Console\Command;

class UpdateCheckCommand extends Command
{
    protected $signature = 'update:check {--notify : Notify admins when an update is available}';

    protected $description = 'Check GitHub for a newer version';

    public function handle(UpdateChecker $checker): int
    {
        $r = $checker->check();
        $this->line('Current: '.$r['current_version'].' ('.substr((string) $r['current_commit'], 0, 7).')');
        $this->line('Latest:  '.$r['latest_version'].' ('.substr((string) $r['commit']['sha'], 0, 7).') – '.strtok((string) $r['commit']['message'], "\n"));
        $this->line('Changed files: '.$r['changed_files']);
        $this->line($r['update_available'] ? 'UPDATE AVAILABLE' : 'Up to date');
        if ($r['update_available'] && $this->option('notify')) {
            event(new SystemAlert('update.available', 'Update available', 'Version '.$r['latest_version'].' is available on GitHub ('.strtok((string) $r['commit']['message'], "\n").').', 'info', ['url' => route('admin.updates.index')]));
        }

        return self::SUCCESS;
    }
}
