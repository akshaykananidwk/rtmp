<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Role;
use App\Models\StreamEndpoint;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anyone can now create an account, so an account owner is an untrusted party.
 * They administer their own tenant and nothing else — not the server, not other tenants.
 */
class SelfServiceAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function signUp(string $email = 'owner@example.com', string $business = 'Owner Shop'): User
    {
        $this->post('/register', [
            'name' => 'Owner', 'business_name' => $business, 'email' => $email,
            'password' => 'Tr0ub4dor&3-AK-9x', 'password_confirmation' => 'Tr0ub4dor&3-AK-9x', 'terms' => '1',
        ]);

        return User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
    }

    public function test_an_account_owner_cannot_reach_server_wide_administration(): void
    {
        $this->seedSystem();
        $this->signUp();

        // Whole-server surfaces: these change or expose the machine every tenant shares —
        // SMTP and platform API secrets, every tenant's backup, the update system, infra.
        foreach (['/admin/settings', '/admin/updates', '/admin/backups', '/admin/health', '/admin/cron-setup'] as $path) {
            $this->get($path)->assertForbidden();
        }

        // Their own data stays reachable, or the account would be useless.
        foreach (['/admin', '/admin/stream-keys', '/admin/destinations', '/admin/overlays', '/admin/recordings'] as $path) {
            $this->get($path)->assertOk();
        }

        foreach ([['post', '/admin/settings/security'], ['post', '/admin/updates/install'], ['post', '/admin/backups'], ['post', '/admin/updates/recover']] as [$method, $path]) {
            $response = $this->call($method, $path, []);
            $this->assertContains($response->getStatusCode(), [403, 302, 419], strtoupper($method).' '.$path.' should not be allowed');
            $this->assertNotEquals(200, $response->getStatusCode());
        }
    }

    public function test_an_account_owner_cannot_make_themselves_a_super_admin(): void
    {
        $this->seedSystem();
        $owner = $this->signUp();

        $this->put('/admin/users/'.$owner->id, [
            'name' => 'Owner', 'email' => $owner->email, 'role' => Role::SUPER_ADMIN,
            'timezone' => 'Asia/Kolkata', 'is_active' => '1',
        ]);

        $this->assertFalse($owner->fresh()->hasRole(Role::SUPER_ADMIN), 'privilege escalation through the user form');

        $this->post('/admin/users', [
            'name' => 'Puppet', 'email' => 'puppet@example.com',
            'password' => 'Tr0ub4dor&3-AK-9x', 'password_confirmation' => 'Tr0ub4dor&3-AK-9x',
            'role' => Role::SUPER_ADMIN, 'timezone' => 'Asia/Kolkata', 'is_active' => '1',
        ]);

        $puppet = User::withoutGlobalScopes()->where('email', 'puppet@example.com')->first();
        $this->assertTrue($puppet === null || ! $puppet->hasRole(Role::SUPER_ADMIN), 'a new super admin must not be creatable from a signed-up account');
    }

    public function test_one_account_cannot_touch_another_accounts_records(): void
    {
        $this->seedSystem();
        $first = $this->signUp('first@example.com', 'First Shop');
        $firstKey = StreamEndpoint::withoutGlobalScopes()->where('tenant_id', $first->tenant_id)->firstOrFail();
        $this->post('/logout');

        $this->signUp('second@example.com', 'Second Shop');

        // Reading, editing, revealing and deleting all resolve to "not found" across tenants.
        $this->get('/admin/stream-keys/'.$firstKey->id.'/edit')->assertNotFound();
        $this->put('/admin/stream-keys/'.$firstKey->id, ['name' => 'stolen'])->assertNotFound();
        $this->post('/admin/stream-keys/'.$firstKey->id.'/reveal')->assertNotFound();
        $this->post('/admin/stream-keys/'.$firstKey->id.'/regenerate')->assertNotFound();
        $this->delete('/admin/stream-keys/'.$firstKey->id)->assertNotFound();

        $this->assertSame($firstKey->key_hash, $firstKey->fresh()->key_hash, 'the other account\'s key must be untouched');

        // And the other account's user is equally out of reach (403 or 404, never granted).
        $this->assertContains($this->get('/admin/users/'.$first->id.'/edit')->getStatusCode(), [403, 404]);
        $this->assertContains($this->delete('/admin/users/'.$first->id)->getStatusCode(), [403, 404]);
        $this->assertNotNull($first->fresh());
    }

    public function test_the_first_account_does_not_become_the_server_owner(): void
    {
        $this->seedSystem();
        $user = $this->signUp();

        $this->assertTrue($user->hasRole(Role::ACCOUNT_OWNER));
        $this->assertFalse($user->hasRole(Role::SUPER_ADMIN), 'signing up first must not hand over the server');
        $this->assertFalse($user->hasRole(Role::ADMIN), 'the staff role carries server-wide rights and is not for sign-ups');
    }

    public function test_an_account_owner_cannot_see_another_tenants_stream_key_anywhere(): void
    {
        $this->seedSystem();
        $first = $this->signUp('first@example.com', 'First Shop');
        $firstKey = StreamEndpoint::withoutGlobalScopes()->where('tenant_id', $first->tenant_id)->firstOrFail();
        $plain = $firstKey->plainKey();
        $this->post('/logout');

        $this->signUp('second@example.com', 'Second Shop');

        foreach (['/admin', '/admin/stream-keys', '/admin/obs-setup', '/admin/live', '/admin/history', '/admin/logs'] as $path) {
            $this->get($path)->assertOk()->assertDontSee($plain);
        }
    }

    public function test_an_account_owner_only_sees_their_own_errors_and_audit_trail(): void
    {
        $this->seedSystem();
        $other = $this->signUp('first@example.com', 'First Shop');
        ErrorLog::create([
            'tenant_id' => $other->tenant_id,
            'reference' => 'ERR-OTHER-0001',
            'exception_class' => 'RuntimeException',
            'message' => 'a fault inside somebody else account',
            'file' => 'x.php',
            'line' => 1,
        ]);
        $this->post('/logout');

        $this->signUp('second@example.com', 'Second Shop');

        // The page is allowed, because it only ever shows this account's own rows.
        $this->get('/admin/logs/errors')->assertOk()->assertDontSee('ERR-OTHER-0001');
        $this->get('/admin/logs/audit')->assertOk()->assertDontSee('first@example.com');
    }
}
