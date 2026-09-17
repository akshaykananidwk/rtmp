@extends('layouts.installer')
@section('content')
<h2>✅ Installation Successful</h2>
<table><tr><th>Application URL</th><td><a href="{{ $result['app_url'] }}">{{ $result['app_url'] }}</a></td></tr><tr><th>Admin Panel URL</th><td><a href="{{ $result['admin_url'] }}">{{ $result['admin_url'] }}</a></td></tr><tr><th>Admin E-mail</th><td>{{ $result['admin_email'] }}</td></tr></table>
<div class="alert alert-success" style="margin-top:16px">The installer is now locked (<span class="mono">storage/app/installed.lock</span>). <span class="mono">/install</span> returns 404 from now on.</div>
<div class="alert alert-info small">Next steps: add the cron line (Admin → Cron Setup), configure the streaming engine on your VPS (docs/STREAMING_SETUP.md), connect platforms (Settings → Platforms) and set up GitHub auto-update (Admin → Updates).</div>
<a class="btn btn-primary btn-lg" href="{{ $result['admin_url'] }}">Go to Admin Login →</a>
@endsection
