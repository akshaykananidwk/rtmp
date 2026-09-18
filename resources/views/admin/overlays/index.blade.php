@extends('layouts.admin')
@section('title', 'Overlays')
@section('content')
@if(! $font)<div class="alert alert-warning">No TrueType font found on this server, so text overlays cannot be drawn. Install one: <span class="mono">apt install fonts-dejavu-core</span> (or set <span class="mono">OVERLAY_FONT</span> in .env to a .ttf path).</div>@endif
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div><h3 style="margin:0">Overlays</h3><div class="small muted">News-channel style branding burned into the outgoing stream: headlines, scrolling ticker, clock and logo.</div></div>@can('create', \App\Models\Overlay::class)<a class="btn btn-primary" href="{{ route('admin.overlays.create') }}">+ New overlay</a>@endcan</div>
  <div class="table-wrap"><table><thead><tr><th>Name</th><th>Elements</th><th>Output</th><th>Default</th><th></th></tr></thead><tbody>
  @forelse($overlays as $o)
    <tr>
      <td><strong>{{ $o->name }}</strong></td>
      <td class="small">@foreach($o->elements() as $el)<span class="pill">{{ $el['type'] }}</span> @endforeach</td>
      <td class="small muted">{{ $o->resolution }} · {{ $o->bitrate_kbps }} kbps · {{ $o->fps }} fps</td>
      <td>{{ $o->is_default ? '⭐' : '' }}</td>
      <td style="white-space:nowrap">@can('update', $o)<a class="btn btn-sm btn-outline" href="{{ route('admin.overlays.edit', $o) }}">Edit</a>@endcan @can('delete', $o)<form method="post" action="{{ route('admin.overlays.destroy', $o) }}" style="display:inline" data-confirm="Delete overlay {{ $o->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">🗑</button></form>@endcan</td>
    </tr>
  @empty<tr><td colspan="5"><div class="empty">No overlays yet. Create one to add a headline, ticker, clock or logo to your stream.</div></td></tr>@endforelse
  </tbody></table></div>
</div>

<div class="card">
  <h3>Which stream key uses which overlay</h3>
  <p class="small muted">The overlay is rendered once and every destination receives the branded picture. Leaving it empty streams the original OBS picture without re-encoding (lowest CPU).</p>
  @forelse($endpoints as $e)
    <form method="post" action="{{ route('admin.overlays.assign') }}" class="dest-row" style="align-items:flex-end">@csrf
      <input type="hidden" name="stream_endpoint_id" value="{{ $e->id }}">
      <div><div class="name">{{ $e->name }}</div><div class="meta">{{ $e->overlay ? 'Currently: '.$e->overlay->name : 'No overlay (direct copy)' }}</div></div>
      <div style="display:flex;gap:8px;align-items:center">
        <select name="overlay_id"><option value="">— none —</option>@foreach($overlays as $o)<option value="{{ $o->id }}" {{ $e->overlay_id === $o->id ? 'selected' : '' }}>{{ $o->name }}</option>@endforeach</select>
        <button class="btn btn-sm btn-primary">Apply</button>
      </div>
    </form>
  @empty<div class="empty">No stream keys yet.</div>@endforelse
</div>
@endsection
