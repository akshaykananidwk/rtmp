<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\EmailVerifier;
use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\StreamSessionService;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSessionDestination;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function signUp(): User
    {
        $this->post('/register', [
            'name' => 'Owner', 'business_name' => 'AK Computer', 'email' => 'owner@example.com',
            'password' => 'Tr0ub4dor&3-AK-9x', 'password_confirmation' => 'Tr0ub4dor&3-AK-9x', 'terms' => '1',
        ]);

        return User::withoutGlobalScopes()->where('email', 'owner@example.com')->firstOrFail();
    }

    public function test_signing_up_sends_a_link_that_confirms_the_address(): void
    {
        $this->seedSystem();
        Notification::fake();

        $user = $this->signUp();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->get(app(EmailVerifier::class)->link($user))->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_or_unsigned_link_is_refused(): void
    {
        $this->seedSystem();
        Notification::fake();
        $user = $this->signUp();

        // Without the signature the route is not reachable at all.
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertForbidden();

        // A valid signature for a hash that does not match the address is refused too.
        $link = app(EmailVerifier::class)->link($user);
        $tampered = str_replace(sha1($user->email), sha1('someone@else.example'), $link);
        $this->get($tampered)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_changing_the_address_invalidates_an_outstanding_link(): void
    {
        $this->seedSystem();
        Notification::fake();
        $user = $this->signUp();
        $link = app(EmailVerifier::class)->link($user);

        $user->forceFill(['email' => 'moved@example.com'])->save();

        $this->get($link)->assertRedirect(route('login'));
        $this->assertNull($user->fresh()->email_verified_at, 'the link was for the old address');
    }

    public function test_an_unconfigured_mail_server_does_not_break_signing_up(): void
    {
        $this->seedSystem();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('Connection could not be established'));

        $this->post('/register', [
            'name' => 'Owner', 'business_name' => 'Shop', 'email' => 'nomail@example.com',
            'password' => 'Tr0ub4dor&3-AK-9x', 'password_confirmation' => 'Tr0ub4dor&3-AK-9x', 'terms' => '1',
        ])->assertRedirect(route('admin.obs-setup'));

        $this->assertNotNull(User::withoutGlobalScopes()->where('email', 'nomail@example.com')->first());
    }

    public function test_verification_only_blocks_streaming_when_the_operator_asks_for_it(): void
    {
        $this->seedSystem();
        Notification::fake();
        $user = $this->signUp();

        $tenant = Tenant::findOrFail($user->tenant_id);
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        StreamDestination::factory()->create(['tenant_id' => $tenant->id]);
        app(StreamSessionService::class)->onSourceReady($endpoint);

        // Off by default, because a box with no SMTP would lock everybody out.
        $this->assertFalse(app(EmailVerifier::class)->blocks($user));
        $this->post('/admin/live/start');
        $this->assertGreaterThan(0, StreamSessionDestination::withoutGlobalScopes()->count());

        StreamSessionDestination::withoutGlobalScopes()->delete();
        app(SettingsService::class)->set('registration', EmailVerifier::REQUIRED, true);

        $this->post('/admin/live/start')->assertRedirect();
        $this->assertSame(0, StreamSessionDestination::withoutGlobalScopes()->count());
        $this->assertStringContainsString('Confirm your e-mail', (string) session('error'));

        // Once confirmed, streaming works again. (The guard caches the user for the life of
        // the test application, so drop it — a real request resolves it afresh each time.)
        app(EmailVerifier::class)->markVerified($user);
        $this->app['auth']->forgetGuards();
        $this->post('/admin/live/start');
        $this->assertGreaterThan(0, StreamSessionDestination::withoutGlobalScopes()->count());
    }

    public function test_the_banner_appears_until_the_address_is_confirmed(): void
    {
        $this->seedSystem();
        Notification::fake();
        $user = $this->signUp();

        $this->get('/admin')->assertOk()->assertSee('Confirm owner@example.com');

        app(EmailVerifier::class)->markVerified($user);
        $this->app['auth']->forgetGuards();
        $this->get('/admin')->assertOk()->assertDontSee('Confirm owner@example.com');
    }

    public function test_resending_is_a_single_message(): void
    {
        $this->seedSystem();
        Notification::fake();
        $user = $this->signUp();
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

        $this->post('/verify-email/resend')->assertRedirect();
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2);
    }
}
