<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Overlays\OverlayRenderer;
use Tests\TestCase;

class OverlayFontTest extends TestCase
{
    public function test_a_font_ships_with_the_application(): void
    {
        $bundled = resource_path('fonts/overlay.ttf');

        $this->assertFileExists($bundled, 'overlays must work without relying on system fonts');
        $this->assertGreaterThan(50_000, filesize($bundled));
        $this->assertSame("\x00\x01\x00\x00", file_get_contents($bundled, false, null, 0, 4), 'not a TrueType file');
        $this->assertFileExists(resource_path('fonts/LICENSE.txt'));
    }

    public function test_font_detection_prefers_the_bundled_file(): void
    {
        config(['akstream.overlay.font' => '']);

        $this->assertSame(resource_path('fonts/overlay.ttf'), (new OverlayRenderer)->font());
    }

    public function test_probing_an_unreadable_path_never_throws(): void
    {
        // open_basedir makes is_file() throw; the helper must swallow that
        $this->assertFalse(OverlayRenderer::readable('/proc/1/root/etc/shadow'));
        $this->assertFalse(OverlayRenderer::readable('/definitely/not/here.ttf'));
        $this->assertTrue(OverlayRenderer::readable(resource_path('fonts/overlay.ttf')));
    }

    public function test_a_configured_font_wins_when_readable(): void
    {
        config(['akstream.overlay.font' => resource_path('fonts/overlay-regular.ttf')]);
        $this->assertSame(resource_path('fonts/overlay-regular.ttf'), (new OverlayRenderer)->font());

        config(['akstream.overlay.font' => '/nope/missing.ttf']);
        $this->assertSame(resource_path('fonts/overlay.ttf'), (new OverlayRenderer)->font());
    }
}
