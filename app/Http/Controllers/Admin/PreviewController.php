<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Http\Controllers\Controller;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated HLS proxy so operators can watch what is actually going out.
 *
 * The media server only accepts playback from the local network, so the browser
 * never talks to it directly: every playlist and segment is fetched server-side
 * for a user who is allowed to see that stream, and playlist URLs are rewritten
 * to point back here. The stream key never reaches the browser.
 */
class PreviewController extends Controller
{
    public function __construct(private readonly StreamEngineInterface $engine) {}

    /** Which stream (raw ingest or branded output) the preview should show. */
    public function playlist(Request $request, StreamEndpoint $endpoint): Response
    {
        $this->authorize('view', $endpoint);

        $path = $this->pathFor($endpoint, $request->query('source') === 'raw');
        $url = $this->hlsBase().'/'.$path.'/index.m3u8';

        $response = $this->fetch($url);
        if ($response === null) {
            return response('Stream not available', 404)->header('Cache-Control', 'no-store');
        }

        // Rewrite segment names to our proxy so the browser never needs engine access
        $body = preg_replace_callback('/^(?!#)(\S+)$/m', function (array $m) use ($endpoint, $request) {
            return route('admin.preview.segment', ['endpoint' => $endpoint->id, 'file' => $m[1]])
                .($request->query('source') === 'raw' ? '?source=raw' : '');
        }, $response) ?? $response;

        return response($body, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function segment(Request $request, StreamEndpoint $endpoint, string $file): Response|StreamedResponse
    {
        $this->authorize('view', $endpoint);

        if (! preg_match('/^[A-Za-z0-9._-]{1,120}$/', $file) || str_contains($file, '..')) {
            abort(404);
        }

        $path = $this->pathFor($endpoint, $request->query('source') === 'raw');
        $url = $this->hlsBase().'/'.$path.'/'.$file;

        try {
            $upstream = Http::timeout(15)->withOptions(['stream' => true])->get($url);
        } catch (\Throwable) {
            abort(504, 'Preview unavailable');
        }

        if (! $upstream->successful()) {
            abort($upstream->status() === 404 ? 404 : 502);
        }

        $body = $upstream->toPsrResponse()->getBody();
        $type = str_ends_with($file, '.m3u8') ? 'application/vnd.apple.mpegurl' : ($upstream->header('Content-Type') ?: 'video/mp2t');

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(65536);
                flush();
            }
        }, 200, ['Content-Type' => $type, 'Cache-Control' => 'no-store']);
    }

    /** Small JSON used by the player to know whether anything is publishing. */
    public function status(Request $request, StreamEndpoint $endpoint)
    {
        $this->authorize('view', $endpoint);

        $session = StreamSession::where('stream_endpoint_id', $endpoint->id)->whereIn('status', ['detected', 'live'])->latest('started_at')->first();
        $branded = $session?->branding_status === 'live';

        return response()->json([
            'live' => $session !== null,
            'branded' => $branded,
            'overlay' => $session?->overlay?->name,
            'resolution' => $session?->resolution,
            'fps' => $session?->fps,
            'playlist' => $session ? route('admin.preview.playlist', $endpoint) : null,
            'raw_playlist' => $branded ? route('admin.preview.playlist', ['endpoint' => $endpoint->id, 'source' => 'raw']) : null,
        ])->header('Cache-Control', 'no-store');
    }

    private function pathFor(StreamEndpoint $endpoint, bool $forceRaw): string
    {
        if (! $forceRaw) {
            $session = StreamSession::where('stream_endpoint_id', $endpoint->id)->whereIn('status', ['detected', 'live'])->latest('started_at')->first();
            if ($session?->branding_status === 'live') {
                return 'branded/'.$endpoint->plainKey();
            }
        }

        return 'live/'.$endpoint->plainKey();
    }

    private function hlsBase(): string
    {
        return rtrim((string) config('akstream.streaming.hls_url', 'http://127.0.0.1:8888'), '/');
    }

    private function fetch(string $url): ?string
    {
        try {
            $res = Http::timeout(8)->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $res->successful() ? $res->body() : null;
    }
}
