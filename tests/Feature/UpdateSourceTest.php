<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Settings\SettingsService;
use App\Domain\Updates\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The in-panel updater is useless until it knows where this copy came from: the
 * repository setting ships empty and the branch defaults to "main".
 */
class UpdateSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_has_no_update_source_until_it_is_set(): void
    {
        $this->seedSystem();

        $this->assertFalse(app(GitHubClient::class)->isConfigured(), 'nothing to check against out of the box');

        $this->artisan('updates:source', ['repository' => 'akshaykananidwk/rtmp', 'branch' => 'claude/ak-computer-streaming-saas-868j8s'])
            ->assertSuccessful();

        $settings = app(SettingsService::class);
        $this->assertSame('akshaykananidwk/rtmp', $settings->get('updates', 'repository'));
        $this->assertSame('claude/ak-computer-streaming-saas-868j8s', $settings->get('updates', 'branch'));
        $this->assertTrue(app(GitHubClient::class)->isConfigured());
    }

    public function test_it_never_quietly_replaces_what_the_operator_chose(): void
    {
        $this->seedSystem();
        $settings = app(SettingsService::class);
        $settings->set('updates', 'repository', 'someone/their-fork');
        $settings->set('updates', 'branch', 'production');

        $this->artisan('updates:source', ['repository' => 'akshaykananidwk/rtmp', 'branch' => 'main'])->assertSuccessful();

        $this->assertSame('someone/their-fork', $settings->get('updates', 'repository'));
        $this->assertSame('production', $settings->get('updates', 'branch'));

        // Only an explicit --force moves it.
        $this->artisan('updates:source', ['repository' => 'akshaykananidwk/rtmp', 'branch' => 'main', '--force' => true])->assertSuccessful();
        $this->assertSame('akshaykananidwk/rtmp', $settings->get('updates', 'repository'));
        $this->assertSame('main', $settings->get('updates', 'branch'));
    }

    public function test_a_nonsense_source_is_refused(): void
    {
        $this->seedSystem();

        $this->artisan('updates:source', ['repository' => 'https://evil.example.com/x'])->assertFailed();
        $this->artisan('updates:source', ['repository' => 'owner/repo', 'branch' => '../escape'])->assertFailed();

        $this->assertFalse(app(GitHubClient::class)->isConfigured());
    }

    public function test_the_updates_page_says_what_is_missing_and_works_once_set(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actingAs($user);

        $this->get('/admin/updates')->assertOk()->assertSee('Configure the repository first.');

        $this->artisan('updates:source', ['repository' => 'akshaykananidwk/rtmp', 'branch' => 'claude/ak-computer-streaming-saas-868j8s']);

        $this->get('/admin/updates')->assertOk()
            ->assertSee('akshaykananidwk/rtmp')
            ->assertSee('claude/ak-computer-streaming-saas-868j8s')
            ->assertDontSee('Configure the repository first.');
    }
}
