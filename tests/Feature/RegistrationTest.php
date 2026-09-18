<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\OnboardingChecklist;
use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\StreamSessionService;
use App\Models\Role;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Akshay Kanani',
            'business_name' => 'AK Computer',
            'email' => 'new@example.com',
            'password' => 'Tr0ub4dor&3-AK-9x',
            'password_confirmation' => 'Tr0ub4dor&3-AK-9x',
            'terms' => '1',
        ], $overrides);
    }

    public function test_a_visitor_can_sign_up_and_lands_on_a_working_account(): void
    {
        $this->seedSystem();

        $this->get('/register')->assertOk()->assertSee('Create your account');
        $this->post('/register', $this->form())->assertRedirect(route('admin.obs-setup'));

        $user = User::withoutGlobalScopes()->where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $tenant = Tenant::findOrFail($user->tenant_id);
        $this->assertSame('AK Computer', $tenant->name);
        $this->assertSame('ak-computer', $tenant->slug);

        // Owners administer their own tenant, never the server.
        $this->assertTrue($user->hasRole(Role::ACCOUNT_OWNER));
        $this->assertFalse($user->hasRole(Role::SUPER_ADMIN));
        $this->assertFalse($user->hasRole(Role::ADMIN));

        // A new account is immediately usable.
        $keys = StreamEndpoint::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $this->assertCount(1, $keys);
        $this->assertNotEmpty($keys->first()->plainKey());

        $this->get('/admin/obs-setup')->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.registered']);
    }

    public function test_the_password_is_hashed_and_never_stored_in_the_clear(): void
    {
        $this->seedSystem();
        $this->post('/register', $this->form());

        $stored = (string) User::withoutGlobalScopes()->where('email', 'new@example.com')->value('password');
        $this->assertNotSame('Tr0ub4dor&3-AK-9x', $stored);
        $this->assertTrue(password_verify('Tr0ub4dor&3-AK-9x', $stored));
    }

    public function test_two_accounts_cannot_see_each_others_data(): void
    {
        $this->seedSystem();

        $this->post('/register', $this->form());
        $first = User::withoutGlobalScopes()->where('email', 'new@example.com')->firstOrFail();
        $firstKey = StreamEndpoint::withoutGlobalScopes()->where('tenant_id', $first->tenant_id)->firstOrFail();
        $this->post('/logout');

        $this->post('/register', $this->form(['email' => 'second@example.com', 'business_name' => 'Second Shop']));
        $second = User::withoutGlobalScopes()->where('email', 'second@example.com')->firstOrFail();

        $this->assertNotSame($first->tenant_id, $second->tenant_id);

        // Signed in as the second account, the first account's key must not resolve.
        $this->get('/admin/stream-keys')->assertOk()->assertDontSee($firstKey->name === 'My stream' ? $firstKey->plainKey() : 'never');
        $this->get('/admin/stream-keys/'.$firstKey->id.'/edit')->assertNotFound();
    }

    public function test_a_duplicate_email_is_rejected_without_creating_anything(): void
    {
        $this->seedSystem();
        $this->post('/register', $this->form());
        $this->post('/logout');

        $tenants = Tenant::count();
        $this->from('/register')->post('/register', $this->form(['business_name' => 'Copycat']))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertSame($tenants, Tenant::count(), 'a rejected sign-up must not leave a tenant behind');
    }

    public function test_a_weak_password_and_unticked_terms_are_rejected(): void
    {
        $this->seedSystem();

        $this->from('/register')->post('/register', $this->form(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');

        $this->from('/register')->post('/register', $this->form(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'new@example.com')->count());
    }

    public function test_the_owner_can_close_sign_ups(): void
    {
        $this->seedSystem();
        app(SettingsService::class)->set('registration', 'open', false);

        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', $this->form())->assertRedirect(route('login'));
        $this->assertSame(0, User::withoutGlobalScopes()->where('email', 'new@example.com')->count());
    }

    public function test_sign_ups_are_rate_limited(): void
    {
        $this->seedSystem();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', $this->form(['email' => 'user'.$i.'@example.com']));
            $this->post('/logout');
        }

        $this->post('/register', $this->form(['email' => 'flood@example.com']))->assertStatus(429);
    }

    public function test_plan_limits_are_enforced_for_a_self_service_account(): void
    {
        $this->seedSystem();
        app(SettingsService::class)->set('registration', 'max_stream_keys', 2);
        app(SettingsService::class)->set('registration', 'max_destinations', 1);

        $this->post('/register', $this->form());

        // Sign-up already created one key, so one more is allowed and the third is not.
        $this->post('/admin/stream-keys', ['name' => 'Second key'])->assertSessionHasNoErrors();
        $this->assertSame(2, StreamEndpoint::withoutGlobalScopes()->count());

        $this->from('/admin/stream-keys/create')->post('/admin/stream-keys', ['name' => 'Third key']);
        $this->assertSame(2, StreamEndpoint::withoutGlobalScopes()->count(), 'the limit shown in Settings must actually hold');

        $destination = ['platform' => 'custom_rtmp', 'name' => 'One', 'p' => ['custom_rtmp' => ['rtmp_url' => 'rtmp://a.example.com/live', 'stream_key' => 'k']]];
        $this->post('/admin/destinations', $destination)->assertSessionHasNoErrors();
        $this->assertSame(1, StreamDestination::withoutGlobalScopes()->count());

        $this->post('/admin/destinations', array_merge($destination, ['name' => 'Two']));
        $this->assertSame(1, StreamDestination::withoutGlobalScopes()->count());
    }

    public function test_the_server_owner_is_not_limited_by_its_own_settings(): void
    {
        [$tenant, $owner] = $this->adminSetup();
        $this->actingAs($owner);
        $this->actAsTenant($tenant);
        app(SettingsService::class)->set('registration', 'max_stream_keys', 1);

        $this->post('/admin/stream-keys', ['name' => 'One']);
        $this->post('/admin/stream-keys', ['name' => 'Two']);

        $this->assertSame(2, StreamEndpoint::withoutGlobalScopes()->count());
    }

    public function test_a_new_account_is_shown_what_to_do_next(): void
    {
        $this->seedSystem();
        $this->post('/register', $this->form());

        // Sign-up created the key, so that step is already ticked and the next one is shown.
        $dashboard = $this->get('/admin')->assertOk();
        $dashboard->assertSee('Getting started');
        $dashboard->assertSee('Add where to stream');
        $dashboard->assertSee('1 of 4 done');

        // Calling the service straight needs the tenant context the HTTP middleware sets.
        $this->actAsTenant(Tenant::findOrFail(User::withoutGlobalScopes()->where('email', 'new@example.com')->value('tenant_id')));
        $checklist = app(OnboardingChecklist::class);
        $this->assertFalse($checklist->isComplete());
        $this->assertSame('destination', $checklist->nextStep()['key']);

        $this->post('/admin/destinations', [
            'platform' => 'custom_rtmp', 'name' => 'Backup relay',
            'p' => ['custom_rtmp' => ['rtmp_url' => 'rtmp://a.example.com/live', 'stream_key' => 'k']],
        ])->assertSessionHasNoErrors();

        $this->get('/admin')->assertOk()->assertSee('Start streaming from OBS');

        // Once a stream has actually run, the checklist stops taking up the dashboard.
        $endpoint = StreamEndpoint::withoutGlobalScopes()->firstOrFail();
        app(StreamSessionService::class)->onSourceReady($endpoint);
        $this->get('/admin')->assertOk()->assertDontSee('Getting started');
    }
}
