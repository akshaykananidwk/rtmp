@extends('layouts.installer')
@section('content')
<h2>Welcome to the installer</h2><p class="muted">This wizard will check your server, connect the database, create the admin account, run migrations and secure the installer — no manual SQL import, no .env editing.</p>
<ul class="muted"><li>PHP 8.2+, MySQL 8 / MariaDB (or SQLite for testing)</li><li>Write access to <span class="mono">storage/</span>, <span class="mono">bootstrap/cache/</span> and <span class="mono">.env</span></li><li>Streaming features (RTMP/FFmpeg/MediaMTX) require a VPS — the web app itself runs on shared hosting</li></ul>
<a class="btn btn-primary btn-lg" href="{{ route('install.requirements') }}">Start →</a>
@endsection
