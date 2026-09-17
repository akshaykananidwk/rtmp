@extends('layouts.installer')
@section('content')
<h2>Installing…</h2>
<p class="muted">Creating tables, running migrations, seeding roles & settings, creating the admin account, storage directories and the installation lock. This takes a few seconds.</p>
<form method="post" action="{{ route('install.execute') }}" id="install-run-form" data-auto="{{ $errors->any() ? '0' : '1' }}">@csrf<button class="btn btn-primary btn-lg">{{ $errors->any() ? 'Retry installation' : 'Install now' }}</button></form>
@endsection
