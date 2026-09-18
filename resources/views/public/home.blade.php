@extends('layouts.public')
@section('content')
<section class="hero container">
  <h1>ONE LIVE EVERYWHERE</h1>
  <div class="sub">“Stream Once. Reach Everywhere.”</div>
  <p class="gu">OBSમાંથી એક જ જગ્યાએ Live કરો અને connected platforms પર એકસાથે Live પહોંચાડો. One RTMP stream from OBS, vMix or any encoder — automatically distributed to YouTube, Facebook and every RTMP destination you connect.</p>
  <div class="cta">@if($registrationOpen ?? true)<a class="btn btn-primary btn-lg" href="{{ route('register') }}">▶ Start Streaming Free</a>@endif<a class="btn btn-outline btn-lg" href="{{ route('login') }}">Login Dashboard</a></div>
</section>

<section class="section container" id="how">
  <h2>How It Works</h2>
  <p class="lead">Configure destinations once in the dashboard. OBS only needs one server and one key.</p>
  <div class="flow">
    <span class="node">🎥 OBS / vMix / Streamlabs</span><span class="arrow">→</span>
    <span class="node">📡 Our RTMP Ingest</span><span class="arrow">→</span>
    <span class="node">⚙️ Streaming Engine</span><span class="arrow">→</span>
    <span class="node">🖥 AK Computer App</span><span class="arrow">→</span>
    <span class="node">▶️ YouTube · 📘 Facebook · 📡 RTMP</span>
  </div>
</section>

<section class="section container">
  <h2>Features</h2>
  <p class="lead">A production streaming control room in your browser — desktop and mobile.</p>
  <div class="grid grid-3">
    @foreach([['🔑','One stream key','Generate, rotate and revoke secure stream keys per event. Keys are hashed + encrypted, never exposed.'],['📡','Multi-destination','Independent relay per platform. If Facebook fails, YouTube keeps running.'],['🔁','Auto reconnect','Exponential backoff retries (5s → 15s → 30s → 60s) with configurable limits.'],['📅','Scheduled live','Auto-start and auto-stop distribution at a set time with chosen destinations.'],['🎬','Recording','Optional local/S3 recording with retention and a protected download library.'],['📈','Analytics & logs','Real-time bitrate, FPS, codec, destination health, live logs and audit trail.'],['⬆️','GitHub auto-update','Backup → download → verify → migrate → health check → automatic rollback.'],['🛡','Security first','Roles & policies, 2FA, rate limits, encrypted tokens, CSP headers, audit log.'],['🧩','Plugin connectors','Add any RTMP platform without touching the core engine.']] as [$icon,$title,$text])
      <div class="card feature"><div class="icon">{{ $icon }}</div><h3>{{ $title }}</h3><p class="muted">{{ $text }}</p></div>
    @endforeach
  </div>
</section>

<section class="section container">
  <h2>Supported Platforms</h2>
  <p class="lead">Only official APIs. Where a platform has no public Live API we say so clearly.</p>
  <div class="grid grid-3">
    @foreach($platforms as $p)
      <div class="card tight"><div style="display:flex;justify-content:space-between;align-items:center"><strong>{{ $p->icon }} {{ $p->label }}</strong> <span class="badge badge-{{ $p->support === 'supported' ? 'live' : ($p->support === 'partial' ? 'warning' : 'failed') }}">{{ str_replace('_', ' ', $p->support) }}</span></div><p class="muted small" style="margin:8px 0 0">{{ $p->description }}</p></div>
    @endforeach
  </div>
</section>

<section class="section container">
  <h2>Benefits</h2>
  <div class="grid grid-2">
    <div class="card"><h3>For events, temples & hotels</h3><p class="muted">One operator, one OBS, every audience. Perfect for Dwarka events, religious ceremonies, weddings, seminars and hotel promotions.</p></div>
    <div class="card"><h3>For your bandwidth</h3><p class="muted">Upload once from the venue. Our server fans out to every platform — no more multiple encoders eating your internet.</p></div>
    <div class="card"><h3>For reliability</h3><p class="muted">Destination failures never stop the show. Automatic retries, alerts and logs keep you informed.</p></div>
    <div class="card"><h3>For the future</h3><p class="muted">Updates install themselves from GitHub with backup and rollback. No manual file replacement ever again.</p></div>
  </div>
</section>

<section class="section container">
  <h2>Pricing</h2>
  <p class="lead">Flexible plans for individuals, businesses and agencies. Contact us for a quote.</p>
  <div class="grid grid-3">
    @foreach([['Starter','1 stream key · 3 destinations · 720p/1080p','Best for personal channels'],['Business','5 stream keys · 10 destinations · recording · scheduling','Best for studios & events'],['Enterprise','Unlimited · multi-tenant · dedicated media node · SLA','For agencies & broadcasters']] as [$name,$feat,$who])
      <div class="card" style="text-align:center"><h3>{{ $name }}</h3><p class="muted">{{ $feat }}</p><p class="small">{{ $who }}</p><a class="btn btn-outline" href="{{ route('contact') }}">Get a quote</a></div>
    @endforeach
  </div>
</section>

<section class="section container faq">
  <h2>FAQ</h2>
  @include('public.partials.faq')
</section>

<section class="section container" id="contact">
  <h2>Contact</h2>
  @include('public.partials.contact')
</section>
@endsection
