<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\EmailVerifier;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerifier $verifier) {}

    public function verify(Request $request, string $user, string $hash, AuditLogger $audit): RedirectResponse
    {
        $target = User::withoutGlobalScopes()->find($user);

        if (! $target || ! $this->verifier->matches($target, $hash)) {
            return redirect()->route('login')->with('error', 'That confirmation link is not valid. Sign in and ask for a new one.');
        }

        $this->verifier->markVerified($target);
        $audit->log('email.verified', $target, [], 'success', $target->id);

        return redirect()->route($request->user() ? 'admin.dashboard' : 'login')
            ->with('status', 'Thank you — '.$target->email.' is confirmed.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return back()->with('status', 'Your e-mail is already confirmed.');
        }

        $sent = $this->verifier->send($user);

        return $sent
            ? back()->with('status', 'Confirmation e-mail sent to '.$user->email.'.')
            : back()->with('error', 'Could not send the e-mail — check Settings → E-mail (SMTP), or ask the administrator.');
    }
}
