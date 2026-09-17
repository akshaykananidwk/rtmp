<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>@yield('title', 'Login') | {{ $brand['name'] }}</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('assets/img/favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ $appVersion }}">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div style="text-align:center;margin-bottom:22px"><a href="{{ route('home') }}" style="font-weight:800;color:var(--text);font-size:1.1rem">🔴 {{ $brand['name'] }}</a><div class="muted small" style="letter-spacing:.2em">ONE LIVE EVERYWHERE</div></div>
    <div class="card">
      @include('components.flash')
      @yield('content')
    </div>
    <p class="muted small" style="text-align:center;margin-top:16px">v{{ $appVersion }} · <a href="{{ route('home') }}">Back to website</a></p>
  </div>
</div>
<script src="{{ asset('assets/js/app.js') }}?v={{ $appVersion }}" defer></script>
</body>
</html>
