@extends('layouts.admin')
@section('title', $overlay->exists ? 'Edit overlay' : 'New overlay')
@section('content')
@php($elements = old('elements', $overlay->elements ?? []))
@php($liveEndpoint = $endpoints->firstWhere('overlay_id', $overlay->id) ?? $endpoints->first())

<form method="post" action="{{ $overlay->exists ? route('admin.overlays.update', $overlay) : route('admin.overlays.store') }}" enctype="multipart/form-data" id="overlay-editor">@csrf @if($overlay->exists)@method('PUT')@endif

<div class="ov-layout">
  {{-- ------------------------------------------------ canvas ------------------------------------------------ --}}
  <div class="card">
    <div class="card-header">
      <div><h3 style="margin:0">Design</h3><div class="small muted">Drag to move · pull a corner to resize · arrow keys nudge (Shift = bigger steps)</div></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap" data-add-menu>
        <button type="button" class="btn btn-sm btn-outline" data-add="text">+ Text</button>
        <button type="button" class="btn btn-sm btn-outline" data-add="ticker">+ Ticker</button>
        <button type="button" class="btn btn-sm btn-outline" data-add="clock">+ Clock</button>
        <button type="button" class="btn btn-sm btn-outline" data-add="image">+ Logo</button>
        <button type="button" class="btn btn-sm btn-outline" data-add="box">+ Bar</button>
      </div>
    </div>

    <div class="ov-stage" data-stage>
      <div class="ov-frame" data-frame>
        @if($liveEndpoint)
          <video class="ov-video" data-editor-video muted playsinline
                 data-status-url="{{ route('admin.preview.status', $liveEndpoint) }}"></video>
        @endif
        <div class="ov-grid"></div>
        <div class="ov-hint" data-hint>Add an element from the buttons above</div>
      </div>
    </div>

    <div class="small muted" style="margin-top:10px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <span>Canvas = your real output ({{ old('resolution', $overlay->resolution ?? '1920x1080') }}). What you see here is what viewers get.</span>
      @if($liveEndpoint)<span data-editor-video-status>Live picture appears here while {{ $liveEndpoint->name }} is streaming</span>@endif
    </div>
  </div>

  {{-- ------------------------------------------------ elements ------------------------------------------------ --}}
  <div class="card">
    <div class="card-header"><h3 style="margin:0">Elements</h3><span class="small muted">click to select</span></div>
    <div id="element-list" class="ov-list">
      @foreach($elements as $i => $el)
        @include('admin.overlays.element', ['i' => $i, 'el' => $el])
      @endforeach
    </div>
  </div>
</div>

{{-- ------------------------------------------------ output settings ------------------------------------------------ --}}
<div class="card" style="margin-top:18px">
  <h3>Output</h3>
  <div class="form-row">
    <div class="field"><label>Overlay name</label><input type="text" name="name" value="{{ old('name', $overlay->name) }}" required maxlength="100"></div>
    <div class="field"><label>Resolution</label><select name="resolution">@foreach(['1920x1080' => '1080p (1920×1080)', '1280x720' => '720p (1280×720)', '854x480' => '480p (854×480)'] as $v => $l)<option value="{{ $v }}" {{ old('resolution', $overlay->resolution ?? '1920x1080') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Bitrate (kbps)</label><input type="number" name="bitrate_kbps" value="{{ old('bitrate_kbps', $overlay->bitrate_kbps ?? 4500) }}" min="500" max="20000"></div>
    <div class="field"><label>FPS</label><select name="fps">@foreach([25, 30, 50, 60] as $f)<option value="{{ $f }}" {{ (int) old('fps', $overlay->fps ?? 30) === $f ? 'selected' : '' }}>{{ $f }}</option>@endforeach</select></div>
    <div class="field"><label>Encoder speed</label><select name="preset">@foreach(['ultrafast' => 'Ultrafast (lowest CPU)', 'superfast' => 'Superfast', 'veryfast' => 'Veryfast (recommended)', 'faster' => 'Faster', 'fast' => 'Fast', 'medium' => 'Medium (best quality)'] as $v => $l)<option value="{{ $v }}" {{ old('preset', $overlay->preset ?? 'veryfast') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select></div>
  </div>
  <div class="field"><label class="check"><input type="checkbox" name="is_default" value="1" {{ old('is_default', $overlay->is_default) ? 'checked' : '' }}> Use as the default overlay</label></div>

  <div class="alert alert-info small">Text and ticker wording can be changed <strong>while you are live</strong> — save and the words swap over within a second. Moving, resizing and colour changes apply when the stream restarts.</div>

  <button class="btn btn-primary btn-lg">{{ $overlay->exists ? 'Save overlay' : 'Create overlay' }}</button>
  <a class="btn btn-outline" href="{{ route('admin.overlays.index') }}">Cancel</a>
</div>
</form>

<template id="element-template">@include('admin.overlays.element', ['i' => '__INDEX__', 'el' => ['type' => 'text', 'enabled' => true]])</template>

@push('scripts')
<script src="{{ asset('assets/js/hls.min.js') }}?v={{ $appVersion }}"></script>
<script src="{{ asset('assets/js/overlay-editor.js') }}?v={{ $appVersion }}"></script>
<script>
/* live picture behind the design canvas */
(function () {
  var video = document.querySelector('[data-editor-video]');
  if (!video || !window.Hls) return;
  var note = document.querySelector('[data-editor-video-status]');
  var hls = null;
  function tick() {
    fetch(video.dataset.statusUrl, { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.live) { if (note) note.textContent = 'No live stream — showing an empty canvas'; return; }
        if (note) note.textContent = 'Live picture from your stream';
        var url = j.raw_playlist || j.playlist;
        if (!url || hls) return;
        if (window.Hls.isSupported()) {
          hls = new window.Hls({ liveSyncDurationCount: 3 });
          hls.loadSource(url); hls.attachMedia(video);
          hls.on(window.Hls.Events.MANIFEST_PARSED, function () { video.play().catch(function () {}); });
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = url; video.play().catch(function () {});
        }
      }).catch(function () {});
  }
  tick(); setInterval(tick, 10000);
})();
</script>
@endpush
@endsection
