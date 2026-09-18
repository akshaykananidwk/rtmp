<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Overlays\OverlayRenderer;
use App\Http\Controllers\Controller;
use App\Models\Overlay;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OverlayController extends Controller
{
    public function __construct(private readonly OverlayRenderer $renderer, private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('viewAny', Overlay::class);

        return view('admin.overlays.index', [
            'overlays' => Overlay::withCount([])->orderByDesc('is_default')->orderBy('name')->get(),
            'endpoints' => StreamEndpoint::with('overlay')->orderBy('name')->get(),
            'font' => $this->renderer->font(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Overlay::class);

        return $this->form(new Overlay([
            'name' => 'News style',
            'resolution' => '1920x1080',
            'bitrate_kbps' => 4500,
            'fps' => 30,
            'preset' => 'veryfast',
            'elements' => self::sampleElements(),
        ]));
    }

    public function edit(Overlay $overlay): View
    {
        $this->authorize('update', $overlay);

        return $this->form($overlay);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Overlay::class);
        $overlay = DB::transaction(function () use ($request) {
            $overlay = new Overlay;
            $this->fill($overlay, $request);
            $overlay->save();
            $this->applyDefault($overlay);
            $this->audit->log('overlay.created', $overlay, ['name' => $overlay->name]);

            return $overlay;
        });
        $this->renderer->syncTextFiles($overlay);

        return redirect()->route('admin.overlays.edit', $overlay)->with('status', 'Overlay saved. Assign it to a stream key to use it.');
    }

    public function update(Request $request, Overlay $overlay): RedirectResponse
    {
        $this->authorize('update', $overlay);
        DB::transaction(function () use ($request, $overlay): void {
            $this->fill($overlay, $request);
            $overlay->save();
            $this->applyDefault($overlay);
            $this->audit->log('overlay.updated', $overlay);
        });

        // Running streams pick up new text within a second (drawtext reload)
        $this->renderer->syncTextFiles($overlay);

        $live = StreamSession::whereIn('status', ['detected', 'live'])->where('overlay_id', $overlay->id)->exists();

        return back()->with('status', $live
            ? 'Overlay updated. Text changes appear in the live stream within a second; size, position and colour changes apply when the stream restarts.'
            : 'Overlay updated.');
    }

    public function destroy(Overlay $overlay): RedirectResponse
    {
        $this->authorize('delete', $overlay);
        StreamEndpoint::where('overlay_id', $overlay->id)->update(['overlay_id' => null]);
        $overlay->delete();
        $this->audit->log('overlay.deleted', $overlay, ['name' => $overlay->name]);

        return redirect()->route('admin.overlays.index')->with('status', 'Overlay deleted.');
    }

    /** Attach / detach an overlay to a stream key. */
    public function assign(Request $request): RedirectResponse
    {
        $this->authorize('create', Overlay::class);
        $data = $request->validate([
            'stream_endpoint_id' => ['required', 'string', 'exists:stream_endpoints,id'],
            'overlay_id' => ['nullable', 'string', 'exists:overlays,id'],
        ]);

        $endpoint = StreamEndpoint::findOrFail($data['stream_endpoint_id']);
        $this->authorize('update', $endpoint);
        $overlay = $data['overlay_id'] ? Overlay::findOrFail($data['overlay_id']) : null;

        $endpoint->update(['overlay_id' => $overlay?->id]);

        // Apply to a stream that is already running
        $session = StreamSession::where('stream_endpoint_id', $endpoint->id)->whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        $session?->forceFill(['overlay_id' => $overlay?->id, 'branding_status' => null])->save();

        $this->audit->log('overlay.assigned', $endpoint, ['overlay' => $overlay?->name]);

        return back()->with('status', $overlay
            ? '"'.$overlay->name.'" is now used for '.$endpoint->name.($session ? ' – the running stream switches over in a few seconds.' : '.')
            : 'Overlay removed from '.$endpoint->name.'.');
    }

    /** Streams a logo back to the editor so it can be positioned on the canvas. */
    public function image(Request $request, string $path)
    {
        $this->authorize('viewAny', Overlay::class);

        $relative = base64_decode($path, true);
        abort_unless(is_string($relative) && preg_match('#^overlays/images/[A-Za-z0-9]+\.(png|jpe?g|webp)$#', $relative), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($relative), 404);

        // Only images belonging to this tenant's overlays may be read
        $owned = Overlay::get()->contains(fn (Overlay $o) => collect($o->elements())->contains(fn ($el) => ($el['image'] ?? null) === $relative));
        abort_unless($owned, 404);

        return response($disk->get($relative), 200, [
            'Content-Type' => $disk->mimeType($relative) ?: 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function form(Overlay $overlay): View
    {
        return view('admin.overlays.form', [
            'overlay' => $overlay,
            'types' => OverlayRenderer::TYPES,
            'positions' => OverlayRenderer::POSITIONS,
            'font' => $this->renderer->font(),
            'endpoints' => StreamEndpoint::orderBy('name')->get(),
        ]);
    }

    private function applyDefault(Overlay $overlay): void
    {
        if ($overlay->is_default) {
            Overlay::where('id', '!=', $overlay->id)->update(['is_default' => false]);
        }
    }

    private function fill(Overlay $overlay, Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'resolution' => ['required', 'string', 'regex:/^\d{3,4}x\d{3,4}$/'],
            'bitrate_kbps' => ['required', 'integer', 'min:500', 'max:20000'],
            'fps' => ['required', 'integer', 'min:10', 'max:60'],
            'preset' => ['required', 'in:ultrafast,superfast,veryfast,faster,fast,medium'],
            'is_default' => ['nullable', 'boolean'],
            'elements' => ['nullable', 'array', 'max:20'],
            'elements.*.type' => ['required', 'in:'.implode(',', array_keys(OverlayRenderer::TYPES))],
            'elements.*.enabled' => ['nullable', 'boolean'],
            'elements.*.text' => ['nullable', 'string', 'max:500'],
            'elements.*.format' => ['nullable', 'string', 'max:20'],
            'elements.*.position' => ['nullable', 'in:'.implode(',', array_keys(OverlayRenderer::POSITIONS))],
            'elements.*.locked' => ['nullable', 'boolean'],
            'elements.*.size' => ['nullable', 'integer', 'min:8', 'max:200'],
            // geometry written by the visual editor, as a percentage of the frame
            'elements.*.x_pct' => ['nullable', 'numeric', 'min:-20', 'max:110'],
            'elements.*.y_pct' => ['nullable', 'numeric', 'min:-20', 'max:110'],
            'elements.*.w_pct' => ['nullable', 'numeric', 'min:0.5', 'max:200'],
            'elements.*.h_pct' => ['nullable', 'numeric', 'min:0.2', 'max:100'],
            'elements.*.size_pct' => ['nullable', 'numeric', 'min:0.5', 'max:30'],
            'elements.*.color' => ['nullable', 'string', 'max:20'],
            'elements.*.background' => ['nullable', 'string', 'max:20'],
            'elements.*.background_opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'elements.*.opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'elements.*.margin' => ['nullable', 'integer', 'min:0', 'max:500'],
            'elements.*.width' => ['nullable', 'regex:/^(iw|\\d{2,4})$/'],
            'elements.*.height' => ['nullable', 'integer', 'min:2', 'max:1000'],
            'elements.*.speed' => ['nullable', 'integer', 'min:20', 'max:600'],
            'elements.*.image_file' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.config('akstream.overlay.max_image_kb', 2048)],
            'elements.*.image_existing' => ['nullable', 'string', 'max:255'],
        ]);

        $elements = [];
        foreach ($data['elements'] ?? [] as $i => $element) {
            $element['enabled'] = (bool) ($element['enabled'] ?? false);
            $element['locked'] = (bool) ($element['locked'] ?? false);

            if ($element['type'] === 'image') {
                $uploaded = $request->file("elements.$i.image_file");
                if ($uploaded) {
                    if (! in_array($uploaded->getMimeType(), ['image/png', 'image/jpeg', 'image/webp'], true)) {
                        abort(422, 'Invalid image type');
                    }
                    $element['image'] = $uploaded->storeAs('overlays/images', Str::ulid().'.'.$uploaded->guessExtension(), 'local');
                } else {
                    $element['image'] = $element['image_existing'] ?? null;
                }
            }
            unset($element['image_file'], $element['image_existing']);

            $elements[] = $element;
        }

        $overlay->fill([
            'name' => $data['name'],
            'resolution' => $data['resolution'],
            'bitrate_kbps' => (int) $data['bitrate_kbps'],
            'fps' => (int) $data['fps'],
            'preset' => $data['preset'],
            'is_default' => (bool) ($data['is_default'] ?? false),
            'elements' => $elements,
        ]);
    }

    /** A ready-made news-channel layout for new overlays. */
    public static function sampleElements(): array
    {
        return [
            ['type' => 'box', 'enabled' => true, 'x_pct' => 0, 'y_pct' => 80, 'w_pct' => 100, 'h_pct' => 14, 'color' => '#0b0f1a', 'opacity' => 0.75],
            ['type' => 'text', 'enabled' => true, 'text' => 'AK COMPUTER LIVE', 'x_pct' => 4, 'y_pct' => 82, 'size_pct' => 4.5, 'color' => '#ffffff'],
            ['type' => 'ticker', 'enabled' => true, 'text' => 'Welcome to our live broadcast  •  Dwarka, Gujarat  •  Subscribe for more', 'x_pct' => 0, 'y_pct' => 91, 'size_pct' => 2.8, 'color' => '#a5f3fc', 'speed' => 120],
            ['type' => 'clock', 'enabled' => true, 'format' => 'd-m-Y H:i', 'x_pct' => 78, 'y_pct' => 4, 'size_pct' => 3.2, 'color' => '#ffffff', 'background' => '#000000', 'background_opacity' => 0.5],
        ];
    }
}
