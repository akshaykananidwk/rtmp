@extends('layouts.public')
@section('content')
<section class="section container"><h2>How It Works</h2><p class="lead">Three steps from OBS to every platform.</p>
<div class="grid grid-3">
<div class="card"><h3>1. Create a stream key</h3><p class="muted">In the dashboard create a key such as <span class="mono">AKDWK-EVENT-001</span>. Copy the server URL and key into OBS → Settings → Stream → Custom.</p></div>
<div class="card"><h3>2. Connect destinations</h3><p class="muted">Connect YouTube/Facebook with OAuth or paste RTMP URL + key for any other platform. Test each destination.</p></div>
<div class="card"><h3>3. Go live</h3><p class="muted">Press Start Streaming in OBS. The dashboard shows "Incoming Stream Detected". Press Start Distribution (or enable auto mode) and every destination goes live.</p></div>
</div>
<div class="card" style="margin-top:24px"><h3>Architecture</h3><pre class="code">Internet ──HTTPS──▶ Apache ─▶ PHP (Laravel) ─▶ MySQL / Redis / Queue
                                   │
                                   ▼ control API
                         Streaming Engine (MediaMTX)
                     RTMP ingest · HLS preview · FFmpeg relays
                                   │
                 ┌─────────────────┼─────────────────┐
                 ▼                 ▼                 ▼
              YouTube           Facebook        Custom RTMP</pre></div>
</section>
@endsection
