@extends('layouts.admin')
@section('title', 'Live Stream')
@section('content')
@php($p = $payload)
<div data-status-url="{{ route('admin.dashboard.status') }}" data-interval="4000" data-initial='@json($p)'></div>
<div data-supervisor-alert class="alert alert-error" @if($p['supervisor']['ok']) style="display:none" @endif>
  <strong>Nothing is being sent to your destinations.</strong>
  <span data-supervisor-problem>{{ $p['supervisor']['problem'] }}</span>
  <div class="small" style="margin-top:8px">The relay supervisor is the process that pushes your video to each platform. On the server run:<br>
    <code>sudo systemctl start akstream-supervisor</code> &nbsp; then &nbsp; <code>sudo systemctl enable akstream-supervisor</code><br>
    If it will not start: <code>sudo journalctl -u akstream-supervisor -n 50 --no-pager</code>
  </div>
</div>
<div class="grid grid-2" style="margin-bottom:18px">
  <div class="card" style="text-align:center">
    <span data-live-indicator class="live-indicator {{ $p['live'] ? 'on' : 'off' }}"><span class="dot"></span> {{ $p['live'] ? 'LIVE' : 'OFFLINE' }}</span>
    <div style="margin:10px 0" class="muted"><span data-field="endpoint">{{ $p['session']['endpoint'] ?? '—' }}</span> · <span data-field="duration">00:00:00</span> · <span data-field="resolution">—</span> @ <span data-field="fps">—</span> fps · <span data-field="codec">—</span></div>
    <div class="muted small">↓ <span data-field="incoming">—</span> · ↑ <span data-field="outgoing">—</span> · Recording: <span data-field="recording">—</span></div>
    @can('streams.control')
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:16px">
      <form method="post" action="{{ route('admin.live.start') }}">@csrf<button class="btn btn-success btn-lg" {{ $p['live'] ? '' : 'disabled' }}>▶ START LIVE</button></form>
      <form method="post" action="{{ route('admin.live.stop') }}" data-confirm="Stop distribution to ALL destinations?">@csrf<button class="btn btn-danger btn-lg" {{ $p['live'] ? '' : 'disabled' }}>■ STOP LIVE</button></form>
      <a class="btn btn-outline btn-lg" href="{{ route('admin.live') }}">↻ REFRESH STATUS</a>
      <a class="btn btn-outline btn-lg" href="{{ route('admin.logs') }}">📜 VIEW LOGS</a>
    </div>
    @if(! $p['live'])<p class="small muted" style="margin-top:12px">Start streaming from OBS first. The source will appear here automatically, then press START LIVE to distribute.</p>@endif
    @endcan
  </div>
  <div class="card"><div class="card-header"><h3>Destinations</h3><a class="btn btn-sm btn-outline" href="{{ route('admin.destinations.index') }}">Manage</a></div>
    <div data-destinations data-can-control="{{ auth()->user()->can('streams.control') ? 1 : 0 }}" data-restart-url="{{ route('admin.live.destination.restart', '__ID__') }}" data-stop-url="{{ route('admin.live.destination.stop', '__ID__') }}"><div class="empty">No distribution running</div></div>
    @can('streams.control')
    @if($p['live'] && $destinations->count())
    <details style="margin-top:14px"><summary class="small muted" style="cursor:pointer">Start only selected destinations</summary>
      <form method="post" action="{{ route('admin.live.start') }}" style="margin-top:10px">@csrf
        @foreach($destinations as $d)<label class="check" style="margin-bottom:6px"><input type="checkbox" name="destination_ids[]" value="{{ $d->id }}" {{ $d->is_enabled ? 'checked' : 'disabled' }}> {{ $d->name }} <span class="pill">{{ $d->platform }}</span></label>@endforeach
        <button class="btn btn-sm btn-primary" style="margin-top:8px">Start selected</button>
      </form>
    </details>
    @endif
    @endcan
  </div>
</div>
@php($previewEndpoint = ($payload['session']['id'] ?? null) ? \App\Models\StreamEndpoint::where('name', $payload['session']['endpoint'])->first() : $endpoints->first())
@if($previewEndpoint)
<div class="card" style="margin-bottom:18px" data-preview="{{ route('admin.preview.status', $previewEndpoint) }}">
  <div class="card-header">
    <div><h3 style="margin:0">Live preview</h3><div class="small muted" data-preview-info>What your viewers are receiving right now</div></div>
    <div style="display:flex;gap:8px;align-items:center">
      <label class="check small" title="Compare with the picture OBS sends, before the overlay"><input type="checkbox" data-preview-raw> show source (no overlay)</label>
      <button class="btn btn-sm btn-outline" data-preview-reload>↻</button>
    </div>
  </div>
  <div style="position:relative;background:#070a12;border:1px solid var(--border);border-radius:10px;overflow:hidden;aspect-ratio:16/9">
    <video data-preview-video muted playsinline controls style="width:100%;height:100%;display:none;background:#000"></video>
    <div data-preview-placeholder style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted);gap:8px">
      <div style="font-size:2rem">📺</div><div data-preview-message>Waiting for a stream…</div>
    </div>
  </div>
  <p class="small muted" style="margin-top:8px">The preview is delayed a few seconds and is muted by default. It never exposes your stream key — the video is proxied through this panel.</p>
</div>
@endif

<div class="card"><div class="card-header"><h3>Live logs</h3><span class="small muted">auto-refresh</span></div>
  <div class="log-box" data-logs-url="{{ route('admin.live.logs') }}" data-after="{{ $logs->last()?->id ?? 0 }}">
    @foreach($logs as $l)<div class="log-line log-{{ $l->level }}"><span class="log-time">{{ $l->created_at->format('H:i:s') }}</span><span>{{ $l->message }}</span></div>@endforeach
  </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/hls.min.js') }}?v={{ $appVersion }}"></script>
@endpush
