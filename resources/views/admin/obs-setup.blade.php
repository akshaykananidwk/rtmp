@extends('layouts.admin')
@section('title', 'OBS Setup')
@section('content')
@if(! $endpoint)<div class="empty">No stream key yet. <a href="{{ route('admin.stream-keys.create') }}">Generate one</a>.</div>@else
<div class="grid grid-2">
  <div class="card">
    <div class="card-header"><h3>Encoder settings</h3><form method="get" action="{{ route('admin.obs-setup') }}"><select name="__key" onchange="location.href='{{ route('admin.obs-setup') }}/'+this.value">@foreach($endpoints as $e)<option value="{{ $e->id }}" {{ $e->id === $endpoint->id ? 'selected' : '' }}>{{ $e->name }}</option>@endforeach</select></form></div>
    @if(session('revealed_key'))<div class="alert alert-warning"><strong>New stream key (shown once):</strong><div class="copy-row" style="margin-top:6px"><input type="text" id="new-key" value="{{ session('revealed_key') }}" readonly><button class="btn btn-sm btn-cyan" data-copy="#new-key">Copy</button></div></div>@endif
    <div class="field"><label>Server</label><div class="copy-row"><input type="text" id="srv" value="{{ $rtmpUrl }}" readonly><button class="btn btn-sm btn-outline" data-copy="#srv">Copy Server</button></div></div>
    <div class="field"><label>Stream Key</label><div class="copy-row"><input type="text" id="key" value="{{ $endpoint->maskedKey() }}" readonly>
      @if($canReveal)<button class="btn btn-sm btn-outline" data-reveal="{{ route('admin.stream-keys.reveal', $endpoint) }}" data-target="#key">Show</button><button class="btn btn-sm btn-cyan" data-copy-reveal="{{ route('admin.stream-keys.reveal', $endpoint) }}">Copy Stream Key</button>@endif</div>
      <div class="help">Status: @if(! $endpoint->isUsable())<span class="badge badge-failed">unusable (disabled/revoked)</span>@else@include('components.badge', ['status' => $endpoint->status])@endif · Reveal/copy actions are audited.</div></div>
    @can('update', $endpoint)<form method="post" action="{{ route('admin.stream-keys.regenerate', $endpoint) }}" data-confirm="Regenerate key? Update OBS afterwards.">@csrf<button class="btn btn-warning btn-sm">↻ Regenerate Key</button></form>@endcan
  </div>
  <div class="card">
    <h3>Instructions</h3>
    <details open><summary><strong>OBS Studio</strong></summary><ol class="muted"><li>Settings → Stream</li><li>Service: <strong>Custom…</strong></li><li>Server: paste the Server above</li><li>Stream Key: paste the key</li><li>Output → Encoder x264/NVENC, Keyframe interval <strong>2s</strong>, Bitrate 3500–6000 kbps, Audio AAC 160 kbps</li><li>Start Streaming → the dashboard shows “Incoming Stream Detected”</li></ol></details>
    <details><summary><strong>vMix</strong></summary><ol class="muted"><li>Stream (bottom bar) → Settings (gear)</li><li>Destination: <strong>Custom RTMP Server</strong></li><li>URL: Server above · Stream Name or Key: the key</li><li>Quality: 1080p 30/60 fps, keyframe 2 s</li><li>Click Start</li></ol></details>
    <details><summary><strong>Streamlabs Desktop</strong></summary><ol class="muted"><li>Settings → Stream → Stream Type: <strong>Custom Streaming Server</strong></li><li>URL: Server above · Stream key: the key</li><li>Go Live</li></ol></details>
    <div class="alert alert-info small" style="margin-top:12px">Keep the key secret. If it leaks, press <em>Regenerate</em> — the old key stops working instantly.</div>
  </div>
</div>
@endif
@endsection
