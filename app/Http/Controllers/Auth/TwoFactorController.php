<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('2fa:user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function verify(Request $request, Totp $totp, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = User::withoutGlobalScopes()->find($request->session()->get('2fa:user_id'));
        if (! $user) {
            return redirect()->route('login');
        }

        $code = preg_replace('/\s+/', '', $data['code']);
        $ok = $totp->verify((string) $user->two_factor_secret, $code);

        if (! $ok) {
            $codes = (array) ($user->two_factor_recovery_codes ?? []);
            if (in_array($code, $codes, true)) {
                $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$code]))])->save();
                $ok = true;
            }
        }

        if (! $ok) {
            $audit->failure('auth.two_factor_failed', $user, []);
            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }

        Auth::login($user, (bool) $request->session()->pull('2fa:remember', false));
        $request->session()->forget('2fa:user_id');
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $audit->log('auth.login', $user, ['two_factor' => true], 'success', $user->id);

        return redirect()->intended(route('admin.dashboard'));
    }
}
