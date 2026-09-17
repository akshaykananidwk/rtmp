@extends('layouts.auth')
@section('title', 'Choose a new password')
@section('content')
<h2>New password</h2>
<form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}">
  <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email', $email) }}" required></div>
  <div class="field"><label for="password">New password</label><input id="password" type="password" name="password" required autocomplete="new-password"><div class="help">Min 10 chars with upper/lower case, number and symbol.</div></div>
  <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" required></div>
  <button class="btn btn-primary btn-block">Reset password</button>
</form>
@endsection
