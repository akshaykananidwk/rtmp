@extends('layouts.admin')
@section('title', $destination->exists ? 'Edit Destination' : 'Add Destination')
@section('content')
<div class="card" style="max-width:760px">
  <form method="post" action="{{ $destination->exists ? route('admin.destinations.update', $destination) : route('admin.destinations.store') }}">@csrf @if($destination->exists)@method('PUT')@endif
    <div class="form-row">
      <div class="field"><label>Platform</label><select name="platform" id="platform-select" {{ $destination->exists ? 'disabled' : '' }}>@foreach($definitions as $def)<option value="{{ $def->name }}" {{ old('platform', $destination->platform) === $def->name ? 'selected' : '' }}>{{ $def->icon }} {{ $def->label }}</option>@endforeach</select>@if($destination->exists)<input type="hidden" name="platform" value="{{ $destination->platform }}">@endif</div>
      <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $destination->name) }}" required maxlength="100" placeholder="My YouTube channel"></div>
    </div>
    @foreach($definitions as $def)
      <div data-platform-info="{{ $def->name }}">
        <div class="alert {{ $def->support === 'supported' ? 'alert-info' : 'alert-warning' }} small">{{ $def->description }}</div>
        @if($def->setupSteps)
          <details class="card" style="margin:0 0 16px;padding:12px 14px" {{ $def->support === 'supported' ? '' : 'open' }}>
            <summary style="cursor:pointer;font-weight:600">{{ $def->icon }} How to set up {{ $def->label }} — step by step</summary>
            <ol style="margin:10px 0 0 18px;padding:0;line-height:1.7" class="small">
              @foreach($def->setupSteps as $step)<li>{{ $step }}</li>@endforeach
            </ol>
            @if($def->docsUrl)<div class="help" style="margin-top:8px">Official documentation: <a href="{{ $def->docsUrl }}" target="_blank" rel="noopener">{{ $def->docsUrl }}</a></div>@endif
          </details>
        @endif
      </div>
    @endforeach
    <div class="form-row">
      <div class="field"><label>Bind to stream key (optional)</label><select name="stream_endpoint_id"><option value="">All stream keys</option>@foreach($endpoints as $e)<option value="{{ $e->id }}" {{ old('stream_endpoint_id', $destination->stream_endpoint_id) === $e->id ? 'selected' : '' }}>{{ $e->name }}</option>@endforeach</select><div class="help">Leave empty to use this destination for every stream key.</div></div>
      <div class="field"><label>Account name / label</label><input type="text" name="account_name" value="{{ old('account_name', $destination->account_name) }}" maxlength="100"></div>
    </div>
    @foreach($definitions as $def)
      {{-- Every platform's fields live in the DOM at once and are only shown/hidden, so their
           names are scoped per platform. Sharing bare names let a hidden platform's empty box
           overwrite the one actually filled in. DestinationRequest lifts the chosen scope. --}}
      <div data-platform-fields="{{ $def->name }}">
        @if($def->oauthSupported)
          <div class="field"><label>Connected account</label>
            @php($accs = \App\Models\PlatformAccount::where('platform', $def->name)->get())
            <select name="p[{{ $def->name }}][platform_account_id]"><option value="">— none (manual RTMP key) —</option>@foreach($accs as $a)<option value="{{ $a->id }}" {{ old('p.'.$def->name.'.platform_account_id', $destination->platform_account_id) === $a->id ? 'selected' : '' }}>{{ $a->name }}</option>@endforeach</select>
            <div class="help">No account? <a href="{{ route('admin.platforms.connect', $def->name) }}">Connect {{ $def->label }}</a> (official OAuth).</div></div>
        @endif
        @foreach($def->fields as $f)
          @php($isCore = in_array($f['name'], ['rtmp_url', 'stream_key'], true))
          @php($key = $isCore ? $f['name'] : 'opt_'.$f['name'])
          @php($name = 'p['.$def->name.']['.$key.']')
          @php($val = $isCore ? ($f['name'] === 'rtmp_url' ? old('p.'.$def->name.'.rtmp_url', $destination->rtmp_url ?: $def->defaultRtmpUrl) : '') : old('p.'.$def->name.'.'.$key, $destination->option($f['name'])))
          <div class="field"><label>{{ $f['label'] }}</label>
            @if($f['type'] === 'textarea')<textarea name="{{ $name }}">{{ $val }}</textarea>
            @elseif($f['type'] === 'select')<select name="{{ $name }}">@foreach($f['options'] as $k => $l)<option value="{{ $k }}" {{ (string) $val === (string) $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
            @elseif($f['type'] === 'page_select')
              @php($pages = collect(\App\Models\PlatformAccount::where('platform', 'facebook')->get())->flatMap(fn ($a) => $a->meta['pages'] ?? []))
              <select name="{{ $name }}"><option value="">— select page —</option>@foreach($pages as $pg)<option value="{{ $pg['id'] }}" {{ (string) $val === (string) $pg['id'] ? 'selected' : '' }}>{{ $pg['name'] }}</option>@endforeach</select><div class="help">Pages appear after connecting a Facebook account.</div>
            @elseif($f['type'] === 'password')<input type="password" name="{{ $name }}" value="" autocomplete="new-password" placeholder="{{ $destination->exists && $destination->stream_key_hint ? 'unchanged ('.$destination->maskedKey().')' : '' }}">
            @else<input type="text" name="{{ $name }}" value="{{ $val }}">@endif
            @if(! empty($f['help']))<div class="help">{{ $f['help'] }}</div>@endif
          </div>
        @endforeach
      </div>
    @endforeach
    <div class="form-row"><div class="field"><label class="check"><input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', $destination->is_enabled ?? true) ? 'checked' : '' }}> Enabled</label></div><div class="field"><label>Sort order</label><input type="number" name="sort_order" value="{{ old('sort_order', $destination->sort_order ?? 0) }}" min="0" max="999"></div></div>
    <button class="btn btn-primary">{{ $destination->exists ? 'Save' : 'Add destination' }}</button> <a class="btn btn-outline" href="{{ route('admin.destinations.index') }}">Cancel</a>
  </form>
</div>
@endsection
