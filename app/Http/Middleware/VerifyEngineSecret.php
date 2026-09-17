<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates MediaMTX hook calls with a shared secret (header or query). */
class VerifyEngineSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('akstream.streaming.engine_secret');
        $given = (string) ($request->header('X-Engine-Secret') ?: $request->input('secret', ''));

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            abort(401, 'Invalid engine secret');
        }

        return $next($request);
    }
}
