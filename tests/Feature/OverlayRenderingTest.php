<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Overlays\OverlayRenderer;
use App\Models\Overlay;
use App\Support\BinaryLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Renders the generated filter graph with the real FFmpeg binary and inspects the
 * result, so a broken filter is caught here instead of on air.
 * Skipped automatically when FFmpeg is not installed.
 */
class OverlayRenderingTest extends TestCase
{
    use RefreshDatabase;

    private ?string $ffmpeg = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ffmpeg = BinaryLocator::find('ffmpeg');
        if (! $this->ffmpeg) {
            $this->markTestSkipped('FFmpeg is not installed on this machine.');
        }
    }

    public function test_the_generated_filter_actually_renders_a_frame(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);

        $logo = storage_path('app/private/overlays/images/test-logo.png');
        File::ensureDirectoryExists(dirname($logo));
        (new Process([$this->ffmpeg, '-y', '-f', 'lavfi', '-i', 'color=c=red:s=120x60', '-frames:v', '1', $logo]))->mustRun();

        $overlay = Overlay::factory()->create([
            'tenant_id' => $tenant->id,
            'resolution' => '640x360',
            'elements' => [
                ['type' => 'box', 'enabled' => true, 'position' => 'bottom_left', 'color' => '#000000', 'opacity' => 0.8, 'width' => 'iw', 'height' => 60, 'margin' => 0],
                ['type' => 'text', 'enabled' => true, 'text' => "AK COMPUTER 'LIVE' : Dwarka", 'position' => 'bottom_left', 'size' => 24, 'color' => '#ffffff', 'margin' => 20],
                ['type' => 'ticker', 'enabled' => true, 'text' => 'Scrolling headline, with a comma: and a colon', 'position' => 'bottom_left', 'size' => 16, 'color' => '#22d3ee', 'margin' => 8, 'speed' => 90],
                ['type' => 'clock', 'enabled' => true, 'format' => 'd-m-Y H:i', 'position' => 'top_right', 'size' => 18, 'color' => '#ffffff', 'background' => '#000000', 'margin' => 12],
                ['type' => 'image', 'enabled' => true, 'position' => 'top_left', 'width' => 90, 'opacity' => 0.9, 'image' => 'overlays/images/test-logo.png'],
            ],
        ]);

        $renderer = app(OverlayRenderer::class);
        $built = $renderer->build($overlay);
        $this->assertNotNull($built);

        $out = sys_get_temp_dir().'/ak-overlay-'.uniqid().'.mp4';
        $cmd = array_merge(
            [$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=25:duration=2'],
            $renderer->encodeArguments($overlay, $built),
            ['-t', '2', $out]
        );
        // The synthetic source has no audio track; '0:a?' makes that optional
        $process = new Process($cmd);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue($process->isSuccessful(), "FFmpeg rejected the overlay filter:\n".$process->getErrorOutput()."\n\nFilter:\n".$built['filter']);
        $this->assertFileExists($out);
        $this->assertGreaterThan(5000, filesize($out), 'rendered file is suspiciously small');

        $probe = new Process([BinaryLocator::find('ffprobe') ?? 'ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height,codec_name', '-of', 'csv=p=0', $out]);
        $probe->mustRun();
        $this->assertStringContainsString('h264', $probe->getOutput());
        $this->assertStringContainsString('640,360', str_replace(' ', '', $probe->getOutput()));

        File::delete([$out, $logo]);
    }

    /** Each element must actually put pixels on screen — a filter that "succeeds" but draws nothing is a bug. */
    public function test_every_element_type_draws_visible_pixels(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $renderer = app(OverlayRenderer::class);

        $cases = [
            'box' => [['type' => 'box', 'enabled' => true, 'position' => 'bottom_left', 'color' => '#0b0f1a', 'opacity' => 0.75, 'width' => 'iw', 'height' => 110, 'margin' => 0], 600, 719],
            'text' => [['type' => 'text', 'enabled' => true, 'text' => 'HEADLINE', 'position' => 'bottom_left', 'size' => 46, 'color' => '#ffffff', 'margin' => 40], 600, 719],
            'ticker' => [['type' => 'ticker', 'enabled' => true, 'text' => 'SCROLLING TICKER', 'position' => 'bottom_left', 'size' => 30, 'color' => '#a5f3fc', 'margin' => 12, 'speed' => 120], 600, 719],
            'clock' => [['type' => 'clock', 'enabled' => true, 'format' => 'd-m-Y H:i', 'position' => 'top_right', 'size' => 34, 'color' => '#ffffff', 'margin' => 30], 0, 120],
        ];

        foreach ($cases as $label => [$element, $y0, $y1]) {
            $overlay = Overlay::factory()->create(['tenant_id' => $tenant->id, 'resolution' => '1280x720', 'elements' => [$element]]);
            $built = $renderer->build($overlay);
            $this->assertNotNull($built, $label);

            $out = sys_get_temp_dir().'/ak-ink-'.uniqid().'.png';
            $process = new Process(array_merge(
                [$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=0x808080:s=1280x720:d=4:r=30'],
                ['-filter_complex', $built['filter'], '-map', '[vout]', '-ss', '2', '-frames:v', '1', '-f', 'image2', '-vcodec', 'png', $out]
            ));
            $process->setTimeout(90);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $label.' failed: '.$process->getErrorOutput());

            $this->assertGreaterThan(50, $this->inkCount($out, $y0, $y1), "$label rendered nothing visible");
            File::delete($out);
        }
    }

    /** Pixels differing from the flat grey background. */
    private function inkCount(string $file, int $y0, int $y1): int
    {
        $image = imagecreatefrompng($file);
        $this->assertNotFalse($image);
        $count = 0;
        for ($y = $y0; $y < min($y1, imagesy($image)); $y += 2) {
            for ($x = 0; $x < imagesx($image); $x += 4) {
                if ((imagecolorat($image, $x, $y) & 0xFFFFFF) !== 0x808080) {
                    $count++;
                }
            }
        }
        imagedestroy($image);

        return $count;
    }

    public function test_text_changed_on_disk_appears_without_rebuilding_the_filter(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);

        $overlay = Overlay::factory()->create([
            'tenant_id' => $tenant->id,
            'resolution' => '320x180',
            'elements' => [['type' => 'text', 'enabled' => true, 'text' => 'FIRST', 'position' => 'center', 'size' => 20, 'color' => '#ffffff']],
        ]);

        $renderer = app(OverlayRenderer::class);
        $built = $renderer->build($overlay);
        $file = $renderer->textDirectory($overlay).'/el0.txt';
        $this->assertSame('FIRST', File::get($file));

        // Simulate an admin editing the line while the encoder is running
        $overlay->elements = [['type' => 'text', 'enabled' => true, 'text' => 'SECOND', 'position' => 'center', 'size' => 20, 'color' => '#ffffff']];
        $overlay->save();
        $renderer->syncTextFiles($overlay);

        $this->assertSame('SECOND', File::get($file));
        $this->assertStringContainsString('reload=1', $built['filter'], 'the running encoder re-reads the file, so no restart is needed');
    }

    /** Every position must produce an expression FFmpeg accepts for each element type. */
    public function test_all_positions_render_for_text_box_and_logo(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);

        $logo = storage_path('app/private/overlays/images/pos-logo.png');
        File::ensureDirectoryExists(dirname($logo));
        (new Process([$this->ffmpeg, '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=60x30', '-frames:v', '1', $logo]))->mustRun();

        $renderer = app(OverlayRenderer::class);

        foreach (array_keys(OverlayRenderer::POSITIONS) as $position) {
            $overlay = Overlay::factory()->create([
                'tenant_id' => $tenant->id,
                'resolution' => '320x180',
                'elements' => [
                    ['type' => 'box', 'enabled' => true, 'position' => $position, 'color' => '#111111', 'opacity' => 0.6, 'width' => 120, 'height' => 30, 'margin' => 10],
                    ['type' => 'text', 'enabled' => true, 'text' => 'POS '.$position, 'position' => $position, 'size' => 14, 'color' => '#ffffff', 'margin' => 10],
                    ['type' => 'image', 'enabled' => true, 'position' => $position, 'width' => 40, 'margin' => 10, 'image' => 'overlays/images/pos-logo.png'],
                ],
            ]);

            $built = $renderer->build($overlay);
            $this->assertNotNull($built, $position);

            $out = sys_get_temp_dir().'/ak-pos-'.uniqid().'.mp4';
            $process = new Process(array_merge(
                [$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=10:duration=1'],
                $renderer->encodeArguments($overlay, $built),
                ['-t', '1', $out]
            ));
            $process->setTimeout(60);
            $process->run();

            $this->assertTrue($process->isSuccessful(), "position '$position' failed:\n".$process->getErrorOutput()."\n".$built['filter']);
            File::delete($out);
        }

        File::delete($logo);
    }

    public function test_a_font_is_available_or_text_is_skipped_safely(): void
    {
        [$tenant] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $renderer = app(OverlayRenderer::class);

        if ($renderer->font() === null) {
            $overlay = Overlay::factory()->create(['tenant_id' => $tenant->id, 'elements' => [['type' => 'text', 'enabled' => true, 'text' => 'x', 'position' => 'center']]]);
            $this->assertNull($renderer->build($overlay), 'without a font the overlay must render nothing rather than crash');

            return;
        }

        $this->assertFileExists($renderer->font());
    }
}
