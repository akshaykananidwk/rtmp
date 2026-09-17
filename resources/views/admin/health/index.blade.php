@extends('layouts.admin')
@section('title', 'System Health')
@section('content')
<div class="card"><div class="card-header"><h3>Health dashboard</h3><form method="post" action="{{ route('admin.health.run') }}">@csrf<button class="btn btn-primary">▶ Run health check</button></form></div>
<div class="health-grid">@foreach($checks as $c)@php($row = $latest[$c->name()] ?? null)<a class="health-item" href="{{ route('admin.health.show', $c->name()) }}"><div><strong>{{ $c->label() }}</strong><div class="small muted">{{ $row ? \Illuminate\Support\Str::limit($row->message, 40) : 'not checked' }}</div></div><span style="font-size:1.4rem">{{ $row ? ($row->status === 'pass' ? '🟢' : ($row->status === 'warn' ? '🟡' : '🔴')) : '⚪' }}</span></a>@endforeach</div>
<p class="small muted" style="margin-top:14px">Checks run automatically every 10 minutes via the scheduler and after every update. Critical: Application, Database, Cache, Storage, PHP. Click a service for diagnostics.</p></div>
@endsection
