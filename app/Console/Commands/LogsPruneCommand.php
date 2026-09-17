<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ErrorLog;
use App\Models\HealthCheck;
use App\Models\StreamDestinationLog;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

class LogsPruneCommand extends Command
{
    protected $signature = 'logs:prune {--days=30}';

    protected $description = 'Rotate database logs (stream logs, health checks, webhook events, error logs, audit logs)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $c = 0;
        $c += StreamDestinationLog::withoutGlobalScopes()->where('created_at', '<', now()->subDays(min($days, 14)))->delete();
        $c += HealthCheck::where('created_at', '<', now()->subDays($days))->delete();
        $c += WebhookEvent::where('created_at', '<', now()->subDays($days))->delete();
        $c += ErrorLog::where('created_at', '<', now()->subDays($days * 3))->delete();
        $c += ActivityLog::where('created_at', '<', now()->subDays($days * 6))->delete();
        $this->info("Pruned $c rows");

        return self::SUCCESS;
    }
}
