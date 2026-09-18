@auth
  @if(auth()->user()->email_verified_at === null)
    <div class="alert {{ $verificationRequired ? 'alert-error' : 'alert-warning' }}" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <div style="flex:1">
        <strong>Confirm {{ auth()->user()->email }}</strong> —
        {{ $verificationRequired
            ? 'you cannot start a stream until this address is confirmed.'
            : 'so we can reach you about your streams.' }}
      </div>
      <form method="post" action="{{ route('verification.resend') }}">@csrf<button class="btn btn-sm btn-outline">Send the link again</button></form>
    </div>
  @endif
@endauth
