@extends('layouts.admin')
@section('title', 'Settings')
@section('content')
@php($tabs = ['general' => 'General', 'streaming' => 'Streaming', 'registration' => 'Sign-ups', 'recording' => 'Recording', 'storage' => 'Storage', 'mail' => 'E-mail (SMTP)', 'security' => 'Security', 'platforms' => 'Platforms (API)', 'notifications' => 'Notifications', 'backups' => 'Backups'])
<div class="tabs">@foreach($tabs as $k => $l)<a href="{{ route('admin.settings', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach</div>
@php($v = $values[$tab] ?? [])
@php($dis = $canManage ? '' : 'disabled')
<div class="card" style="max-width:820px">
<form method="post" action="{{ route('admin.settings.update', $tab) }}" enctype="multipart/form-data">@csrf
@switch($tab)
@case('general')
  <div class="form-row"><div class="field"><label>Application name</label><input type="text" name="app_name" value="{{ $v['app_name'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Timezone</label><select name="timezone" {{ $dis }}>@foreach($timezones as $tz)<option value="{{ $tz }}" {{ ($v['timezone'] ?? '') === $tz ? 'selected' : '' }}>{{ $tz }}</option>@endforeach</select></div></div>
  <div class="form-row"><div class="field"><label>Contact e-mail</label><input type="email" name="contact_email" value="{{ $v['contact_email'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Support number</label><input type="text" name="support_phone" value="{{ $v['support_phone'] ?? '' }}" {{ $dis }}></div></div>
  <div class="field"><label>Address</label><textarea name="address" {{ $dis }}>{{ $v['address'] ?? '' }}</textarea></div>
  <div class="form-row"><div class="field"><label>Logo (png/jpg/webp/svg)</label><input type="file" name="logo" {{ $dis }}>@if(! empty($v['logo']))<div class="help">Current: {{ $v['logo'] }}</div>@endif</div><div class="field"><label>Favicon</label><input type="file" name="favicon" {{ $dis }}></div></div>
@break
@case('streaming')
  <div class="field"><label>RTMP host (shown to users as OBS Server)</label><input type="text" name="rtmp_host" value="{{ $v['rtmp_host'] ?? '' }}" placeholder="rtmp://stream.example.com/live" {{ $dis }}></div>
  <div class="form-row"><div class="field"><label>Default bitrate (kbps)</label><input type="number" name="default_bitrate" value="{{ $v['default_bitrate'] ?? 4500 }}" {{ $dis }}></div><div class="field"><label>Default resolution</label><input type="text" name="default_resolution" value="{{ $v['default_resolution'] ?? '1920x1080' }}" {{ $dis }}></div><div class="field"><label>Max retries per destination</label><input type="number" name="retry_count" value="{{ $v['retry_count'] ?? 5 }}" {{ $dis }}><div class="help">Backoff: {{ implode('s → ', config('akstream.streaming.backoff')) }}s</div></div></div>
  <div class="field"><label class="check"><input type="checkbox" name="auto_distribution" value="1" {{ ($v['auto_distribution'] ?? '0') === '1' ? 'checked' : '' }} {{ $dis }}> Auto Distribution: start all enabled destinations automatically when OBS connects</label></div>
  <div class="field"><label class="check"><input type="checkbox" name="recording_enabled" value="1" {{ ($v['recording_enabled'] ?? '0') === '1' ? 'checked' : '' }} {{ $dis }}> Record every stream by default</label></div>
  <div class="alert alert-info small">Engine API: <span class="mono">{{ config('akstream.streaming.engine_api_url') }}</span> · internal RTMP: <span class="mono">{{ config('akstream.streaming.internal_rtmp_url') }}</span> · node <span class="mono">{{ config('akstream.streaming.node_id') }}</span> (from .env)</div>
@break
@case('registration')
  <div class="field"><label class="check"><input type="checkbox" name="open" value="1" {{ ($v['open'] ?? '1') === '1' ? 'checked' : '' }} {{ $dis }}> Allow anyone to create an account at <span class="mono">{{ route('register') }}</span></label><div class="help">Turn this off to run a private install: existing users keep working, and the sign-up links disappear.</div></div>
  <div class="form-row"><div class="field"><label>Plan given to new accounts</label><input type="text" name="default_plan" value="{{ $v['default_plan'] ?? 'free' }}" {{ $dis }}></div></div>
  <div class="form-row"><div class="field"><label>Stream keys per account</label><input type="number" name="max_stream_keys" value="{{ $v['max_stream_keys'] ?? 3 }}" min="1" max="100" {{ $dis }}></div><div class="field"><label>Destinations per account</label><input type="number" name="max_destinations" value="{{ $v['max_destinations'] ?? 10 }}" min="1" max="100" {{ $dis }}><div class="help">Applies to accounts that signed up themselves; super admins are never limited.</div></div></div>
  <div class="form-row"><div class="field"><label>Streaming minutes per month</label><input type="number" name="max_monthly_minutes" value="{{ $v['max_monthly_minutes'] ?? 0 }}" min="0" max="1000000" {{ $dis }}><div class="help"><strong>0 = unlimited.</strong> Counted per calendar month and enforced when a stream is started, from the panel and the API alike. A stream already running is never cut off mid-broadcast.</div></div></div>
  @break

@case('recording')
  <div class="form-row"><div class="field"><label>Format</label><select name="format" {{ $dis }}>@foreach(['mp4','mkv','flv'] as $f)<option value="{{ $f }}" {{ ($v['format'] ?? 'mp4') === $f ? 'selected' : '' }}>{{ $f }}</option>@endforeach</select></div><div class="field"><label>Resolution</label><input type="text" name="resolution" value="{{ $v['resolution'] ?? 'source' }}" {{ $dis }}><div class="help">"source" = copy without re-encoding (recommended)</div></div></div>
  <div class="form-row"><div class="field"><label>Retention (days)</label><input type="number" name="retention_days" value="{{ $v['retention_days'] ?? 30 }}" {{ $dis }}></div><div class="field"><label>Max recording size (MB)</label><input type="number" name="max_size_mb" value="{{ $v['max_size_mb'] ?? 4096 }}" {{ $dis }}></div></div>
@break
@case('storage')
  <div class="form-row"><div class="field"><label>Driver</label><select name="driver" {{ $dis }}><option value="local" {{ ($v['driver'] ?? 'local') === 'local' ? 'selected' : '' }}>Local disk</option><option value="s3" {{ ($v['driver'] ?? '') === 's3' ? 'selected' : '' }}>S3-compatible</option></select></div><div class="field"><label>Retention (days)</label><input type="number" name="retention_days" value="{{ $v['retention_days'] ?? 30 }}" {{ $dis }}></div></div>
  <div class="form-row"><div class="field"><label>S3 key</label><input type="text" name="s3_key" value="{{ $v['s3_key'] ?? '' }}" {{ $dis }}></div><div class="field"><label>S3 secret {{ ! empty($v['s3_secret']) ? '(saved)' : '' }}</label><input type="password" name="s3_secret" value="" autocomplete="off" {{ $dis }}></div></div>
  <div class="form-row"><div class="field"><label>Region</label><input type="text" name="s3_region" value="{{ $v['s3_region'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Bucket</label><input type="text" name="s3_bucket" value="{{ $v['s3_bucket'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Endpoint (optional)</label><input type="text" name="s3_endpoint" value="{{ $v['s3_endpoint'] ?? '' }}" {{ $dis }}></div></div>
@break
@case('mail')
  <div class="form-row"><div class="field"><label>SMTP host</label><input type="text" name="host" value="{{ $v['host'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Port</label><input type="number" name="port" value="{{ $v['port'] ?? 587 }}" {{ $dis }}></div><div class="field"><label>Encryption</label><select name="encryption" {{ $dis }}>@foreach(['tls','ssl','none'] as $e)<option value="{{ $e }}" {{ ($v['encryption'] ?? 'tls') === $e ? 'selected' : '' }}>{{ $e }}</option>@endforeach</select></div></div>
  <div class="form-row"><div class="field"><label>Username</label><input type="text" name="username" value="{{ $v['username'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Password {{ ! empty($v['password']) ? '(saved)' : '' }}</label><input type="password" name="password" value="" autocomplete="off" {{ $dis }}></div></div>
  <div class="form-row"><div class="field"><label>From address</label><input type="email" name="from_address" value="{{ $v['from_address'] ?? '' }}" {{ $dis }}></div><div class="field"><label>From name</label><input type="text" name="from_name" value="{{ $v['from_name'] ?? '' }}" {{ $dis }}></div></div>
@break
@case('security')
  <div class="form-row"><div class="field"><label>Session timeout (minutes)</label><input type="number" name="session_timeout" value="{{ $v['session_timeout'] ?? 120 }}" {{ $dis }}></div><div class="field"><label>Max login attempts (per 15 min)</label><input type="number" name="login_max_attempts" value="{{ $v['login_max_attempts'] ?? 5 }}" {{ $dis }}></div></div>
  <div class="field"><label class="check"><input type="checkbox" name="two_factor_required" value="1" {{ ($v['two_factor_required'] ?? '0') === '1' ? 'checked' : '' }} {{ $dis }}> Require 2FA for admins (enforced at next login)</label></div>
  <div class="field"><label>Admin IP allowlist (comma separated IP or CIDR; empty = allow all)</label><textarea name="ip_allowlist" {{ $dis }}>{{ $v['ip_allowlist'] ?? '' }}</textarea><div class="help">⚠ Make sure your own IP is included before saving.</div></div>
  <div class="field"><label class="check"><input type="checkbox" name="maintenance_mode" value="1" {{ ($v['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' }} {{ $dis }}> Maintenance mode (visitors see the maintenance page; your session keeps access)</label></div>
@break
@case('platforms')
  <h3>YouTube (Google Cloud OAuth client)</h3><div class="form-row"><div class="field"><label>Client ID</label><input type="text" name="youtube_client_id" value="{{ $v['youtube_client_id'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Client secret {{ ! empty($v['youtube_client_secret']) ? '(saved)' : '' }}</label><input type="password" name="youtube_client_secret" value="" autocomplete="off" {{ $dis }}></div></div><div class="help" style="margin-bottom:16px">Redirect URI: <span class="mono">{{ route('admin.platforms.callback', 'youtube') }}</span> · enable "YouTube Data API v3".</div>
  <h3>Meta / Facebook (App)</h3><div class="form-row"><div class="field"><label>App ID</label><input type="text" name="meta_app_id" value="{{ $v['meta_app_id'] ?? '' }}" {{ $dis }}></div><div class="field"><label>App secret {{ ! empty($v['meta_app_secret']) ? '(saved)' : '' }}</label><input type="password" name="meta_app_secret" value="" autocomplete="off" {{ $dis }}></div><div class="field"><label>Webhook verify token</label><input type="text" name="meta_webhook_verify_token" value="{{ $v['meta_webhook_verify_token'] ?? '' }}" {{ $dis }}></div></div><div class="help" style="margin-bottom:16px">Redirect URI: <span class="mono">{{ route('admin.platforms.callback', 'facebook') }}</span> · Webhook: <span class="mono">{{ route('webhooks.meta') }}</span> · Permissions: pages_show_list, pages_manage_posts, publish_video (App Review).</div>
  <h3>Twitch</h3><div class="form-row"><div class="field"><label>Client ID</label><input type="text" name="twitch_client_id" value="{{ $v['twitch_client_id'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Client secret {{ ! empty($v['twitch_client_secret']) ? '(saved)' : '' }}</label><input type="password" name="twitch_client_secret" value="" autocomplete="off" {{ $dis }}></div></div><div class="help">Redirect URI: <span class="mono">{{ route('admin.platforms.callback', 'twitch') }}</span></div>
@break
@case('notifications')
  <div class="field"><label class="check"><input type="checkbox" name="email_enabled" value="1" {{ ($v['email_enabled'] ?? '1') === '1' ? 'checked' : '' }} {{ $dis }}> E-mail notifications to admins</label></div>
  <div class="field"><label class="check"><input type="checkbox" name="whatsapp_enabled" value="1" {{ ($v['whatsapp_enabled'] ?? '0') === '1' ? 'checked' : '' }} {{ $dis }}> WhatsApp notifications (Meta WhatsApp Cloud API) for warnings/critical alerts</label></div>
  <div class="form-row"><div class="field"><label>WhatsApp phone number ID</label><input type="text" name="phone_number_id" value="{{ $v['phone_number_id'] ?? '' }}" {{ $dis }}></div><div class="field"><label>Access token {{ ! empty($v['access_token']) ? '(saved)' : '' }}</label><input type="password" name="access_token" value="" autocomplete="off" {{ $dis }}></div><div class="field"><label>Admin number (E.164)</label><input type="text" name="admin_number" value="{{ $v['admin_number'] ?? '' }}" placeholder="919978123146" {{ $dis }}></div></div>
@break
@case('backups')
  <div class="field"><label class="check"><input type="checkbox" name="auto_enabled" value="1" {{ ($v['auto_enabled'] ?? '1') === '1' ? 'checked' : '' }} {{ $dis }}> Automatic backups</label></div>
  <div class="form-row"><div class="field"><label>Frequency</label><select name="frequency" {{ $dis }}><option value="daily" {{ ($v['frequency'] ?? 'daily') === 'daily' ? 'selected' : '' }}>Daily 02:30</option><option value="weekly" {{ ($v['frequency'] ?? '') === 'weekly' ? 'selected' : '' }}>Weekly</option></select></div><div class="field"><label>Retention (days)</label><input type="number" name="retention_days" value="{{ $v['retention_days'] ?? 14 }}" {{ $dis }}></div></div>
  <div class="field"><label class="check"><input type="checkbox" name="encrypt" value="1" {{ ($v['encrypt'] ?? '0') === '1' ? 'checked' : '' }} {{ $dis }}> Encrypt backups (XChaCha20-Poly1305, key derived from APP_KEY)</label></div>
@break
@endswitch
@if($canManage)<button class="btn btn-primary">Save {{ $tabs[$tab] }}</button>@else<div class="alert alert-info small">Read-only: you need the settings.manage permission to change settings.</div>@endif
</form></div>
@endsection
