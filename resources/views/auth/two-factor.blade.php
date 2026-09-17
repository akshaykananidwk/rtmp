@extends('layouts.auth')
@section('title', 'Two-factor authentication')
@section('content')
<h2>Two-factor code</h2><p class="muted">Enter the 6-digit code from your authenticator app, or a recovery code.</p>
<form method="post" action="{{ route('two-factor.challenge') }}">@csrf
  <div class="field"><label for="code">Code</label><input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></div>
  <button class="btn btn-primary btn-block">Verify</button>
</form>
@endsection
