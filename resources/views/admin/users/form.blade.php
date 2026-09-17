@extends('layouts.admin')
@section('title', $user->exists ? 'Edit User' : 'Add User')
@section('content')
<div class="card" style="max-width:640px"><form method="post" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">@csrf @if($user->exists)@method('PUT')@endif
  <div class="form-row"><div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $user->name) }}" required></div><div class="field"><label>E-mail</label><input type="email" name="email" value="{{ old('email', $user->email) }}" required></div></div>
  <div class="form-row"><div class="field"><label>{{ $user->exists ? 'New password (leave blank to keep)' : 'Password' }}</label><input type="password" name="password" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}><div class="help">Min 10 chars, upper/lower, number, symbol.</div></div><div class="field"><label>Confirm</label><input type="password" name="password_confirmation" autocomplete="new-password"></div></div>
  <div class="form-row"><div class="field"><label>Role</label><select name="role">@foreach($roles as $r)<option value="{{ $r->name }}" {{ old('role', $user->roleNames()->first()) === $r->name ? 'selected' : '' }}>{{ $r->label }} — {{ $r->description }}</option>@endforeach</select></div><div class="field"><label>Phone (WhatsApp)</label><input type="text" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="+91 99781 23146"></div></div>
  <div class="form-row"><div class="field"><label>Timezone</label><select name="timezone">@foreach(\DateTimeZone::listIdentifiers() as $tz)<option value="{{ $tz }}" {{ old('timezone', $user->timezone ?? 'Asia/Kolkata') === $tz ? 'selected' : '' }}>{{ $tz }}</option>@endforeach</select></div><div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $user->is_active ?? true) ? 'checked' : '' }}> Active</label></div></div>
  <button class="btn btn-primary">Save</button> <a class="btn btn-outline" href="{{ route('admin.users.index') }}">Cancel</a>
</form></div>
@endsection
