<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\InvitationService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', 'string', Rule::in(collect($this->invitations->assignableRoles($request->user()))->pluck('name')->all())],
        ]);

        $email = strtolower($data['email']);

        if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
            return back()->withInput()->with('error', 'Somebody already uses that e-mail address. Ask them to sign in instead.');
        }

        if (! $this->invitations->canAssign($request->user(), $data['role'])) {
            return back()->withInput()->with('error', 'You cannot invite somebody with more rights than you have.');
        }

        ['invitation' => $invitation, 'token' => $token] = $this->invitations->invite($tenant->get(), $request->user(), $email, $data['role']);
        $this->audit->log('invitation.sent', $invitation, ['email' => $email, 'role' => $data['role']]);

        // Mail is optional on a self-hosted box, so the link is shown once here as well.
        return back()
            ->with('status', 'Invitation created for '.$email.'. It expires in '.InvitationService::LIFETIME_DAYS.' days.')
            ->with('invitation_link', route('invitations.accept', $token));
    }

    public function destroy(TeamInvitation $invitation): RedirectResponse
    {
        $this->authorize('create', User::class);

        if ($invitation->isPending()) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $this->audit->log('invitation.revoked', $invitation, ['email' => $invitation->email]);
        }

        return back()->with('status', 'Invitation revoked — the link no longer works.');
    }
}
