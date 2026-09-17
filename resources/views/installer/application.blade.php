@extends('layouts.installer')
@section('content')
<h2>Application configuration</h2>
<form method="post" action="{{ route('install.application.save') }}">@csrf
  <div class="field"><label>Application name</label><input type="text" name="name" value="{{ old('name', $app['name']) }}" required></div>
  <div class="field"><label>Application URL</label><input type="url" name="url" value="{{ old('url', $app['url']) }}" required><div class="help">Use https:// in production.</div></div>
  <div class="form-row"><div class="field"><label>Timezone</label><select name="timezone">@foreach($timezones as $tz)<option value="{{ $tz }}" {{ old('timezone', $app['timezone']) === $tz ? 'selected' : '' }}>{{ $tz }}</option>@endforeach</select></div><div class="field"><label>Admin e-mail</label><input type="email" name="admin_email" value="{{ old('admin_email', $app['admin_email']) }}" required></div></div>
  <div class="field"><label>Public RTMP server URL (shown in OBS)</label><input type="text" name="rtmp_url" value="{{ old('rtmp_url', $app['rtmp_url']) }}" required><div class="help">e.g. rtmp://stream.yourdomain.com/live — can be changed later in Settings.</div></div>
  <p class="small muted">APP_KEY and encryption secrets are generated automatically.</p>
  <button class="btn btn-primary">Continue →</button>
</form>
@endsection
