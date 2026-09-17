<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $meta['title'] ?? 'ONE LIVE EVERYWHERE' }} | {{ $brand['name'] }}</title>
<meta name="description" content="{{ $meta['description'] ?? $brand['tagline'] }}">
<link rel="canonical" href="{{ $meta['canonical'] ?? url()->current() }}">
<meta property="og:type" content="website"><meta property="og:site_name" content="{{ $brand['name'] }} – {{ $brand['product'] }}">
<meta property="og:title" content="{{ $meta['title'] ?? 'ONE LIVE EVERYWHERE' }}"><meta property="og:description" content="{{ $meta['description'] ?? $brand['tagline'] }}"><meta property="og:url" content="{{ url()->current() }}"><meta property="og:image" content="{{ asset('assets/img/og.svg') }}">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="{{ $meta['title'] ?? 'ONE LIVE EVERYWHERE' }}"><meta name="twitter:description" content="{{ $meta['description'] ?? $brand['tagline'] }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('assets/img/favicon.svg') }}" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ $appVersion }}">
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $brand['name'], 'telephone' => '+91'.$brand['phone'], 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1st Floor, Shreeji Shopping Center, Near City Palace Hotel', 'addressLocality' => 'Dwarka', 'addressRegion' => 'Gujarat', 'postalCode' => '361335', 'addressCountry' => 'IN'], 'url' => url('/'), 'description' => $meta['description'] ?? $brand['tagline'], 'makesOffer' => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'SoftwareApplication', 'name' => $brand['product'], 'applicationCategory' => 'MultimediaApplication', 'operatingSystem' => 'Web']]], JSON_UNESCAPED_SLASHES) !!}</script>
</head>
<body>
<header class="container">
  <nav class="nav">
    <a class="logo" href="{{ route('home') }}">🔴 {{ $brand['name'] }} <b>ONE LIVE EVERYWHERE</b></a>
    <button class="nav-toggle" data-toggle="#public-menu" aria-label="Menu">☰</button>
    <ul id="public-menu">
      <li><a href="{{ route('how-it-works') }}">How It Works</a></li>
      <li><a href="{{ route('features') }}">Features</a></li>
      <li><a href="{{ route('platforms') }}">Platforms</a></li>
      <li><a href="{{ route('pricing') }}">Pricing</a></li>
      <li><a href="{{ route('faq') }}">FAQ</a></li>
      <li><a href="{{ route('contact') }}">Contact</a></li>
      @auth<li><a class="btn btn-primary btn-sm" href="{{ route('admin.dashboard') }}">Dashboard</a></li>@else<li><a class="btn btn-outline btn-sm" href="{{ route('login') }}">Login</a></li><li><a class="btn btn-primary btn-sm" href="{{ route('admin.login') }}">Admin Login</a></li>@endauth
    </ul>
  </nav>
</header>
<main>@yield('content')</main>
<footer>
  <div class="container grid grid-3">
    <div><strong style="color:var(--text)">{{ $brand['name'] }}</strong><br>{{ $brand['product'] }}<br>{{ $brand['tagline'] }}<br><span class="small">v{{ $appVersion }}</span></div>
    <div><strong style="color:var(--text)">Contact</strong><br>{{ $brand['owner'] }}<br>📞 <a href="tel:+91{{ $brand['phone'] }}">{{ $brand['phone'] }}</a><br>{!! nl2br(e($brand['address'])) !!}<br>GST: {{ $brand['gst'] }}</div>
    <div><strong style="color:var(--text)">Keywords</strong><br><span class="small">live streaming software · multi platform live streaming · RTMP streaming server · stream to multiple platforms · OBS multi streaming · live streaming software Gujarat · live streaming Dwarka</span></div>
  </div>
  <div class="container" style="margin-top:20px">© {{ date('Y') }} {{ $brand['name'] }}. All rights reserved.</div>
</footer>
<script src="{{ asset('assets/js/app.js') }}?v={{ $appVersion }}" defer></script>
</body>
</html>
