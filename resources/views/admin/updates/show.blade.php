@extends('layouts.admin')
@section('title', 'Update '.($update->version ?? substr((string) $update->commit_sha, 0, 7)))
@section('content')
@php($running = in_array($update->state, \App\Domain\Updates\UpdateState::IN_PROGRESS, true))
<div class="card" style="margin-bottom:18px" {!! $running ? 'data-update-status-url="'.route('admin.updates.status', $update).'"' : '' !!}>
  <div class="card-header"><h3>{{ $update->previous_version }} → {{ $update->version ?? '?' }} <span class="muted mono small">{{ substr((string) $update->commit_sha, 0, 7) }}</span></h3><span data-update-state class="badge badge-{{ $update->state }}">{{ $update->status }}</span></div>
  @if($running)<div class="alert alert-warning">Update running… this page refreshes automatically. Do not stop the server.</div>@endif
  @if($update->error)<div class="alert alert-error"><strong>Error:</strong> {{ $update->error }}</div>@endif
  <div class="grid grid-4">
    <div class="stat"><span class="label">Backup</span><span class="value sm">{{ $update->backup_ok ? '✅ verified' : '—' }}</span>@if($update->backup_id)<a class="small" href="{{ route('admin.backups.index') }}">{{ $update->backup_id }}</a>@endif</div>
    <div class="stat"><span class="label">Migration</span><span class="value sm">{{ $update->migration_ok === null ? ($update->migration_ran ? '…' : '—') : ($update->migration_ok ? '✅ OK' : '❌ failed') }}</span></div>
    <div class="stat"><span class="label">Health check</span><span class="value sm">{{ $update->health_ok === null ? '—' : ($update->health_ok ? '✅ PASS' : '❌ FAIL') }}</span></div>
    <div class="stat"><span class="label">Rollback</span><span class="value sm">{{ $update->rolled_back ? '↩ performed' : '—' }}</span></div>
  </div>
  <div class="small muted" style="margin-top:12px">Repository {{ $update->repository }}@{{ $update->branch }} · {{ $update->changed_files }} files (+{{ $update->added_files }} / ~{{ $update->modified_files }} / −{{ $update->deleted_files }}) · {{ \App\Domain\Analytics\AnalyticsService::humanBytes($update->download_size) }} · started {{ $update->started_at?->format('d M Y H:i:s') }} · {{ $update->duration_seconds }}s</div>
  @if($update->state === 'completed' && auth()->user()->can('manage', \App\Models\Update::class))<form method="post" action="{{ route('admin.updates.rollback', $update) }}" style="margin-top:14px" data-confirm-word="ROLLBACK" data-confirm="Roll back to {{ $update->previous_version }}? Files are restored from the update backup; the database is restored if migrations ran. Type ROLLBACK to continue.">@csrf<button class="btn btn-warning">↩ Roll back this update</button></form>@endif
</div>
<div class="card"><h3>Detailed log</h3><div class="log-box" id="update-logs" style="max-height:600px">@foreach($update->logs as $l)<div class="log-line log-{{ $l->level }}"><span class="log-time">{{ $l->created_at->format('H:i:s') }}</span><span>[{{ $l->step }}] {{ $l->message }}</span></div>@endforeach</div></div>
@endsection
