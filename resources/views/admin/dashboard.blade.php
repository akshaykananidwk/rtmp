@extends('layouts.admin')
@section('title', 'Dashboard')
@section('content')
@include('components.onboarding')
@php($p = $payload)
<div data-status-url="{{ route('admin.dashboard.status') }}" data-interval="5000" data-initial='@json($p)'></div>
<div class="grid grid-4" style="margin-bottom:18px">
  <div class="card" style="text-align:center"><div class="stat"><span class="label">Live status</span></div><div style="margin:10px 0"><span data-live-indicator class="live-indicator {{ $p['live'] ? 'on' : 'off' }}"><span class="dot {{ $p['live'] ? 'pulse' : '' }}"></span> {{ $p['live'] ? 'LIVE' : 'OFFLINE' }}</span></div><div class="muted small" data-field="title">{{ $p['session']['title'] ?? '—' }}</div></div>
  <div class="card"><div class="stat"><span class="label">Destinations</span><span class="value"><span data-field="dest_live">{{ $p['summary']['live'] }}</span><span class="muted" style="font-size:1rem"> / <span data-field="dest_total">{{ $p['summary']['total'] }}</span></span></span></div><div class="small muted">Live / connected · <span data-field="dest_connecting">{{ $p['summary']['connecting'] }}</span> connecting · <span style="color:#f87171" data-field="dest_failed">{{ $p['summary']['failed'] }}</span> failed</div><div class="small muted">{{ $p['counts']['destinations'] }} configured</div></div>
  <div class="card"><div class="stat"><span class="label">Viewers</span><span class="value" data-field="viewers">{{ $p['viewers'] ?? '—' }}</span></div><div class="small muted">From platform APIs where available</div></div>
  <div class="card"><div class="stat"><span class="label">Bandwidth</span><span class="value sm">↓ <span data-field="incoming">{{ $p['session']['incoming_bitrate'] ?? '—' }}{{ isset($p['session']) ? ' kbps' : '' }}</span></span><span class="value sm">↑ <span data-field="outgoing">{{ $p['session']['outgoing_bitrate'] ?? '—' }}{{ isset($p['session']) ? ' kbps' : '' }}</span></span></div></div>
</div>
<div class="grid grid-2" style="margin-bottom:18px">
  <div class="card">
    <div class="card-header"><h3>Current stream</h3><a class="btn btn-sm btn-outline" href="{{ route('admin.live') }}">Live control →</a></div>
    <div class="grid grid-3">
      @foreach([['Stream','endpoint'],['Started','started'],['Duration','duration'],['Resolution','resolution'],['FPS','fps'],['Codec','codec'],['Incoming','incoming'],['Outgoing','outgoing'],['Recording','recording']] as [$l,$f])
        <div class="stat"><span class="label">{{ $l }}</span><span class="value sm" data-field="{{ $f }}">—</span></div>
      @endforeach
    </div>
  </div>
  <div class="card"><div class="card-header"><h3>Destination health</h3></div><div data-destinations data-can-control="{{ auth()->user()->can('streams.control') ? 1 : 0 }}" data-restart-url="{{ route('admin.live.destination.restart', '__ID__') }}" data-stop-url="{{ route('admin.live.destination.stop', '__ID__') }}"><div class="empty">No destinations active</div></div></div>
</div>
<div class="grid grid-3">
  <div class="card"><div class="card-header"><h3>System health</h3><a class="small" href="{{ route('admin.health') }}">details</a></div>
    @forelse($health as $service => $row)<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)"><span>{{ ucwords(str_replace('_',' ',$service)) }}</span>@include('components.badge', ['status' => $row->status])</div>@empty<div class="empty">No health check yet. <a href="{{ route('admin.health') }}">Run one</a>.</div>@endforelse
    <div style="margin-top:12px"><div class="small muted">Disk usage {{ $disk['percent'] }}% ({{ round($disk['free']/1073741824,1) }} GB free)</div><div class="progress {{ $disk['level'] === 'critical' ? 'crit' : ($disk['level'] === 'warning' ? 'warn' : '') }}"><span style="width:{{ $disk['percent'] }}%"></span></div></div>
  </div>
  <div class="card"><div class="card-header"><h3>Upcoming schedules</h3><a class="small" href="{{ route('admin.schedules.index') }}">all</a></div>
    @forelse($upcoming as $s)<div style="padding:6px 0;border-bottom:1px solid var(--border)"><strong>{{ $s->title }}</strong><div class="small muted">{{ $s->scheduled_at->timezone($s->timezone)->format('d M Y, h:i A') }} ({{ $s->timezone }}) @include('components.badge', ['status' => $s->status])</div></div>@empty<div class="empty">Nothing scheduled</div>@endforelse
  </div>
  <div class="card"><div class="card-header"><h3>Last 7 days</h3><a class="small" href="{{ route('admin.analytics') }}">analytics</a></div>
    <div class="grid grid-2"><div class="stat"><span class="label">Streams</span><span class="value">{{ $analytics['total_streams'] }}</span></div><div class="stat"><span class="label">Duration</span><span class="value sm">{{ \App\Domain\Analytics\AnalyticsService::humanDuration($analytics['total_duration']) }}</span></div><div class="stat"><span class="label">Destinations OK</span><span class="value sm" style="color:#4ade80">{{ $analytics['destinations_ok'] }}</span></div><div class="stat"><span class="label">Failed</span><span class="value sm" style="color:#f87171">{{ $analytics['destinations_failed'] }}</span></div></div>
    <div class="divider"></div><h4 class="small muted">Recent streams</h4>
    @forelse($recent as $s)<div class="small" style="padding:4px 0"><a href="{{ route('admin.history.show', $s) }}">{{ $s->title }}</a> · {{ gmdate('H:i:s', $s->duration_seconds) }}</div>@empty<div class="small muted">None yet</div>@endforelse
  </div>
</div>
@endsection
