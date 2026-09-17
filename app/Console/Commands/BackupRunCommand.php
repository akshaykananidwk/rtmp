<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupService;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run {--type=full : full|files|database} {--trigger=manual} {--prune : Delete expired backups afterwards}';

    protected $description = 'Create a backup of files and/or database';

    public function handle(BackupService $backups): int
    {
        $b = $backups->create((string) $this->option('type'), (string) $this->option('trigger'));
        $this->info('Backup '.$b->id.' completed ('.number_format($b->size_bytes / 1048576, 2).' MB)');
        if ($this->option('prune')) {
            $this->info('Pruned '.$backups->prune().' expired backup(s)');
        }

        return self::SUCCESS;
    }
}
