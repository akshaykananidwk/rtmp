<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Preflight;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PreflightTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/ak-preflight-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/.env.example', "APP_NAME=Test\nAPP_KEY=\nAPP_ENV=production\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_creates_env_with_app_key_on_a_fresh_upload(): void
    {
        $this->assertFileDoesNotExist($this->dir.'/.env');

        Preflight::run($this->dir);

        $this->assertFileExists($this->dir.'/.env');
        $env = File::get($this->dir.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{40,}$/m', $env);
        $this->assertStringContainsString('APP_NAME=Test', $env);
        $this->assertDirectoryExists($this->dir.'/storage/framework/sessions');
        $this->assertDirectoryExists($this->dir.'/bootstrap/cache');
    }

    public function test_never_overwrites_an_existing_env_or_key(): void
    {
        File::put($this->dir.'/.env', "APP_KEY=base64:EXISTINGKEYVALUE=\nCUSTOM=keep-me\n");

        Preflight::run($this->dir);

        $env = File::get($this->dir.'/.env');
        $this->assertStringContainsString('APP_KEY=base64:EXISTINGKEYVALUE=', $env);
        $this->assertStringContainsString('CUSTOM=keep-me', $env);
    }

    public function test_fills_in_only_a_missing_key(): void
    {
        File::put($this->dir.'/.env', "APP_KEY=\nCUSTOM=keep-me\n");

        Preflight::run($this->dir);

        $env = File::get($this->dir.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:.+$/m', $env);
        $this->assertStringContainsString('CUSTOM=keep-me', $env);
        $this->assertSame(1, substr_count($env, 'APP_KEY='));
    }

    public function test_does_nothing_once_installed(): void
    {
        File::ensureDirectoryExists($this->dir.'/storage/app');
        File::put($this->dir.'/storage/app/installed.lock', '{}');

        Preflight::run($this->dir);

        $this->assertFileDoesNotExist($this->dir.'/.env');
    }
}
