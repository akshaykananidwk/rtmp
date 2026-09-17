@extends('layouts.admin')
@section('title', 'Stream Test')
@section('content')
<div class="grid grid-2">
  <div class="card">
    @if(! $engineReachable)<div class="alert alert-warning">Streaming engine (MediaMTX) is not reachable from this server. Stream detection requires the VPS media stack — see <em>docs/STREAMING_SETUP.md</em>.</div>@endif
    @if($session)
      <h2>🟢 Incoming Stream Detected</h2>
      <table><tr><th>Stream key</th><td>{{ $session->endpoint?->name }}</td></tr><tr><th>Resolution</th><td>{{ $session->resolution ?? 'probing…' }}</td></tr><tr><th>FPS</th><td>{{ $session->fps ?? '—' }}</td></tr><tr><th>Bitrate</th><td>{{ $session->incoming_bitrate_kbps }} kbps</td></tr><tr><th>Codec</th><td>{{ $session->video_codec ?? '—' }}</td></tr><tr><th>Audio</th><td>{{ $session->audio_codec ?? '—' }}</td></tr><tr><th>Started</th><td>{{ $session->started_at?->format('H:i:s') }}</td></tr><tr><th>Distribution</th><td>{{ $session->distribution_started_at ? 'RUNNING since '.$session->distribution_started_at->format('H:i:s') : 'not started' }}</td></tr></table>
      @can('streams.control')<form method="post" action="{{ route('admin.live.start') }}" style="margin-top:16px">@csrf<button class="btn btn-success btn-lg">▶ Start Distribution</button></form>@endcan
    @else
      <h2>⚪ Waiting for incoming stream</h2>
      <p class="muted">Press <strong>Start Streaming</strong> in OBS. This page shows the detected source details when the encoder connects. Auto distribution is <strong>{{ app(\App\Domain\Settings\SettingsService::class)->bool('streaming','auto_distribution') ? 'ON' : 'OFF' }}</strong> (change in Settings → Streaming).</p>
      <a class="btn btn-outline" href="{{ route('admin.stream-test') }}">↻ Refresh</a>
    @endif
  </div>
  <div class="card"><h3>Enabled destinations ({{ $destinations->count() }})</h3>
    @forelse($destinations as $d)<div class="dest-row"><div><div class="name">{{ $d->name }} <span class="pill">{{ $d->platform }}</span></div><div class="meta">{{ $d->last_test_result ? 'Last test: '.$d->last_test_result.' '.$d->last_tested_at?->diffForHumans() : 'Not tested yet' }}</div></div>@include('components.badge', ['status' => $d->displayStatus()])</div>@empty<div class="empty">No destinations. <a href="{{ route('admin.destinations.create') }}">Add one</a>.</div>@endforelse
  </div>
</div>
@endsection
