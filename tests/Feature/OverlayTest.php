<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Overlays\OverlayRenderer;
use App\Domain\Streaming\StreamSessionService;
use App\Models\Overlay;
use App\Models\Role;
use App\Models\StreamEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_renderer_builds_a_filter_with_text_ticker_clock_and_box(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $overlay = Overlay::factory()->create(['tenant_id' => $tenant->id]);

        $renderer = app(OverlayRenderer::class);
        $built = $renderer->build($overlay);

        $this->assertNotNull($built);
        $filter = $built['filter'];
        $this->assertStringContainsString('scale=1280:720', $filter);
        $this->assertStringContainsString('drawbox=', $filter);
        $this->assertStringContainsString('drawtext=', $filter);
        $this->assertStringContainsString('reload=1', $filter, 'text must be reloadable so it can change while live');
        $this->assertStringContainsString('localtime', $filter);
        $this->assertStringEndsWith('[vout]', $filter);

        // The words themselves live in files, never in the command line (no escaping bugs)
        $this->assertStringNotContainsString('AK COMPUTER LIVE', $filter);
        $dir = $renderer->textDirectory($overlay);
        $this->assertSame('AK COMPUTER LIVE', trim(File::get($dir.'/el1.txt')));
        $this->assertSame('Breaking news from Dwarka', trim(File::get($dir.'/el2.txt')));

        $args = $renderer->encodeArguments($overlay, $built);
        $this->assertContains('libx264', $args);
        $this->assertContains('[vout]', $args);
        $this->assertContains('2500k', $args);
    }

    public function test_editing_text_while_live_rewrites_the_file_the_encoder_reads(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $overlay = Overlay::factory()->create(['tenant_id' => $tenant->id]);
        $renderer = app(OverlayRenderer::class);
        $renderer->build($overlay);
        $file = $renderer->textDirectory($overlay).'/el1.txt';
        $this->assertSame('AK COMPUTER LIVE', trim(File::get($file)));

        $elements = $overlay->elements;
        $elements[1]['text'] = 'LIVE FROM DWARKA TEMPLE';
        $this->actingAs($user)->put(route('admin.overlays.update', $overlay), [
            'name' => $overlay->name, 'resolution' => $overlay->resolution, 'bitrate_kbps' => $overlay->bitrate_kbps,
            'fps' => $overlay->fps, 'preset' => $overlay->preset, 'elements' => $elements,
        ])->assertRedirect();

        $this->assertSame('LIVE FROM DWARKA TEMPLE', trim(File::get($file)));
    }

    public function test_dangerous_text_cannot_break_out_of_the_filter(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $overlay = Overlay::factory()->create([
            'tenant_id' => $tenant->id,
            'elements' => [['type' => 'text', 'enabled' => true, 'text' => "x':drawbox=x=0:y=0:w=99:h=99:color=red@1,format=gray'", 'position' => 'top_left', 'size' => 30]],
        ]);

        $renderer = app(OverlayRenderer::class);
        $built = $renderer->build($overlay);

        $this->assertStringNotContainsString('drawbox', $built['filter'], 'user text must never reach the filter graph');
        $this->assertStringNotContainsString('format=gray', $built['filter']);
        $this->assertStringContainsString('textfile=', $built['filter']);
    }

    public function test_overlay_crud_and_assignment_to_a_stream_key(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);
        $endpoint = StreamEndpoint::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get(route('admin.overlays.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.overlays.create'))->assertOk()->assertSee('Elements');

        $this->actingAs($user)->post(route('admin.overlays.store'), [
            'name' => 'Temple live', 'resolution' => '1920x1080', 'bitrate_kbps' => 4500, 'fps' => 30, 'preset' => 'veryfast',
            'elements' => [
                ['type' => 'text', 'enabled' => 1, 'text' => 'Dwarkadhish darshan', 'position' => 'bottom_left', 'size' => 44, 'color' => '#ffffff'],
                ['type' => 'clock', 'enabled' => 1, 'format' => 'd-m-Y H:i', 'position' => 'top_right', 'size' => 30, 'color' => '#ffffff'],
            ],
        ])->assertRedirect();

        $overlay = Overlay::first();
        $this->assertSame('Temple live', $overlay->name);
        $this->assertCount(2, $overlay->elements());

        $this->actingAs($user)->post(route('admin.overlays.assign'), ['stream_endpoint_id' => $endpoint->id, 'overlay_id' => $overlay->id])->assertRedirect();
        $this->assertSame($overlay->id, $endpoint->fresh()->overlay_id);

        // A new session inherits the overlay from its stream key
        config(['akstream.streaming.engine' => 'none']);
        $session = app(StreamSessionService::class)->onSourceReady($endpoint->fresh());
        $this->assertSame($overlay->id, $session->overlay_id);

        $this->actingAs($user)->post(route('admin.overlays.assign'), ['stream_endpoint_id' => $endpoint->id, 'overlay_id' => null])->assertRedirect();
        $this->assertNull($endpoint->fresh()->overlay_id);

        $this->actingAs($user)->delete(route('admin.overlays.destroy', $overlay))->assertRedirect();
        $this->assertSoftDeleted('overlays', ['id' => $overlay->id]);
    }

    public function test_logo_upload_is_validated_and_stored_privately(): void
    {
        [$tenant, $user] = $this->adminSetup();
        $this->actAsTenant($tenant);

        $this->actingAs($user)->post(route('admin.overlays.store'), [
            'name' => 'Logo', 'resolution' => '1280x720', 'bitrate_kbps' => 2500, 'fps' => 30, 'preset' => 'veryfast',
            'elements' => [['type' => 'image', 'enabled' => 1, 'position' => 'top_left', 'width' => 180, 'image_file' => UploadedFile::fake()->create('logo.php', 10, 'application/x-php')]],
        ])->assertSessionHasErrors('elements.0.image_file');

        $this->actingAs($user)->post(route('admin.overlays.store'), [
            'name' => 'Logo', 'resolution' => '1280x720', 'bitrate_kbps' => 2500, 'fps' => 30, 'preset' => 'veryfast',
            'elements' => [['type' => 'image', 'enabled' => 1, 'position' => 'top_left', 'width' => 180, 'image_file' => UploadedFile::fake()->image('logo.png', 200, 80)]],
        ])->assertRedirect();

        $stored = Overlay::first()->elements()[0]['image'];
        $this->assertStringStartsWith('overlays/images/', $stored);
        $this->assertFileExists(storage_path('app/private/'.$stored));
        // never reachable from the web
        $this->assertFileDoesNotExist(public_path($stored));
    }

    public function test_viewer_cannot_edit_overlays(): void
    {
        [$tenant, $viewer] = $this->adminSetup(Role::VIEWER);
        $this->actAsTenant($tenant);
        $overlay = Overlay::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($viewer)->get(route('admin.overlays.index'))->assertOk();
        $this->actingAs($viewer)->get(route('admin.overlays.create'))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.overlays.destroy', $overlay))->assertForbidden();
    }

    public function test_overlays_are_tenant_isolated(): void
    {
        $this->seedSystem();
        $a = $this->makeTenant('a');
        $b = $this->makeTenant('b');
        $userB = $this->makeUser($b);
        $overlayA = Overlay::factory()->create(['tenant_id' => $a->id, 'name' => 'Tenant A overlay']);

        $this->actingAs($userB)->get(route('admin.overlays.index'))->assertOk()->assertDontSee('Tenant A overlay');
        $this->actingAs($userB)->get(route('admin.overlays.edit', $overlayA))->assertNotFound();
        $this->actingAs($userB)->delete(route('admin.overlays.destroy', $overlayA))->assertNotFound();
    }
}
