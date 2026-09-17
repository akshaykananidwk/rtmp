<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Updates\ProtectedPaths;
use App\Models\UpdateProtectedPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtectedPathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_paths_are_protected(): void
    {
        $p = new ProtectedPaths;
        foreach (['.env', '.env.production', 'config.php', 'storage/app/backups/x.zip', 'public/uploads/a.png', 'uploads/b.jpg', 'vendor/autoload.php', 'storage/logs/laravel.log'] as $path) {
            $this->assertTrue($p->isProtected($path), "$path should be protected");
        }
        foreach (['app/Models/User.php', 'routes/web.php', 'VERSION', 'public/index.php', 'resources/views/x.blade.php'] as $path) {
            $this->assertFalse($p->isProtected($path), "$path should NOT be protected");
        }
    }

    public function test_traversal_is_always_protected_and_unsafe_pattern_rejected(): void
    {
        $p = new ProtectedPaths;
        $this->assertTrue($p->isProtected('../etc/passwd'));
        $this->assertTrue($p->isProtected('app/../../x'));
        $this->assertFalse(ProtectedPaths::isSafePattern('../x'));
        $this->assertFalse(ProtectedPaths::isSafePattern('/etc'));
        $this->assertTrue(ProtectedPaths::isSafePattern('public/custom/'));
    }

    public function test_custom_db_pattern_is_honoured(): void
    {
        UpdateProtectedPath::create(['path' => 'public/custom/']);
        $p = new ProtectedPaths;
        $this->assertTrue($p->isProtected('public/custom/theme.css'));
    }
}
