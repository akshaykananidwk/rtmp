<?php

use App\Domain\Recording\RecordingService;
use App\Domain\Scheduling\ScheduleService;
use App\Domain\Settings\SettingsService;
use App\Domain\Storage\DiskMonitor;
use App\Domain\Updates\GitHubClient;
use App\Events\SystemAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks (cron: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
*/

Schedule::call(fn () => app(ScheduleService::class)->runDue())->everyMinute()->name('scheduled-streams')->withoutOverlapping();

Schedule::command('stream:sync')->everyMinute()->withoutOverlapping()->when(fn () => config('akstream.streaming.engine') !== 'none');

Schedule::command('health:check --trigger=scheduler --alert')->everyTenMinutes()->withoutOverlapping();

Schedule::call(function (): void {
    $u = app(DiskMonitor::class)->usage();
    $key = 'alerts.disk.'.$u['level'];
    if ($u['level'] !== 'ok' && ! Cache::has($key)) {
        event(new SystemAlert('disk.'.$u['level'], 'Disk usage '.$u['level'], 'Storage disk is '.$u['percent'].'% full ('.round($u['free'] / 1073741824, 1).' GB free).', $u['level'] === 'critical' ? 'critical' : 'warning'));
        Cache::put($key, 1, now()->addHours(6));
    }
})->hourly()->name('disk-monitor');

Schedule::command('backup:run --type=full --trigger=scheduled --prune')
    ->dailyAt('02:30')
    ->when(fn () => app(SettingsService::class)->bool('backups', 'auto_enabled', true))
    ->withoutOverlapping();

Schedule::command('update:check --notify')->dailyAt('04:00')->when(fn () => app(GitHubClient::class)->isConfigured());

Schedule::call(fn () => app(RecordingService::class)->purgeExpired())->dailyAt('03:15')->name('recording-retention');

Schedule::command('logs:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::call(fn () => Cache::put('queue:heartbeat', now()->timestamp, 600))->everyMinute()->name('scheduler-heartbeat');
