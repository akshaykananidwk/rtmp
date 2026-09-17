@extends('layouts.admin')
@section('title', 'Stream History')
@section('content')
<div class="card"><div class="table-wrap"><table><thead><tr><th>Title</th><th>Key</th><th>Started</th><th>Ended</th><th>Duration</th><th>Bitrate</th><th>Res / FPS</th><th>Destinations</th><th>Status</th></tr></thead><tbody>
@forelse($sessions as $s)<tr><td><a href="{{ route('admin.history.show', $s) }}"><strong>{{ $s->title }}</strong></a></td><td>{{ $s->endpoint?->name }}</td><td>{{ $s->started_at?->format('d M Y H:i') }}</td><td>{{ $s->ended_at?->format('H:i') ?? '—' }}</td><td>{{ gmdate('H:i:s', $s->duration_seconds) }}</td><td>{{ $s->incoming_bitrate_kbps }} kbps</td><td>{{ $s->resolution ?? '—' }} / {{ $s->fps ?? '—' }}</td><td>{{ $s->destinations_count }}</td><td>@include('components.badge', ['status' => $s->status])</td></tr>
@empty<tr><td colspan="9"><div class="empty">No streams yet.</div></td></tr>@endforelse</tbody></table></div>{{ $sessions->links('components.pagination') }}</div>
@endsection
