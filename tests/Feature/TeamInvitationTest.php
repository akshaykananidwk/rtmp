<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounts\InvitationService;
use App\Models\Role;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOfNewAccount(string $email = 'owner@example.com'): User
    {
        $this->post('/register', [
            'name' => 'Owner', 'business_name' => 'AK Computer', 'email' => $email,
            'password' => 'Tr0ub4dor&3-AK-9x', 'password_confirmation' => 'Tr0ub4dor&3-AK-9x', 'terms' => '1',
        ]);

        return User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
    }

    public function test_a_colleague_can_be_invited_and_joins_the_same_account(): void
    {
        $this->seedSystem();
        $owner = $this->ownerOfNewAccount();

        $response = $this->post('/admin/invitations', ['email' => 'Colleague@Example.com', 'role' => Role::OPERATOR]);
        $response->assertRedirect();

        $link = session('invitation_link');
        $this->assertNotNull($link, 'the link is shown once because mail may not be configured');

        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('colleague@example.com', $invitation->email, 'addresses are normalised');
        $this->assertSame('pending', $invitation->status());

        // Only the hash is kept, so the database alone cannot be used to join.
        $this->assertStringNotContainsString($invitation->token_hash, $link);
        $token = basename(parse_url($link, PHP_URL_PATH));
        $this->assertSame($invitation->token_hash, InvitationService::hash($token));

        $this->post('/logout');
        $this->get($link)->assertOk()->assertSee('AK Computer')->assertSee('colleague@example.com');

        $this->post($link, [
            'name' => 'Colleague', 'password' => 'An0ther!Str0ng-Pass', 'password_confirmation' => 'An0ther!Str0ng-Pass',
        ])->assertRedirect(route('admin.dashboard'));

        $joined = User::withoutGlobalScopes()->where('email', 'colleague@example.com')->firstOrFail();
        $this->assertSame($owner->tenant_id, $joined->tenant_id, 'they join the inviting account, not a new one');
        $this->assertTrue($joined->hasRole(Role::OPERATOR));
        $this->assertAuthenticatedAs($joined);
        $this->assertSame('accepted', $invitation->fresh()->status());
    }

    public function test_a_link_cannot_be_used_twice_or_after_being_revoked(): void
    {
        $this->seedSystem();
        $this->ownerOfNewAccount();

        $this->post('/admin/invitations', ['email' => 'one@example.com', 'role' => Role::VIEWER]);
        $link = session('invitation_link');
        $this->post('/logout');

        $this->post($link, ['name' => 'One', 'password' => 'An0ther!Str0ng-Pass', 'password_confirmation' => 'An0ther!Str0ng-Pass']);
        $this->post('/logout');

        // Second use is refused, and no second account appears.
        $this->get($link)->assertRedirect(route('login'));
        $this->post($link, ['name' => 'Impostor', 'password' => 'An0ther!Str0ng-Pass', 'password_confirmation' => 'An0ther!Str0ng-Pass'])
            ->assertRedirect(route('login'));
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'one@example.com')->count());
    }

    public function test_a_revoked_or_expired_invitation_stops_working(): void
    {
        $this->seedSystem();
        $this->ownerOfNewAccount();

        $this->post('/admin/invitations', ['email' => 'two@example.com', 'role' => Role::VIEWER]);
        $revokedLink = session('invitation_link');
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();
        $this->delete('/admin/invitations/'.$invitation->id);
        $this->assertSame('revoked', $invitation->fresh()->status());

        $this->post('/admin/invitations', ['email' => 'three@example.com', 'role' => Role::VIEWER]);
        $expiredLink = session('invitation_link');
        TeamInvitation::withoutGlobalScopes()->where('email', 'three@example.com')
            ->update(['expires_at' => now()->subDay()]);

        $this->post('/logout');
        $this->get($revokedLink)->assertRedirect(route('login'));
        $this->get($expiredLink)->assertRedirect(route('login'));
        $this->assertSame(0, User::withoutGlobalScopes()->whereIn('email', ['two@example.com', 'three@example.com'])->count());
    }

    public function test_nobody_can_invite_somebody_with_more_rights_than_themselves(): void
    {
        $this->seedSystem();
        $this->ownerOfNewAccount();

        // The owner is account_owner (70); admin (80) and super_admin (100) are above them.
        foreach ([Role::ADMIN, Role::SUPER_ADMIN] as $role) {
            $this->post('/admin/invitations', ['email' => 'climb@example.com', 'role' => $role])
                ->assertSessionHasErrors('role');
        }

        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_inviting_an_address_that_already_has_an_account_is_refused(): void
    {
        $this->seedSystem();
        $this->ownerOfNewAccount('first@example.com');
        $this->post('/logout');
        $this->ownerOfNewAccount('second@example.com');

        $this->post('/admin/invitations', ['email' => 'first@example.com', 'role' => Role::VIEWER])
            ->assertSessionHas('error');

        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_re_inviting_replaces_the_outstanding_link(): void
    {
        $this->seedSystem();
        $this->ownerOfNewAccount();

        $this->post('/admin/invitations', ['email' => 'again@example.com', 'role' => Role::VIEWER]);
        $firstLink = session('invitation_link');
        $this->post('/admin/invitations', ['email' => 'again@example.com', 'role' => Role::OPERATOR]);
        $secondLink = session('invitation_link');

        $this->assertNotSame($firstLink, $secondLink);
        $this->post('/logout');

        // The superseded link must be dead, so a forwarded old mail cannot be used.
        $this->get($firstLink)->assertRedirect(route('login'));
        $this->get($secondLink)->assertOk();
    }

    public function test_one_account_cannot_revoke_another_accounts_invitation(): void
    {
        $this->seedSystem();
        $this->ownerOfNewAccount('first@example.com');
        $this->post('/admin/invitations', ['email' => 'target@example.com', 'role' => Role::VIEWER]);
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();
        $this->post('/logout');

        $this->ownerOfNewAccount('second@example.com');
        $this->delete('/admin/invitations/'.$invitation->id)->assertNotFound();

        $this->assertSame('pending', $invitation->fresh()->status());
    }
}
