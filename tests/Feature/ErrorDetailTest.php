<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorDetailTest extends TestCase
{
    use RefreshDatabase;

    private string $lock;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->lock = storage_path('app/installed.lock');
        File::ensureDirectoryExists(dirname($this->lock));
        File::put($this->lock, '{"installed":true}');
        Route::get('/__boom', fn () => throw new \RuntimeException('SQLSTATE[HY000]: no such table: overlays (password: hunter2)'))->middleware('web');
    }

    protected function tearDown(): void
    {
        File::delete($this->lock);
        parent::tearDown();
    }

    public function test_super_admin_sees_the_reason_and_the_migration_hint(): void
    {
        [$tenant, $admin] = $this->adminSetup(Role::SUPER_ADMIN);

        $response = $this->actingAs($admin)->get('/__boom');

        $response->assertStatus(500)
            ->assertSee('no such table: overlays')
            ->assertSee('php artisan migrate --force')
            ->assertSee('Reference ID');

        // secrets are still masked for everyone
        $response->assertDontSee('hunter2');
    }

    public function test_other_users_only_see_the_reference(): void
    {
        [$tenant, $operator] = $this->adminSetup(Role::OPERATOR);

        $this->actingAs($operator)->get('/__boom')
            ->assertStatus(500)
            ->assertSee('Reference ID')
            ->assertDontSee('no such table')
            ->assertDontSee('RuntimeException');

        $this->get('/__boom')->assertStatus(500)->assertDontSee('no such table');
    }
}
