<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\RegistrationService;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    public function show(): View|RedirectResponse
    {
        if (! $this->registration->isOpen()) {
            return redirect()->route('login')->with('error', 'Sign-up is currently closed. Please contact the administrator for an account.');
        }

        return view('auth.register');
    }

    public function store(RegisterRequest $request, AuditLogger $audit): RedirectResponse
    {
        if (! $this->registration->isOpen()) {
            return redirect()->route('login')->with('error', 'Sign-up is currently closed.');
        }

        $user = $this->registration->register($request->validated());

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $audit->log('auth.registered', $user, ['tenant' => $user->tenant_id], 'success', $user->id);

        return redirect()->route('admin.obs-setup')
            ->with('status', 'Welcome to '.config('akstream.brand.name', 'AK COMPUTER').'! Your stream key is ready — set up OBS below, then add the platforms you want to stream to.');
    }
}
