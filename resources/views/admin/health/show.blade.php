@extends('layouts.admin')
@section('title', 'Health: '.$label)
@section('content')
<div class="card"><div class="card-header"><h3>{{ $label }} diagnostics</h3><a class="btn btn-sm btn-outline" href="{{ route('admin.health') }}">← All services</a></div>
<div class="table-wrap"><table><thead><tr><th>Time</th><th>Status</th><th>Message</th><th>Duration</th><th>Trigger</th><th>Details</th></tr></thead><tbody>@forelse($history as $h)<tr><td class="small">{{ $h->created_at->format('d M H:i:s') }}</td><td>@include('components.badge', ['status' => $h->status])</td><td>{{ $h->message }}</td><td class="small">{{ $h->duration_ms }} ms</td><td class="small">{{ $h->trigger }}</td><td>@if($h->details)<details><summary class="small">show</summary><pre class="code">{{ json_encode($h->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif</td></tr>@empty<tr><td colspan="6"><div class="empty">No results yet.</div></td></tr>@endforelse</tbody></table></div></div>
@endsection
