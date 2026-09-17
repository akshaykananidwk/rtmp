@extends('layouts.installer')
@section('content')
<h2>Admin account</h2>
<form method="post" action="{{ route('install.admin.save') }}">@csrf
  <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', 'Akshay Kanani') }}" required></div>
  <div class="field"><label>E-mail</label><input type="email" name="email" value="{{ old('email', $email) }}" required></div>
  <div class="form-row"><div class="field"><label>Password</label><input type="password" name="password" required autocomplete="new-password"><div class="help">Min 10 chars with upper/lower case, number and symbol.</div></div><div class="field"><label>Confirm password</label><input type="password" name="password_confirmation" required></div></div>
  <button class="btn btn-primary">Continue →</button>
</form>
@endsection
