<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Settings\SettingsService;
use App\Models\PlatformAccount;
use App\Models\Role;
use App\Models\StreamDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Connecting a Facebook account should be all someone has to do before going live —
 * not a first step that leaves them hunting for a second form.
 */
class ConnectAccountTest extends TestCase
{
    use RefreshDatabase;

    private function configureMeta(): void
    {
        app(SettingsService::class)->setMany('platforms', ['meta_app_id' => '123', 'meta_app_secret' => 'shh']);
    }

    private function fakeGraph(array $pages): void
    {
        Http::fake([
            'https://graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user-token', 'expires_in' => 5184000], 200),
            'https://graph.facebook.com/*/me?*' => Http::response(['id' => 'U1', 'name' => 'Akshay Kanani'], 200),
            'https://graph.facebook.com/*/me/accounts*' => Http::response(['data' => $pages], 200),
            'https://graph.facebook.com/*' => Http::response(['id' => 'U1', 'name' => 'Akshay Kanani'], 200),
        ]);
    }

    private function completeOauth(): TestResponse
    {
        // The controller checks the state it put in the session, so mirror the real redirect.
        $this->withSession(['oauth:state' => 'state-123', 'oauth:platform' => 'facebook']);

        return $this->get('/admin/platforms/facebook/callback?code=auth-code&state=state-123');
    }

    public function test_connecting_an_account_with_one_page_leaves_a_destination_ready_to_go_live(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $this->configureMeta();
        $this->fakeGraph([['id' => 'PAGE1', 'name' => 'AK Computer Page', 'category' => 'Shop']]);

        $this->completeOauth()->assertRedirect(route('admin.destinations.index'));

        $account = PlatformAccount::withoutGlobalScopes()->firstOrFail();
        $destination = StreamDestination::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('facebook', $destination->platform);
        $this->assertSame($account->id, $destination->platform_account_id);
        $this->assertSame('oauth', $destination->connection_method);
        $this->assertTrue($destination->is_enabled);
        $this->assertSame('PAGE1', $destination->option('page_id'), 'a single Page needs no choosing');
    }

    public function test_several_pages_send_the_operator_to_choose_one(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $this->configureMeta();
        $this->fakeGraph([
            ['id' => 'PAGE1', 'name' => 'Shop', 'category' => 'Shop'],
            ['id' => 'PAGE2', 'name' => 'News', 'category' => 'Media'],
        ]);

        $destinationId = null;
        $response = $this->completeOauth();
        $destination = StreamDestination::withoutGlobalScopes()->firstOrFail();
        $destinationId = $destination->id;

        $response->assertRedirect(route('admin.destinations.edit', $destinationId));
        $this->assertNull($destination->option('page_id'), 'the operator must pick, not us');
    }

    public function test_connecting_twice_does_not_pile_up_destinations(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $this->configureMeta();
        $this->fakeGraph([['id' => 'PAGE1', 'name' => 'AK Computer Page']]);

        $this->completeOauth();
        $this->completeOauth();

        $this->assertSame(1, StreamDestination::withoutGlobalScopes()->count());
    }

    public function test_a_state_mismatch_is_refused(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $this->configureMeta();
        $this->fakeGraph([]);

        $this->withSession(['oauth:state' => 'state-123', 'oauth:platform' => 'facebook']);
        $this->get('/admin/platforms/facebook/callback?code=x&state=WRONG')
            ->assertRedirect(route('admin.destinations.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, StreamDestination::withoutGlobalScopes()->count());
        $this->assertSame(0, PlatformAccount::withoutGlobalScopes()->count());
    }

    public function test_a_tenant_admin_is_told_plainly_when_the_server_has_no_credentials(): void
    {
        $this->seedSystem();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, Role::ADMIN);
        $this->actingAs($user);
        $this->actAsTenant($tenant);

        // No Meta credentials configured, and this user cannot open Settings to add them.
        $this->get('/admin/platforms/facebook/connect')
            ->assertRedirect(route('admin.destinations.index'))
            ->assertSessionHas('error');

        $this->followingRedirects()->get('/admin/platforms/facebook/connect')
            ->assertOk()
            ->assertSee('Ask the administrator');
    }

    public function test_an_account_can_be_disconnected_and_connected_again(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);
        $this->actAsTenant($tenant);
        $this->configureMeta();
        $this->fakeGraph([['id' => 'PAGE1', 'name' => 'AK Computer Page']]);

        $this->completeOauth();
        $account = PlatformAccount::withoutGlobalScopes()->firstOrFail();

        $this->delete('/admin/platforms/accounts/'.$account->id)->assertRedirect();
        $this->assertSoftDeleted('platform_accounts', ['id' => $account->id]);
        $this->assertSame(0, $account->tokens()->count(), 'tokens must not survive a disconnect');

        // Reconnecting restores the same account rather than failing on the trashed row.
        $this->completeOauth();
        $restored = PlatformAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($account->id, $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('connected', $restored->status);
        $this->assertSame(1, $restored->tokens()->count());
    }
}
