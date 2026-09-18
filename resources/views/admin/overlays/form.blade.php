@extends('layouts.admin')
@section('title', $overlay->exists ? 'Edit overlay' : 'New overlay')
@section('content')
@php($elements = old('elements', $overlay->elements ?? []))
<form method="post" action="{{ $overlay->exists ? route('admin.overlays.update', $overlay) : route('admin.overlays.store') }}" enctype="multipart/form-data">@csrf @if($overlay->exists)@method('PUT')@endif
<div class="grid grid-2">
  <div class="card">
    <h3>Output settings</h3>
    <div class="field"><label>Overlay name</label><input type="text" name="name" value="{{ old('name', $overlay->name) }}" required maxlength="100"></div>
    <div class="form-row">
      <div class="field"><label>Resolution</label><select name="resolution">@foreach(['1920x1080' => '1080p (1920×1080)', '1280x720' => '720p (1280×720)', '854x480' => '480p (854×480)'] as $v => $l)<option value="{{ $v }}" {{ old('resolution', $overlay->resolution) === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>Bitrate (kbps)</label><input type="number" name="bitrate_kbps" value="{{ old('bitrate_kbps', $overlay->bitrate_kbps ?? 4500) }}" min="500" max="20000"></div>
      <div class="field"><label>FPS</label><select name="fps">@foreach([25, 30, 50, 60] as $f)<option value="{{ $f }}" {{ (int) old('fps', $overlay->fps ?? 30) === $f ? 'selected' : '' }}>{{ $f }}</option>@endforeach</select></div>
    </div>
    <div class="field"><label>Encoder speed</label><select name="preset">@foreach(['ultrafast' => 'Ultrafast (lowest CPU, largest bitrate)', 'superfast' => 'Superfast', 'veryfast' => 'Veryfast (recommended)', 'faster' => 'Faster', 'fast' => 'Fast', 'medium' => 'Medium (best quality, most CPU)'] as $v => $l)<option value="{{ $v }}" {{ old('preset', $overlay->preset ?? 'veryfast') === $v ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label class="check"><input type="checkbox" name="is_default" value="1" {{ old('is_default', $overlay->is_default) ? 'checked' : '' }}> Mark as the default overlay</label></div>
    <div class="alert alert-info small">Adding an overlay means the video is re-encoded once on this server (roughly 1 CPU core for 1080p30 at "veryfast"). All destinations then copy that single branded stream, so ten platforms cost the same as one.</div>
    @if(! $font)<div class="alert alert-warning small">No font found — text and ticker elements will be skipped. Install with <span class="mono">apt install fonts-dejavu-core</span>.</div>@endif
  </div>

  <div class="card">
    <h3>Live text updates</h3>
    <p class="small muted">Text and ticker content is re-read by the encoder about once per second: while you are live, change the words here, press Save, and the on-screen text changes without interrupting the broadcast. Size, colour and position changes need the stream to restart.</p>
    <div class="divider"></div>
    <h3>Preview positions</h3>
    <div style="position:relative;aspect-ratio:16/9;background:#070a12;border:1px solid var(--border);border-radius:10px;overflow:hidden">
      @foreach($elements as $el)
        @php($pos = $el['position'] ?? 'bottom_left')
        @php($vpos = str_starts_with($pos, 'top') ? 'top:6%' : (str_starts_with($pos, 'bottom') ? 'bottom:6%' : 'top:46%'))
        @php($hpos = str_ends_with($pos, '_left') ? 'left:4%' : (str_ends_with($pos, '_right') ? 'right:4%' : 'left:50%;transform:translateX(-50%)'))
        @if(($el['enabled'] ?? false))
          <div style="position:absolute;{{ $vpos }};{{ $hpos }};font-size:{{ max(9, min(22, (int) ($el['size'] ?? 40) / 3)) }}px;color:{{ $el['color'] ?? '#fff' }};background:{{ ($el['type'] ?? '') === 'box' ? ($el['color'] ?? '#000') : 'rgba(0,0,0,.45)' }};padding:2px 8px;border-radius:4px;white-space:nowrap;max-width:92%;overflow:hidden">
            {{ ($el['type'] ?? '') === 'clock' ? now()->format('d-m-Y H:i') : (($el['type'] ?? '') === 'image' ? '🖼 logo' : (($el['type'] ?? '') === 'box' ? ' ' : \Illuminate\Support\Str::limit($el['text'] ?? '', 40))) }}
          </div>
        @endif
      @endforeach
      <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#243049;font-size:13px">video picture</div>
    </div>
    <p class="small muted" style="margin-top:8px">Rough guide only — the real position is calculated by the encoder.</p>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <div class="card-header"><h3>Elements</h3><span class="small muted">Leave a row's "Show" unticked to hide it without deleting</span></div>
  <div id="elements">
    @foreach($elements as $i => $el)
      @include('admin.overlays.element', ['i' => $i, 'el' => $el, 'types' => $types, 'positions' => $positions])
    @endforeach
  </div>
  <button type="button" class="btn btn-outline btn-sm" id="add-element">+ Add element</button>
  <div class="divider"></div>
  <button class="btn btn-primary">{{ $overlay->exists ? 'Save overlay' : 'Create overlay' }}</button>
  <a class="btn btn-outline" href="{{ route('admin.overlays.index') }}">Cancel</a>
</div>
</form>

<template id="element-template">
  @include('admin.overlays.element', ['i' => '__INDEX__', 'el' => ['type' => 'text', 'enabled' => true, 'position' => 'bottom_left', 'size' => 42, 'color' => '#ffffff', 'opacity' => 1, 'margin' => 40], 'types' => $types, 'positions' => $positions])
</template>
@push('scripts')
<script>
(function () {
  var box = document.getElementById('elements'), tpl = document.getElementById('element-template');
  var next = {{ count($elements) }};
  document.getElementById('add-element').addEventListener('click', function () {
    var html = tpl.innerHTML.replace(/__INDEX__/g, next++);
    var d = document.createElement('div'); d.innerHTML = html; box.appendChild(d.firstElementChild);
  });
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-remove-element]');
    if (b) { e.preventDefault(); b.closest('[data-element]').remove(); }
  });
  box.addEventListener('change', function (e) {
    if (!e.target.matches('[data-type-select]')) return;
    var row = e.target.closest('[data-element]'), t = e.target.value;
    row.querySelectorAll('[data-for]').forEach(function (f) {
      f.style.display = f.dataset.for.split(',').indexOf(t) === -1 ? 'none' : '';
    });
  });
  box.querySelectorAll('[data-type-select]').forEach(function (s) { s.dispatchEvent(new Event('change', { bubbles: true })); });
})();
</script>
@endpush
@endsection
