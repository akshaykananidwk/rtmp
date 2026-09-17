<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string', 'max:200'], 'remember' => ['nullable', 'boolean']]);

        $key = 'login-attempts:'.strtolower($data['email']).'|'.$request->ip();
        $max = (int) app(SettingsService::class)->get('security', 'login_max_attempts', 5);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $audit->failure('auth.login_locked', null, ['email' => $data['email']]);
            throw ValidationException::withMessages(['email' => 'Too many login attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }

        $user = User::withoutGlobalScopes()->where('email', strtolower($data['email']))->first();
        if (! $user || ! $user->is_active || ! Auth::validate(['email' => $user->email, 'password' => $data['password']])) {
            RateLimiter::hit($key, 15 * 60);
            $audit->failure('auth.login_failed', null, ['email' => $data['email']]);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('2fa:user_id', $user->id);
            $request->session()->put('2fa:remember', (bool) ($data['remember'] ?? false));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $audit->log('auth.login', $user, [], 'success', $user->id);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->log('auth.logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
