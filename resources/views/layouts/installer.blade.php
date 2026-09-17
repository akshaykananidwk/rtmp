<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Installer – Step {{ $step ?? 0 }} | AK COMPUTER</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body>
<div class="container" style="max-width:820px;padding-top:40px;padding-bottom:40px">
  <div style="text-align:center;margin-bottom:24px"><div style="font-weight:800;font-size:1.3rem">🔴 AK COMPUTER</div><div class="muted" style="letter-spacing:.2em;font-size:.8rem">ONE LIVE EVERYWHERE · INSTALLER</div></div>
  <div class="steps">
    @foreach(['Welcome', 'Requirements', 'Database', 'Application', 'Admin', 'Install', 'Done'] as $i => $label)
      <span class="{{ ($step ?? 0) === $i ? 'active' : (($step ?? 0) > $i ? 'done' : '') }}">{{ $i }}. {{ $label }}</span>
    @endforeach
  </div>
  <div class="card">
    @if($errors->any())<div class="alert alert-error">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @yield('content')
  </div>
</div>
<script src="{{ asset('assets/js/app.js') }}" defer></script>
</body>
</html>
