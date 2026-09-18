@extends('layouts.admin')
@section('title', 'Analytics')
@section('content')
@php($h = \App\Domain\Analytics\AnalyticsService::class)
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
  <div class="tabs">@foreach([7, 30, 90] as $d)<a href="{{ route('admin.analytics', ['days' => $d]) }}" class="{{ $days === $d ? 'active' : '' }}">Last {{ $d }} days</a>@endforeach</div>
  <a class="btn btn-sm btn-outline" href="{{ route('admin.export.analytics', ['days' => $days]) }}">⬇ Download CSV</a>
</div>
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><h3>This month's usage</h3><span class="small muted">since {{ $usage['period_start'] }}</span></div>
  <div class="grid grid-4">
    <div><div class="small muted">Streams</div><div style="font-size:22px;font-weight:700">{{ $usage['streams'] }}</div></div>
    <div><div class="small muted">Streaming minutes</div><div style="font-size:22px;font-weight:700">{{ number_format($usage['minutes_used']) }}@if($usage['minutes_allowed'] > 0)<span class="small muted"> / {{ number_format($usage['minutes_allowed']) }}</span>@endif</div></div>
    <div><div class="small muted">Sent to platforms</div><div style="font-size:22px;font-weight:700">{{ $h::humanBytes($usage['bytes_sent']) }}</div></div>
    <div><div class="small muted">Recordings</div><div style="font-size:22px;font-weight:700">{{ $usage['recordings'] }}<span class="small muted"> · {{ $h::humanBytes($usage['recording_bytes']) }}</span></div></div>
  </div>
  @if($usage['minutes_allowed'] > 0)
    <div style="margin-top:12px;height:8px;background:var(--border);border-radius:4px;overflow:hidden">
      <div style="height:100%;width:{{ $usage['percent_used'] }}%;background:{{ $usage['percent_used'] >= 90 ? 'var(--danger,#e35)' : 'var(--primary,#4c7dff)' }}"></div>
    </div>
    <div class="small muted" style="margin-top:6px">{{ number_format($usage['minutes_left']) }} minutes left this month. The allowance resets on the 1st.</div>
  @else
    <div class="small muted" style="margin-top:12px">No monthly limit is set on this server.</div>
  @endif
</div>
<div class="grid grid-4" style="margin-bottom:18px">
  <div class="card"><div class="stat"><span class="label">Total streams</span><span class="value">{{ $data['total_streams'] }}</span></div></div>
  <div class="card"><div class="stat"><span class="label">Total duration</span><span class="value sm">{{ $h::humanDuration($data['total_duration']) }}</span></div></div>
  <div class="card"><div class="stat"><span class="label">Destinations</span><span class="value sm"><span style="color:#4ade80">{{ $data['destinations_ok'] }} ok</span> · <span style="color:#f87171">{{ $data['destinations_failed'] }} failed</span></span></div></div>
  <div class="card"><div class="stat"><span class="label">Bandwidth</span><span class="value sm">{{ $h::humanBytes($data['total_bytes']) }}</span></div><div class="small muted">avg {{ $data['avg_bitrate'] }} kbps · recordings {{ $h::humanBytes($data['recording_bytes']) }}</div></div>
</div>
@php($maxS = max(1, max(array_column($data['daily'], 'streams'))))@php($maxD = max(1, max(array_column($data['daily'], 'duration'))))@php($maxB = max(1, max(array_column($data['daily'], 'bytes'))))@php($maxE = max(1, max(array_column($data['daily'], 'errors'))))
<div class="grid grid-2">
  <div class="card"><h3>Daily streams</h3><div class="chart">@foreach($data['daily'] as $d)<div class="bar" style="height:{{ $d['streams'] / $maxS * 100 }}%" data-label="{{ $d['date'] }}: {{ $d['streams'] }}"></div>@endforeach</div></div>
  <div class="card"><h3>Streaming duration</h3><div class="chart">@foreach($data['daily'] as $d)<div class="bar" style="height:{{ $d['duration'] / $maxD * 100 }}%" data-label="{{ $d['date'] }}: {{ $h::humanDuration($d['duration']) }}"></div>@endforeach</div></div>
  <div class="card"><h3>Bandwidth usage</h3><div class="chart">@foreach($data['daily'] as $d)<div class="bar" style="height:{{ $d['bytes'] / $maxB * 100 }}%" data-label="{{ $d['date'] }}: {{ $h::humanBytes($d['bytes']) }}"></div>@endforeach</div></div>
  <div class="card"><h3>Errors</h3><div class="chart">@foreach($data['daily'] as $d)<div class="bar" style="height:{{ $d['errors'] / $maxE * 100 }}%;background:var(--red)" data-label="{{ $d['date'] }}: {{ $d['errors'] }}"></div>@endforeach</div></div>
</div>
<div class="card" style="margin-top:18px"><h3>Destination success rate</h3><table><thead><tr><th>Platform</th><th>Sessions</th><th>Succeeded</th><th>Failed</th><th>Rate</th></tr></thead><tbody>@forelse($data['by_platform'] as $p)<tr><td>{{ $p['platform'] }}</td><td>{{ $p['total'] }}</td><td style="color:#4ade80">{{ $p['succeeded'] }}</td><td style="color:#f87171">{{ $p['failed'] }}</td><td><div class="progress"><span style="width:{{ $p['total'] ? round($p['succeeded']/$p['total']*100) : 0 }}%"></span></div></td></tr>@empty<tr><td colspan="5" class="muted">No data</td></tr>@endforelse</tbody></table><p class="small muted" style="margin-top:10px">Platform-specific viewer analytics are shown only when the official API provides them.</p></div>
@endsection
