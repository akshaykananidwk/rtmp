<div class="grid grid-2">
  <div class="card"><h3>{{ $brand['name'] }}</h3><p><strong>{{ $brand['owner'] }}</strong><br>📞 <a href="tel:+91{{ $brand['phone'] }}">{{ $brand['phone'] }}</a><br>💬 <a href="https://wa.me/91{{ $brand['phone'] }}" rel="noopener" target="_blank">WhatsApp</a></p><p class="muted">{!! nl2br(e($brand['address'])) !!}<br>GST: {{ $brand['gst'] }}</p></div>
  <div class="card"><h3>Get started</h3><p class="muted">Call us for a demo, installation on your VPS, OBS setup at your venue, or a managed streaming package for your event.</p><a class="btn btn-primary" href="tel:+91{{ $brand['phone'] }}">Call {{ $brand['phone'] }}</a> <a class="btn btn-outline" href="{{ route('login') }}">Login</a></div>
</div>
