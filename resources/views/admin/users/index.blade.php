@extends('layouts.admin')
@section('title', 'Users')
@section('content')
<div class="card"><div class="card-header"><h3>Users</h3>@can('create', \App\Models\User::class)<a class="btn btn-primary" href="{{ route('admin.users.create') }}">+ Add user</a>@endcan</div>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>2FA</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
@foreach($users as $u)<tr><td><strong>{{ $u->name }}</strong></td><td>{{ $u->email }}</td><td>{{ $u->roles->pluck('label')->implode(', ') }}</td><td>{{ $u->hasTwoFactorEnabled() ? '✅' : '—' }}</td><td>@include('components.badge', ['status' => $u->is_active ? 'live' : 'disabled'])</td><td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}<br>{{ $u->last_login_ip }}</td><td style="white-space:nowrap">@can('update', $u)<a class="btn btn-sm btn-outline" href="{{ route('admin.users.edit', $u) }}">Edit</a>@endcan @can('delete', $u)<form method="post" action="{{ route('admin.users.destroy', $u) }}" style="display:inline" data-confirm="Delete {{ $u->email }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">🗑</button></form>@endcan</td></tr>@endforeach
</tbody></table></div>{{ $users->links('components.pagination') }}</div>
@endsection
