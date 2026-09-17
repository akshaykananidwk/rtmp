<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** WORKFLOW A: fresh install through the wizard (SQLite driver so it runs anywhere). */
class InstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ak-install-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/.env', "APP_NAME=Old\n");
        config([
            'akstream.installer.lock_path' => $this->dir.'/installed.lock',
            'akstream.installer.env_path' => $this->dir.'/.env',
            'akstream.installer.sqlite_dir' => $this->dir,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_full_installation_flow(): void
    {
        $this->get('/install')->assertOk()->assertSee('Welcome to the installer');
        $this->get('/install/requirements')->assertOk()->assertSee('PASS');
        $this->get('/install/application')->assertRedirect('/install/database');

        $db = ['driver' => 'sqlite', 'database' => 'install.sqlite'];
        $this->postJson('/install/database/test', $db)->assertOk()->assertJson(['ok' => true]);
        $this->post('/install/database', $db)->assertRedirect('/install/application');

        $this->post('/install/application', ['name' => 'AK Test', 'url' => 'https://stream.test', 'timezone' => 'Asia/Kolkata', 'admin_email' => 'admin@stream.test', 'rtmp_url' => 'rtmp://stream.test/live'])->assertRedirect('/install/admin');
        $this->post('/install/admin', ['name' => 'Akshay', 'email' => 'admin@stream.test', 'password' => 'weak'])->assertSessionHasErrors('password');
        $this->post('/install/admin', ['name' => 'Akshay', 'email' => 'admin@stream.test', 'password' => 'Str0ng!Password#2026', 'password_confirmation' => 'Str0ng!Password#2026'])->assertRedirect('/install/run');
        $this->get('/install/run')->assertOk()->assertSee('Installing');
        $this->post('/install/run')->assertRedirect('/install/complete');
        $this->get('/install/complete')->assertOk()->assertSee('Installation Successful')->assertSee('https://stream.test/admin');

        // Lock + env + database
        $this->assertFileExists($this->dir.'/installed.lock');
        $env = File::get($this->dir.'/.env');
        $this->assertStringContainsString('APP_KEY=base64:', $env);
        $this->assertStringContainsString('APP_NAME="AK Test"', $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('STREAM_ENGINE_SECRET=', $env);
        $this->assertStringNotContainsString('APP_DEBUG=true', $env);

        $user = User::withoutGlobalScopes()->where('email', 'admin@stream.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(Role::SUPER_ADMIN));
        $this->assertSame(4, Role::count());
        $this->assertDatabaseHas('update_protected_paths', ['path' => '.env']);
        $this->assertDatabaseHas('system_settings', ['group' => 'streaming', 'key' => 'rtmp_host']);

        // Reinstall prevention
        $this->get('/install')->assertNotFound();
        $this->post('/install/run')->assertNotFound();

        // Login works with the created admin
        $this->post('/login', ['email' => 'admin@stream.test', 'password' => 'Str0ng!Password#2026'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
    }

    public function test_database_step_validates_input(): void
    {
        $this->post('/install/database', ['driver' => 'mysql', 'host' => 'evil;rm', 'port' => 3306, 'database' => 'x', 'username' => 'u'])->assertSessionHasErrors('host');
        $this->postJson('/install/database/test', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'u', 'password' => 'p'])->assertOk()->assertJson(['ok' => false]);
    }
}
