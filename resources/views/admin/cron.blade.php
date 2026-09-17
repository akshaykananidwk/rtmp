@extends('layouts.admin')
@section('title', 'Cron / Scheduler Setup')
@section('content')
<div class="grid grid-2">
<div class="card"><h3>1. Laravel scheduler (required everywhere)</h3><p class="muted small">Runs scheduled streams, health checks, backups, retention and update checks. Add ONE cron line:</p><pre class="code">* * * * * cd {{ $basePath }} && {{ $phpBinary }} artisan schedule:run >> /dev/null 2>&1</pre><p class="small muted">cPanel: Cron Jobs → "Every minute" → command above (use the PHP 8.3 binary path shown in cPanel, e.g. /usr/local/bin/php).</p>
<h3>2. Queue worker (required for notifications, platform API calls, updates)</h3><p class="muted small">VPS (systemd) — see docs/DEPLOYMENT.md. Shared hosting: run the worker from cron every minute:</p><pre class="code">* * * * * cd {{ $basePath }} && {{ $phpBinary }} artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1</pre></div>
<div class="card"><h3>3. Stream supervisor (VPS only)</h3><p class="muted small">Runs FFmpeg relays and recording on the media server. Install as a systemd service:</p><pre class="code">[Unit]
Description=AK Computer stream supervisor
After=network.target mediamtx.service

[Service]
User=www-data
WorkingDirectory={{ $basePath }}
ExecStart={{ $phpBinary }} artisan stream:supervisor
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target</pre>
<h3>4. Manual commands</h3><pre class="code">php artisan health:check
php artisan backup:run --type=full
php artisan update:check
php artisan update:install
php artisan update:recover
php artisan stream:sync</pre></div>
</div>
@endsection
