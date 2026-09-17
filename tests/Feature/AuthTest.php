<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logout_flow(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->get('/login')->assertOk()->assertSee('Sign in');

        $this->post('/login', ['email' => $user->email, 'password' => 'Str0ng!Password#2026'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->id]);

        $this->get('/admin')->assertOk()->assertSee('Dashboard');
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login_failed', 'result' => 'failure']);
    }

    public function test_login_is_locked_after_too_many_attempts(): void
    {
        [$tenant, $user] = $this->adminSetup();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'bad'.$i]);
        }
        $r = $this->post('/login', ['email' => $user->email, 'password' => 'Str0ng!Password#2026']);
        $this->assertTrue($r->getStatusCode() === 429 || $r->getSession()->has('errors'), 'login must be throttled');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $user->forceFill(['is_active' => false])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'Str0ng!Password#2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_two_factor_challenge_required_when_enabled(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $totp = app(Totp::class);
        $secret = $totp->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['aaaa-bbbb']])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'Str0ng!Password#2026'])->assertRedirect('/two-factor');
        $this->assertGuest();
        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/two-factor', ['code' => $totp->code($secret)])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_request_does_not_leak_users(): void
    {
        $this->seedSystem();
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');
    }

    public function test_guest_is_redirected_from_admin(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_security_headers_present(): void
    {
        $this->seedSystem();
        $r = $this->get('/login');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($r->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($r->headers->get('X-Request-Id'));
    }
}
