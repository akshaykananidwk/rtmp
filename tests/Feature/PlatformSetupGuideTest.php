<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSetupGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_platforms_tab_shows_the_exact_values_each_console_asks_for(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);

        $page = $this->get('/admin/settings?tab=platforms')->assertOk();

        // A wrong redirect URI is the usual reason a connection fails, so each one is
        // printed verbatim next to the steps rather than described.
        foreach (['youtube', 'facebook', 'twitch'] as $platform) {
            $page->assertSee(route('admin.platforms.callback', $platform), false);
        }

        $page->assertSee('YouTube Data API v3');
        $page->assertSee('Valid OAuth Redirect URIs');
        $page->assertSee('dev.twitch.tv', false);
    }

    public function test_it_states_plainly_that_meta_app_review_cannot_be_skipped(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);

        $this->get('/admin/settings?tab=platforms')->assertOk()
            ->assertSee('App Review')
            ->assertSee('pages_manage_posts')
            ->assertSee('nobody can do it for you or skip it');
    }
}
