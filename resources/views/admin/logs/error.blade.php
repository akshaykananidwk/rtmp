@extends('layouts.admin')
@section('title', 'Error '.$error->reference)
@section('content')
<div class="card"><table><tr><th>Reference</th><td class="mono">{{ $error->reference }}</td></tr><tr><th>Time</th><td>{{ $error->created_at }}</td></tr><tr><th>Request ID</th><td class="mono small">{{ $error->request_id }}</td></tr><tr><th>Module</th><td>{{ $error->module }}</td></tr><tr><th>Exception</th><td class="mono">{{ $error->exception_class }}</td></tr><tr><th>Message</th><td>{{ $error->message }}</td></tr><tr><th>Location</th><td class="mono small">{{ $error->file }}:{{ $error->line }}</td></tr><tr><th>Request</th><td class="small">{{ $error->method }} {{ $error->url }} from {{ $error->ip_address }}</td></tr><tr><th>User</th><td class="small">{{ $error->user_id ?? '—' }}</td></tr></table>
<details style="margin-top:12px"><summary>Sanitized stack trace</summary><pre class="code" style="max-height:500px;overflow:auto">{{ $error->trace }}</pre></details></div>
@endsection
