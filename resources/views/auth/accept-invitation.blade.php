@extends('layouts.auth')
@section('title', 'Join the team')
@section('content')
<h2>Join {{ $team ?: 'the team' }}</h2>
<p class="small muted" style="margin-top:-6px;margin-bottom:16px">You were invited as <strong>{{ $email }}</strong>. Choose a password to finish.</p>
<form method="post" action="{{ route('invitations.accept.store', $token) }}">@csrf
  <div class="field"><label for="name">Your name</label><input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" maxlength="100"></div>
  <div class="field"><label>E-mail</label><input type="email" value="{{ $email }}" disabled><div class="help">Fixed by the invitation — ask for a new invite to use a different address.</div></div>
  <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="new-password"><div class="help">At least {{ config('akstream.security.password_min_length', 10) }} characters, with upper and lower case letters, a number and a symbol.</div></div>
  <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
  <button class="btn btn-primary btn-block">Join the team</button>
</form>
@endsection
