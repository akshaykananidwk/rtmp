@extends('layouts.admin')
@section('title', 'Error Log')
@section('content')
<div class="tabs"><a href="{{ route('admin.logs') }}">Streaming</a><a href="{{ route('admin.logs.audit') }}">Audit</a><a class="active" href="{{ route('admin.logs.errors') }}">Errors</a></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Time</th><th>Module</th><th>Exception</th><th>Message</th><th>User</th></tr></thead><tbody>@forelse($errorLogs as $e)<tr><td><a class="mono" href="{{ route('admin.logs.error', $e) }}">{{ $e->reference }}</a></td><td class="small">{{ $e->created_at->format('d M H:i:s') }}</td><td class="small">{{ $e->module }}</td><td class="small mono">{{ class_basename((string) $e->exception_class) }}</td><td class="small">{{ \Illuminate\Support\Str::limit($e->message, 100) }}</td><td class="small">{{ $e->user_id ? substr($e->user_id, -6) : '—' }}</td></tr>@empty<tr><td colspan="6"><div class="empty">No errors recorded. 🎉</div></td></tr>@endforelse</tbody></table></div>{{ $errorLogs->links('components.pagination') }}</div>
@endsection
