@extends('layouts.auth')
@section('title', 'Something went wrong')
@section('content')
<h2>Something went wrong.</h2>
<p class="muted">Our team has been notified. Please try again in a moment.</p>
<p><strong>Reference ID:</strong> <span class="mono">{{ $reference ?? 'ERR-UNKNOWN' }}</span></p>

@isset($detail)
  {{-- Shown only before installation, where there is no admin panel to look the reference up in. --}}
  <div class="alert alert-error" style="text-align:left">
    <strong>{{ $detail['exception'] }}</strong>
    <div class="small mono" style="margin-top:6px;word-break:break-word">{{ $detail['message'] }}</div>
    <div class="small muted mono" style="margin-top:6px">{{ $detail['file'] }}</div>
  </div>
  @auth
    <p class="small muted">You are seeing this detail because you are a super admin. Full history: <a href="{{ url('admin/logs/errors') }}">Admin → Logs → Errors</a>.
    @if(str_contains(strtolower($detail['message'] ?? ''), 'no such table') || str_contains(strtolower($detail['message'] ?? ''), "doesn't exist"))
      <br><strong>A database table is missing — run the migrations:</strong> <span class="mono">php artisan migrate --force</span>
    @endif
    </p>
  @else
    <p class="small muted">This detail is shown because the application is not installed yet. Run <a href="{{ url('diagnose.php') }}">diagnose.php</a> for a full server check, fix the cause, then continue at <a href="{{ url('install') }}">/install</a>.</p>
  @endauth
@endisset

<a class="btn btn-outline" href="{{ url('/') }}">Home</a> <a class="btn btn-primary" href="{{ url()->previous() }}">Go back</a>
@endsection
