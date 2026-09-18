@extends('layouts.admin')
@section('title', 'System Updates')
@section('content')
@php($canManage = auth()->user()->can('manage', \App\Models\Update::class))
@if($inProgress)<div class="alert alert-warning">An update is in progress: <a href="{{ route('admin.updates.show', $inProgress) }}">{{ $inProgress->status }}</a></div>
@elseif($locked)<div class="alert alert-error">Update lock is present but no update is running — a previous update may have been interrupted. @if($canManage)<form method="post" action="{{ route('admin.updates.recover') }}" style="display:inline">@csrf<button class="btn btn-sm btn-warning">Run recovery</button></form>@endif</div>@endif
@if($missingEnv)<div class="alert alert-warning"><strong>New configuration keys detected</strong> (not in your .env; safe defaults in use): <span class="mono">{{ implode(', ', $missingEnv) }}</span>. Review Settings.</div>@endif
<div class="grid grid-2" style="margin-bottom:18px">
  <div class="card"><h3>GitHub repository</h3>
    @unless($repository)
      <div class="alert alert-warning small">In-panel updates are off until this is filled in — a fresh install ships with no repository and a branch of <span class="mono">main</span>. Enter the repository and the branch this copy was installed from, then Save.</div>
    @endunless
    <form method="post" action="{{ route('admin.updates.settings') }}">@csrf
      <div class="field"><label>Repository</label><input type="text" name="repository" value="{{ old('repository', $repository) }}" placeholder="username/project" {{ $canManage ? '' : 'disabled' }}></div>
      <div class="field"><label>Branch</label><input type="text" name="branch" value="{{ old('branch', $branch) }}" placeholder="main" {{ $canManage ? '' : 'disabled' }}></div>
      <div class="field"><label>Personal Access Token {{ $hasToken ? '(saved: '.$tokenHint.')' : '' }}</label><input type="password" name="token" value="" autocomplete="off" placeholder="{{ $hasToken ? 'leave blank to keep' : 'ghp_… (Contents: read-only)' }}" {{ $canManage ? '' : 'disabled' }}><div class="help">Stored encrypted. Never shown, logged or sent to the browser. Use a fine-grained token with <em>Contents: Read</em> only.</div></div>
      @if($canManage)<button class="btn btn-primary">Save & verify access</button>@endif
    </form>
    <div class="divider"></div>
    <div class="small muted">Current version <strong>{{ $currentVersion }}</strong> · commit <span class="mono">{{ substr((string) $currentCommit, 0, 7) ?: 'unknown' }}</span> · strategy <span class="pill">{{ $strategy }}</span></div>
  </div>
  <div class="card"><div class="card-header"><h3>Update status</h3>@if($canManage && $repository)<form method="post" action="{{ route('admin.updates.check') }}">@csrf<button class="btn btn-cyan">🔎 CHECK FOR UPDATE</button></form>@endif</div>
    @if($check)
      <table>
        <tr><th>Current Version</th><td>{{ $check['current_version'] }} <span class="muted mono small">{{ substr((string) $check['current_commit'], 0, 7) }}</span></td></tr>
        <tr><th>Latest Version</th><td><strong>{{ $check['latest_version'] }}</strong> <span class="muted mono small">{{ substr((string) $check['commit']['sha'], 0, 7) }}</span></td></tr>
        <tr><th>Commit</th><td>{{ \Illuminate\Support\Str::limit(strtok((string) $check['commit']['message'], "\n"), 120) }}</td></tr>
        <tr><th>Author</th><td>{{ $check['commit']['author'] }}</td></tr>
        <tr><th>Commit Date</th><td>{{ $check['commit']['date'] ? \Illuminate\Support\Carbon::parse($check['commit']['date'])->format('d M Y H:i') : '—' }}</td></tr>
        <tr><th>Changed Files</th><td>{{ $check['changed_files'] }} <span class="small muted">(+{{ $check['counts']['added'] }} added · {{ $check['counts']['modified'] }} modified · −{{ $check['counts']['deleted'] }} deleted · {{ $check['counts']['protected_skipped'] }} protected skipped)</span></td></tr>
        <tr><th>Update Size</th><td>≈ {{ \App\Domain\Analytics\AnalyticsService::humanBytes($check['estimated_size']) }} of changes (full archive downloaded)</td></tr>
        <tr><th>Checked</th><td class="small muted">{{ \Illuminate\Support\Carbon::parse($check['checked_at'])->diffForHumans() }}</td></tr>
      </table>
      @if($check['release_notes'])<details style="margin-top:10px"><summary>Release notes</summary><pre class="code">{{ $check['release_notes'] }}</pre></details>@endif
      @if($check['changes'])<details style="margin-top:10px"><summary>File changes</summary><div class="log-box" style="max-height:220px">@foreach($check['changes'] as $c)<div class="log-line {{ $c['protected'] ? 'log-warning' : 'log-info' }}"><span class="log-time">{{ str_pad($c['status'], 8) }}</span><span>{{ $c['file'] }}{{ $c['protected'] ? '  (protected – will be skipped)' : '' }}</span></div>@endforeach</div></details>@endif
      @if($check['update_available'] && $canManage && ! $inProgress)
        <form method="post" action="{{ route('admin.updates.install') }}" style="margin-top:16px" data-confirm-word="UPDATE" data-confirm="This will enable maintenance mode, back up files + database, install {{ $check['latest_version'] }} and run migrations. Type UPDATE to continue.">@csrf<button class="btn btn-success btn-lg">⬆ UPDATE NOW</button></form>
      @elseif(! $check['update_available'])<div class="alert alert-success" style="margin-top:12px">You are up to date.</div>@endif
    @else<div class="empty">{{ $repository ? 'Press Check for Update.' : 'Configure the repository first.' }}</div>@endif
  </div>
</div>
<div class="card" style="margin-bottom:18px"><h3>Update History</h3><div class="table-wrap"><table><thead><tr><th>Version</th><th>Previous</th><th>Git Commit</th><th>Date</th><th>Admin</th><th>Status</th><th>Backup</th><th>Migration</th><th>Health</th><th>Rollback</th><th>Duration</th></tr></thead><tbody>
@forelse($history as $u)<tr><td><a href="{{ route('admin.updates.show', $u) }}"><strong>{{ $u->version ?? '?' }}</strong></a></td><td>{{ $u->previous_version }}</td><td class="mono small">{{ substr((string) $u->commit_sha, 0, 7) }}</td><td class="small">{{ $u->started_at?->format('d M Y H:i') }}</td><td class="small">{{ $u->started_by ? (\App\Models\User::withoutGlobalScopes()->find($u->started_by)?->name ?? '—') : 'system' }}</td><td>@include('components.badge', ['status' => $u->state])</td><td>{{ $u->backup_ok ? '✅' : '—' }}</td><td>{{ $u->migration_ok === null ? '—' : ($u->migration_ok ? '✅' : '❌') }}</td><td>{{ $u->health_ok === null ? '—' : ($u->health_ok ? '✅' : '❌') }}</td><td>{{ $u->rolled_back ? '↩ yes' : '—' }}</td><td>{{ $u->duration_seconds }}s</td></tr>
@empty<tr><td colspan="11"><div class="empty">No updates yet.</div></td></tr>@endforelse</tbody></table></div>{{ $history->links('components.pagination') }}</div>
<div class="card"><h3>Protected paths</h3><p class="small muted">Never overwritten or deleted by updates. Defaults cannot be removed.</p>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">@foreach($protected as $p)<span class="pill">{{ $p->path }} @if(! $p->is_default && $canManage)<form method="post" action="{{ route('admin.updates.protected.destroy', $p) }}" style="display:inline">@csrf @method('DELETE')<button class="btn btn-sm" style="padding:0 4px">✕</button></form>@endif</span>@endforeach</div>
@if($canManage)<form method="post" action="{{ route('admin.updates.protected.store') }}" style="display:flex;gap:8px;max-width:480px">@csrf<input type="text" name="path" placeholder="public/custom-uploads/" required><button class="btn btn-outline">Add</button></form>@endif
</div>
@endsection
