@extends('layouts.admin')
@section('title', 'Webhooks')
@section('content')

@if(session('webhook_secret'))
  <div class="alert alert-success">
    <strong>Signing secret — copy it now, it is never shown again:</strong><br>
    <span class="mono" style="word-break:break-all">{{ session('webhook_secret') }}</span>
  </div>
@endif

<div class="card">
  <div class="card-header"><h3>Your endpoints</h3></div>
  @if($webhooks->isEmpty())
    <div class="empty">No webhooks yet. Add one below to have this panel call your own system when something happens.</div>
  @else
    <div class="table-wrap"><table><thead><tr><th>Name</th><th>URL</th><th>Events</th><th>Status</th><th>Last delivery</th><th></th></tr></thead><tbody>
      @foreach($webhooks as $w)
        <tr>
          <td><strong>{{ $w->name }}</strong><div class="small muted">secret {{ $w->secret_hint }}</div></td>
          <td class="small mono" style="word-break:break-all">{{ $w->url }}</td>
          <td class="small">{{ implode(', ', $w->events ?? []) }}</td>
          <td>
            @include('components.badge', ['status' => $w->is_enabled ? 'live' : 'disabled'])
            @if($w->consecutive_failures > 0)<div class="small muted">{{ $w->consecutive_failures }} failed in a row</div>@endif
          </td>
          <td class="small muted">
            {{ $w->last_delivered_at?->diffForHumans() ?? 'never' }}
            @if($w->last_error)<br><span style="color:var(--danger,#e35)">{{ $w->last_error }}</span>@endif
          </td>
          <td style="white-space:nowrap">
            <form method="post" action="{{ route('admin.webhooks.test', $w) }}" style="display:inline">@csrf<button class="btn btn-sm btn-outline">Test</button></form>
            <form method="post" action="{{ route('admin.webhooks.toggle', $w) }}" style="display:inline">@csrf<button class="btn btn-sm btn-outline">{{ $w->is_enabled ? 'Disable' : 'Enable' }}</button></form>
            <form method="post" action="{{ route('admin.webhooks.destroy', $w) }}" style="display:inline" data-confirm="Delete {{ $w->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">🗑</button></form>
          </td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>

@can('create', \App\Models\OutgoingWebhook::class)
<div class="card" style="margin-top:18px">
  <div class="card-header"><h3>Add an endpoint</h3></div>
  <form method="post" action="{{ route('admin.webhooks.store') }}">@csrf
    <div class="form-row">
      <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="My CRM"></div>
      <div class="field" style="flex:2"><label>URL (https only)</label><input type="url" name="url" value="{{ old('url') }}" required maxlength="2048" placeholder="https://example.com/hooks/ak"></div>
    </div>
    <div class="field"><label>Send these events</label>
      @foreach($events as $event => $description)
        <label class="check" style="margin-bottom:6px"><input type="checkbox" name="events[]" value="{{ $event }}" {{ in_array($event, old('events', ['stream.started', 'stream.stopped']), true) ? 'checked' : '' }}> <span class="mono">{{ $event }}</span> — {{ $description }}</label>
      @endforeach
    </div>
    <button class="btn btn-primary">Create webhook</button>
  </form>
  <div class="divider"></div>
  <div class="small muted">
    Each call is a <span class="mono">POST</span> of JSON with an <span class="mono">X-AK-Signature</span> header holding
    <span class="mono">sha256=HMAC_SHA256(raw body, your secret)</span>. Verify it over the raw body before trusting the call.
    A failing endpoint is retried, and switched off after {{ \App\Models\OutgoingWebhook::FAILURE_LIMIT }} failures in a row.
  </div>
</div>
@endcan

@if($recent->isNotEmpty())
<div class="card" style="margin-top:18px">
  <div class="card-header"><h3>Recent deliveries</h3></div>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>Endpoint</th><th>Event</th><th>Result</th><th>Took</th></tr></thead><tbody>
    @foreach($recent as $d)
      <tr>
        <td class="small muted">{{ $d->created_at->diffForHumans() }}</td>
        <td class="small">{{ $d->webhook?->name ?? '—' }}</td>
        <td class="small mono">{{ $d->event }}</td>
        <td class="small">{{ $d->succeeded ? '✅ '.$d->status_code : '❌ '.($d->error ?: $d->status_code) }}</td>
        <td class="small muted">{{ $d->duration_ms }} ms</td>
      </tr>
    @endforeach
  </tbody></table></div>
</div>
@endif

@endsection
