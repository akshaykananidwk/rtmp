<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Streaming\StreamKeyService;
use App\Domain\Streaming\StreamSessionService;
use App\Http\Controllers\Controller;
use App\Models\StreamEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Hooks called by MediaMTX:
 *   authHTTP        → POST /internal/engine/auth      (publish/read authorization)
 *   runOnReady      → POST /internal/engine/ready     (source started)
 *   runOnNotReady   → POST /internal/engine/not-ready (source stopped)
 */
class EngineHookController extends Controller
{
    public function __construct(private readonly StreamKeyService $keys, private readonly StreamSessionService $sessions) {}

    public function auth(Request $request): JsonResponse
    {
        $action = (string) $request->input('action', '');
        $path = (string) $request->input('path', '');

        // Reads (HLS preview, ffmpeg relays) are only allowed from local/internal callers
        if ($action === 'read') {
            $ip = (string) $request->input('ip', $request->ip());
            $allowed = in_array($ip, ['127.0.0.1', '::1'], true) || str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip);

            return $allowed ? response()->json(['ok' => true]) : response()->json(['ok' => false], 401);
        }

        if ($action !== 'publish' || ! str_starts_with($path, 'live/')) {
            return response()->json(['ok' => false], 401);
        }

        $key = substr($path, 5);
        $endpoint = $this->keys->findByKey($key);
        if (! $endpoint || ! $endpoint->isUsable()) {
            Log::channel('streaming')->warning('Publish rejected for unknown/disabled key', ['ip' => $request->input('ip')]);

            return response()->json(['ok' => false], 401);
        }

        return response()->json(['ok' => true]);
    }

    public function ready(Request $request): JsonResponse
    {
        $endpoint = $this->resolve((string) $request->input('path', ''));
        if (! $endpoint) {
            return response()->json(['ok' => false], 404);
        }
        $session = $this->sessions->onSourceReady($endpoint, $request->input('node'));

        return response()->json(['ok' => true, 'session' => $session->id]);
    }

    public function notReady(Request $request): JsonResponse
    {
        $endpoint = $this->resolve((string) $request->input('path', ''));
        if (! $endpoint) {
            return response()->json(['ok' => false], 404);
        }
        $this->sessions->onSourceEnded($endpoint);

        return response()->json(['ok' => true]);
    }

    private function resolve(string $path): ?StreamEndpoint
    {
        if (! str_starts_with($path, 'live/')) {
            return null;
        }

        return $this->keys->findByKey(substr($path, 5));
    }
}
