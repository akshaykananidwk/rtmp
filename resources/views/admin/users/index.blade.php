@extends('layouts.admin')
@section('title', 'Users')
@section('content')
<div class="card"><div class="card-header"><h3>Users</h3>@can('create', \App\Models\User::class)<a class="btn btn-primary" href="{{ route('admin.users.create') }}">+ Add user</a>@endcan</div>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>2FA</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
@foreach($users as $u)<tr><td><strong>{{ $u->name }}</strong></td><td>{{ $u->email }}</td><td>{{ $u->roles->pluck('label')->implode(', ') }}</td><td>{{ $u->hasTwoFactorEnabled() ? '✅' : '—' }}</td><td>@include('components.badge', ['status' => $u->is_active ? 'live' : 'disabled'])</td><td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}<br>{{ $u->last_login_ip }}</td><td style="white-space:nowrap">@can('update', $u)<a class="btn btn-sm btn-outline" href="{{ route('admin.users.edit', $u) }}">Edit</a>@endcan @can('delete', $u)<form method="post" action="{{ route('admin.users.destroy', $u) }}" style="display:inline" data-confirm="Delete {{ $u->email }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">🗑</button></form>@endcan</td></tr>@endforeach
</tbody></table></div>{{ $users->links('components.pagination') }}</div>

@can('create', \App\Models\User::class)
<div class="card" style="margin-top:18px">
  <div class="card-header"><h3>Invite a colleague</h3></div>
  @if(session('invitation_link'))
    <div class="alert alert-success small">
      <strong>Send them this link</strong> — it is shown once and works for {{ \App\Domain\Accounts\InvitationService::LIFETIME_DAYS }} days:<br>
      <span class="mono" style="word-break:break-all">{{ session('invitation_link') }}</span>
    </div>
  @endif
  <form method="post" action="{{ route('admin.invitations.store') }}" class="form-row" style="align-items:flex-end">@csrf
    <div class="field" style="flex:2"><label>E-mail</label><input type="email" name="email" value="{{ old('email') }}" required maxlength="190" placeholder="colleague@example.com"></div>
    <div class="field"><label>Role</label><select name="role">@foreach($invitableRoles as $role)<option value="{{ $role->name }}" {{ old('role') === $role->name ? 'selected' : '' }}>{{ $role->label }}</option>@endforeach</select></div>
    <div class="field" style="flex:0 0 auto"><button class="btn btn-primary">Create invitation</button></div>
  </form>
  @if($invitations->isNotEmpty())
    <div class="table-wrap" style="margin-top:12px"><table><thead><tr><th>E-mail</th><th>Role</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody>
      @foreach($invitations as $i)
        <tr>
          <td>{{ $i->email }}</td>
          <td>{{ $i->role }}</td>
          <td>@include('components.badge', ['status' => $i->status() === 'pending' ? 'connecting' : ($i->status() === 'accepted' ? 'live' : 'disabled')]) {{ $i->status() }}</td>
          <td class="small muted">{{ $i->expires_at->diffForHumans() }}</td>
          <td>@if($i->isPending())<form method="post" action="{{ route('admin.invitations.destroy', $i) }}" data-confirm="Revoke the invitation for {{ $i->email }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline">Revoke</button></form>@endif</td>
        </tr>
      @endforeach
    </tbody></table></div>
  @endif
</div>
@endcan
@endsection
