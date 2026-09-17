@extends('layouts.admin')
@section('title', $endpoint->exists ? 'Edit Stream Key' : 'Generate Stream Key')
@section('content')
<div class="card" style="max-width:640px">
  <form method="post" action="{{ $endpoint->exists ? route('admin.stream-keys.update', $endpoint) : route('admin.stream-keys.store') }}">@csrf @if($endpoint->exists)@method('PUT')@endif
    <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $endpoint->name) }}" required maxlength="100" placeholder="Dwarka Event"></div>
    @unless($endpoint->exists)<div class="field"><label>Path ID (optional)</label><input type="text" name="slug" value="{{ old('slug') }}" placeholder="AKDWK-EVENT-001" maxlength="40"><div class="help">Human identifier, e.g. AKDWK-HOTEL-001. Letters, numbers and dashes. The secret key is generated separately.</div></div>@endunless
    <div class="field"><label>Assign to user</label><select name="user_id"><option value="">— me —</option>@foreach($users as $u)<option value="{{ $u->id }}" {{ old('user_id', $endpoint->user_id) === $u->id ? 'selected' : '' }}>{{ $u->name }} ({{ $u->email }})</option>@endforeach</select></div>
    <div class="field"><label class="check"><input type="checkbox" name="auto_distribute" value="1" {{ old('auto_distribute', $endpoint->auto_distribute) ? 'checked' : '' }}> Automatic mode: start distribution as soon as OBS connects</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="record_enabled" value="1" {{ old('record_enabled', $endpoint->record_enabled) ? 'checked' : '' }}> Record sessions from this key</label></div>
    <button class="btn btn-primary">{{ $endpoint->exists ? 'Save' : 'Generate key' }}</button> <a class="btn btn-outline" href="{{ route('admin.stream-keys.index') }}">Cancel</a>
  </form>
</div>
@endsection
