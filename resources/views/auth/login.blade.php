@extends('layouts.auth')
@section('title', 'Login')
@section('content')
<h2>Sign in</h2>
<form method="post" action="{{ route('login') }}">@csrf
  <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
  <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
  <div class="field"><label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label></div>
  <button class="btn btn-primary btn-block">Login</button>
</form>
<p class="small" style="margin-top:14px;text-align:center"><a href="{{ route('password.request') }}">Forgot password?</a></p>
@if($registrationOpen ?? true)<p class="small" style="text-align:center">New here? <a href="{{ route('register') }}">Create a free account</a></p>@endif
@endsection
