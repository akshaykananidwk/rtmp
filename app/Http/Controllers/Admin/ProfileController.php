<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly Totp $totp) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        $pending = $request->session()->get('2fa:pending_secret');

        return view('admin.profile', [
            'user' => $user,
            'tokens' => $user->tokens()->latest()->get(),
            'pendingSecret' => $pending,
            'pendingUri' => $pending ? $this->totp->provisioningUri($pending, $user->email, config('akstream.brand.name')) : null,
            'newToken' => $request->session()->get('new_api_token'),
            'recoveryCodes' => $request->session()->get('recovery_codes'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s\-]+$/'],
            'timezone' => ['required', 'timezone:all'],
        ]);
        $user->update($data);
        $this->audit->log('profile.updated', $user);

        return back()->with('status', 'Profile updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', Password::defaults()]]);
        $request->user()->update(['password' => $data['password']]);
        $this->audit->log('auth.password_changed', $request->user());

        return back()->with('status', 'Password changed.');
    }

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $request->session()->put('2fa:pending_secret', $this->totp->generateSecret());

        return back()->with('status', 'Scan the code with your authenticator app and confirm.');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = (string) $request->session()->get('2fa:pending_secret');
        if ($secret === '' || ! $this->totp->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Invalid code, please try again.']);
        }
        $codes = $this->totp->recoveryCodes();
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $codes, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('2fa:pending_secret');
        $this->audit->log('auth.two_factor_enabled', $request->user());

        return back()->with('status', 'Two-factor authentication enabled. Save your recovery codes.')->with('recovery_codes', $codes);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('auth.two_factor_disabled', $request->user());

        return back()->with('status', 'Two-factor authentication disabled.');
    }

    public function createToken(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'abilities' => ['nullable', 'array'], 'abilities.*' => ['string', Rule::in(['read', 'control', 'manage'])]]);
        // Unticking every ability box leaves the key out of the validated data entirely,
        // so it has to be defaulted rather than indexed — otherwise the form 500s.
        $token = $request->user()->createToken($data['name'], ($data['abilities'] ?? []) ?: ['read'], now()->addYear());
        $this->audit->log('api_token.created', $request->user(), ['name' => $data['name']]);

        return back()->with('status', 'API token created. Copy it now – it will not be shown again.')->with('new_api_token', $token->plainTextToken);
    }

    public function revokeToken(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();
        $this->audit->log('api_token.revoked', $request->user(), ['token_id' => $tokenId]);

        return back()->with('status', 'API token revoked.');
    }
}
