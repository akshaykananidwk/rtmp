@extends('layouts.admin')
@section('title', 'Stream Keys')
@section('content')
<div class="card">
  <div class="card-header"><div><h3 style="margin:0">RTMP endpoints & stream keys</h3><div class="small muted">Server: <span class="mono">{{ $rtmpUrl }}</span> <button class="btn btn-sm btn-outline" data-copy-text="{{ $rtmpUrl }}">Copy</button></div></div>@can('create', \App\Models\StreamEndpoint::class)<a class="btn btn-primary" href="{{ route('admin.stream-keys.create') }}">+ Generate Stream Key</a>@endcan</div>
  <div class="table-wrap"><table><thead><tr><th>Name</th><th>Path ID</th><th>Key</th><th>Assigned to</th><th>Destinations</th><th>Status</th><th>Last seen</th><th></th></tr></thead><tbody>
  @forelse($endpoints as $e)
    <tr><td><strong>{{ $e->name }}</strong>@if($e->auto_distribute) <span class="pill">auto</span>@endif @if($e->record_enabled)<span class="pill">rec</span>@endif</td><td class="mono">{{ $e->slug }}</td><td class="mono">{{ $e->maskedKey() }}</td><td>{{ $e->user?->name ?? '—' }}</td><td>{{ $e->destinations_count }}</td>
    <td>@if($e->revoked_at)<span class="badge badge-failed">revoked</span>@elseif(! $e->is_enabled)<span class="badge badge-disabled">disabled</span>@else@include('components.badge', ['status' => $e->status])@endif</td>
    <td class="small muted">{{ $e->last_seen_at?->diffForHumans() ?? 'never' }}</td>
    <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="{{ route('admin.obs-setup', $e) }}">OBS</a>
      @can('update', $e)<a class="btn btn-sm btn-outline" href="{{ route('admin.stream-keys.edit', $e) }}">Edit</a>
      <form method="post" action="{{ route('admin.stream-keys.regenerate', $e) }}" style="display:inline" data-confirm="Regenerate key? OBS must be updated with the new key.">@csrf<button class="btn btn-sm btn-warning">Regenerate</button></form>
      <form method="post" action="{{ route('admin.stream-keys.toggle', $e) }}" style="display:inline">@csrf<button class="btn btn-sm btn-outline">{{ $e->is_enabled ? 'Disable' : 'Enable' }}</button></form>
      @if(! $e->revoked_at)<form method="post" action="{{ route('admin.stream-keys.revoke', $e) }}" style="display:inline" data-confirm="Revoke this key permanently?">@csrf<button class="btn btn-sm btn-danger">Revoke</button></form>@endif
      <form method="post" action="{{ route('admin.stream-keys.destroy', $e) }}" style="display:inline" data-confirm="Delete this endpoint and its history link?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline">🗑</button></form>@endcan
    </td></tr>
  @empty<tr><td colspan="8"><div class="empty">No stream keys yet.</div></td></tr>@endforelse
  </tbody></table></div>
  {{ $endpoints->links('components.pagination') }}
</div>
@endsection
