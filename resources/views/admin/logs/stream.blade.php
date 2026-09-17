@extends('layouts.admin')
@section('title', 'Logs')
@section('content')
<div class="tabs"><a class="active" href="{{ route('admin.logs') }}">Streaming</a><a href="{{ route('admin.logs.audit') }}">Audit</a><a href="{{ route('admin.logs.errors') }}">Errors</a></div>
<div class="card"><div class="card-header"><h3>Streaming logs</h3><div>@foreach([null => 'All', 'info' => 'Info', 'warning' => 'Warning', 'error' => 'Error'] as $k => $l)<a class="btn btn-sm {{ $level === $k ? 'btn-primary' : 'btn-outline' }}" href="{{ route('admin.logs', $k ? ['level' => $k] : []) }}">{{ $l }}</a> @endforeach</div></div>
<div class="table-wrap"><table><thead><tr><th>Time</th><th>Level</th><th>Event</th><th>Destination</th><th>Message</th></tr></thead><tbody>@forelse($logs as $l)<tr><td class="small">{{ $l->created_at->format('d M H:i:s') }}</td><td>@include('components.badge', ['status' => $l->level])</td><td class="mono small">{{ $l->event }}</td><td class="small">{{ $l->destination?->name ?? '—' }}</td><td>{{ $l->message }}</td></tr>@empty<tr><td colspan="5"><div class="empty">No logs.</div></td></tr>@endforelse</tbody></table></div>{{ $logs->links('components.pagination') }}</div>
@endsection
