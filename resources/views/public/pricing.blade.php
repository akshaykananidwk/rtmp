@extends('layouts.public')
@section('content')
<section class="section container"><h2>Pricing</h2><p class="lead">Pricing-ready plans. Final pricing is quoted per deployment (self-hosted VPS or managed by AK Computer).</p>
<div class="grid grid-3">
@foreach([['Starter','₹ —','1 stream key','3 destinations','Recording 7 days','E-mail alerts'],['Business','₹ —','5 stream keys','10 destinations','Recording 30 days','Scheduling + WhatsApp alerts'],['Enterprise','₹ —','Unlimited keys','Unlimited destinations','S3 storage','Dedicated media node + SLA']] as $plan)
<div class="card" style="text-align:center"><h3>{{ $plan[0] }}</h3><div class="stat"><span class="value">{{ $plan[1] }}</span></div><ul style="list-style:none;padding:0;color:var(--muted)">@foreach(array_slice($plan,2) as $f)<li>✔ {{ $f }}</li>@endforeach</ul><a class="btn btn-primary" href="{{ route('contact') }}">Contact for quote</a></div>
@endforeach
</div></section>
@endsection
