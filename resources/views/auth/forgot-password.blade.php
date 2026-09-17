@extends('layouts.auth')
@section('title', 'Forgot password')
@section('content')
<h2>Reset your password</h2><p class="muted">We will e-mail you a secure reset link.</p>
<form method="post" action="{{ route('password.email') }}">@csrf
  <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus></div>
  <button class="btn btn-primary btn-block">Send reset link</button>
</form>
<p class="small" style="margin-top:14px;text-align:center"><a href="{{ route('login') }}">Back to login</a></p>
@endsection
