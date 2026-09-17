@extends('layouts.admin')
@section('title', 'Audit Log')
@section('content')
<div class="tabs"><a href="{{ route('admin.logs') }}">Streaming</a><a class="active" href="{{ route('admin.logs.audit') }}">Audit</a><a href="{{ route('admin.logs.errors') }}">Errors</a></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>IP</th><th>Action</th><th>Subject</th><th>Result</th><th>Details</th></tr></thead><tbody>@forelse($logs as $l)<tr><td class="small">{{ $l->created_at->format('d M Y H:i:s') }}</td><td>{{ $l->user?->name ?? 'system' }}</td><td class="small mono">{{ $l->ip_address }}</td><td class="mono small">{{ $l->action }}</td><td class="small">{{ $l->subject_type ? class_basename($l->subject_type).' '.substr((string) $l->subject_id, -6) : '—' }}</td><td>@include('components.badge', ['status' => $l->result])</td><td class="small muted">{{ $l->properties ? \Illuminate\Support\Str::limit(json_encode($l->properties, JSON_UNESCAPED_SLASHES), 120) : '' }}</td></tr>@empty<tr><td colspan="7"><div class="empty">No audit entries.</div></td></tr>@endforelse</tbody></table></div>{{ $logs->links('components.pagination') }}</div>
@endsection
