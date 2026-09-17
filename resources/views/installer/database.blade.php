@extends('layouts.installer')
@section('content')
<h2>Database configuration</h2>
<form method="post" action="{{ route('install.database.save') }}">@csrf
  <div class="field"><label>Driver</label><select name="driver">@foreach(['mysql' => 'MySQL 8', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite (testing only)'] as $k => $l)<option value="{{ $k }}" {{ old('driver', $db['driver']) === $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select></div>
  <div class="form-row"><div class="field"><label>Database host</label><input type="text" name="host" value="{{ old('host', $db['host']) }}"></div><div class="field"><label>Database port</label><input type="number" name="port" value="{{ old('port', $db['port']) }}"></div></div>
  <div class="field"><label>Database name</label><input type="text" name="database" value="{{ old('database', $db['database']) }}" required></div>
  <div class="form-row"><div class="field"><label>Database username</label><input type="text" name="username" value="{{ old('username', $db['username']) }}"></div><div class="field"><label>Database password</label><input type="password" name="password" autocomplete="new-password"></div></div>
  <div id="db-test-result" class="alert alert-info" style="display:block">Press "Test Database Connection".</div>
  <button type="button" class="btn btn-outline" id="db-test" data-url="{{ route('install.database.test') }}">Test Database Connection</button>
  <button class="btn btn-primary" id="db-continue">Continue →</button>
</form>
@endsection
