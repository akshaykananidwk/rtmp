<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\InvitationService;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationAcceptController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(string $token): View|RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);

        if (! $invitation) {
            return redirect()->route('login')->with('error', 'This invitation link is no longer valid. Ask for a new one.');
        }

        return view('auth.accept-invitation', [
            'token' => $token,
            'email' => $invitation->email,
            'team' => Tenant::find($invitation->tenant_id)?->name,
        ]);
    }

    public function store(Request $request, string $token, AuditLogger $audit): RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);

        if (! $invitation) {
            return redirect()->route('login')->with('error', 'This invitation link is no longer valid. Ask for a new one.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->invitations->accept($invitation, $data['name'], $data['password']);

        Auth::login($user);
        $request->session()->regenerate();
        $audit->log('invitation.accepted', $user, ['email' => $user->email], 'success', $user->id);

        return redirect()->route('admin.dashboard')->with('status', 'Welcome to the team!');
    }
}
