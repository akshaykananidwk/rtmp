@extends('layouts.auth')
@section('title', 'Create your account')
@section('content')
<h2>Create your account</h2>
<p class="small muted" style="margin-top:-6px;margin-bottom:16px">Stream once — go live everywhere. Free to start, no card needed.</p>
<form method="post" action="{{ route('register') }}">@csrf
  <div class="field"><label for="name">Your name</label><input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name" maxlength="100"></div>
  <div class="field"><label for="business_name">Channel / business name <span class="muted">(optional)</span></label><input id="business_name" type="text" name="business_name" value="{{ old('business_name') }}" autocomplete="organization" maxlength="100" placeholder="Leave empty to use your name"></div>
  <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" maxlength="190"></div>
  <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="new-password"><div class="help">At least {{ config('akstream.security.password_min_length', 10) }} characters, with upper and lower case letters, a number and a symbol. Passwords found in known breaches are refused.</div></div>
  <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
  <div class="field"><label class="check"><input type="checkbox" name="terms" value="1" {{ old('terms') ? 'checked' : '' }}> I agree to the terms of service</label></div>
  <button class="btn btn-primary btn-block">Create account</button>
</form>
<p class="small" style="margin-top:14px;text-align:center">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
