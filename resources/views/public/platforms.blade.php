@extends('layouts.public')
@section('content')
<section class="section container"><h2>Supported Platforms</h2><p class="lead">Platform support depends on official APIs and account permissions. No scraping, no workarounds.</p>
<div class="grid grid-2">
@foreach($platforms as $p)
<div class="card"><div class="card-header"><h3 style="margin:0">{{ $p->icon }} {{ $p->label }}</h3><span class="badge badge-{{ $p->support === 'supported' ? 'live' : ($p->support === 'partial' ? 'warning' : 'failed') }}">{{ str_replace('_',' ',$p->support) }}</span></div><p class="muted">{{ $p->description }}</p><p class="small">Connection: {{ $p->oauthSupported ? 'OAuth (official API) or manual RTMP key' : 'RTMP URL + stream key' }}</p></div>
@endforeach
</div></section>
@endsection
