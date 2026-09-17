<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>@yield('title', 'Dashboard') | {{ $brand['name'] }}</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('assets/img/favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ $appVersion }}">
</head>
<body>
@php($u = auth()->user())
<div class="admin">
  <aside class="sidebar" id="sidebar">
    <a class="logo" href="{{ route('admin.dashboard') }}">🔴 {{ $brand['name'] }}<small>ONE LIVE EVERYWHERE</small></a>
    <nav>
      @php($nav = [
        ['admin.dashboard', '📊', 'Dashboard', 'dashboard.view'],
        ['admin.live', '🔴', 'Live Stream', 'streams.view'],
        ['admin.stream-test', '🧪', 'Stream Test', 'streams.view'],
        ['admin.destinations.index', '📡', 'Destinations', 'destinations.view'],
        ['admin.schedules.index', '📅', 'Schedules', 'schedules.view'],
        ['admin.recordings.index', '🎬', 'Recordings', 'recordings.view'],
        ['admin.analytics', '📈', 'Analytics', 'analytics.view'],
        ['admin.history.index', '🕘', 'Stream History', 'streams.view'],
        ['admin.stream-keys.index', '🔑', 'Stream Keys', 'stream_keys.view'],
        ['admin.obs-setup', '🎥', 'OBS Setup', 'stream_keys.view'],
      ])
      @foreach($nav as [$route, $icon, $label, $perm])
        @can($perm)<a href="{{ route($route) }}" class="{{ request()->routeIs(str_replace('.index', '.*', $route)) || request()->routeIs($route) ? 'active' : '' }}">{{ $icon }} {{ $label }}</a>@endcan
      @endforeach
      <div class="sec">System</div>
      @can('users.view')<a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">👥 Users</a>@endcan
      @can('backups.view')<a href="{{ route('admin.backups.index') }}" class="{{ request()->routeIs('admin.backups.*') ? 'active' : '' }}">💾 Backups</a>@endcan
      @can('updates.view')<a href="{{ route('admin.updates.index') }}" class="{{ request()->routeIs('admin.updates.*') ? 'active' : '' }}">⬆️ Updates</a>@endcan
      @can('health.view')<a href="{{ route('admin.health') }}" class="{{ request()->routeIs('admin.health*') ? 'active' : '' }}">💚 Health</a>@endcan
      @can('logs.view')<a href="{{ route('admin.logs') }}" class="{{ request()->routeIs('admin.logs*') ? 'active' : '' }}">📜 Logs</a>@endcan
      @can('settings.view')<a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings*') ? 'active' : '' }}">⚙️ Settings</a>@endcan
      @can('settings.view')<a href="{{ route('admin.cron') }}" class="{{ request()->routeIs('admin.cron') ? 'active' : '' }}">⏱ Cron Setup</a>@endcan
    </nav>
    <div class="version">Version {{ $appVersion }}<br>{{ $brand['name'] }} · {{ $brand['phone'] }}</div>
  </aside>
  <div>
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:12px"><button class="btn btn-sm btn-outline menu-btn" data-toggle="#sidebar">☰</button><span class="title">@yield('title', 'Dashboard')</span></div>
      <div class="right">
        @php($unread = $u->unreadNotifications()->count())
        <a href="{{ route('admin.notifications') }}" class="notif" title="Notifications">🔔@if($unread)<span class="count">{{ $unread }}</span>@endif</a>
        <a href="{{ route('admin.profile') }}" title="Profile" style="display:flex;align-items:center;gap:8px;color:var(--text)"><span class="avatar">{{ strtoupper(substr($u->name, 0, 1)) }}</span><span class="small" style="display:none">{{ $u->name }}</span></a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-outline">Logout</button></form>
      </div>
    </div>
    <div class="main">
      @include('components.flash')
      @yield('content')
    </div>
  </div>
</div>
<script src="{{ asset('assets/js/app.js') }}?v={{ $appVersion }}" defer></script>
@stack('scripts')
</body>
</html>
