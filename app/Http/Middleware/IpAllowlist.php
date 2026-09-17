<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Optional admin IP restriction (comma separated IPs / CIDRs in Security Settings). */
class IpAllowlist
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $list = trim((string) $this->settings->get('security', 'ip_allowlist', ''));
        if ($list === '') {
            return $next($request);
        }
        $ip = (string) $request->ip();
        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if ($this->matches($ip, $entry)) {
                return $next($request);
            }
        }
        Log::warning('Admin access blocked by IP allowlist', ['ip' => $ip]);
        abort(403, 'Access from your IP address is not allowed.');
    }

    private function matches(string $ip, string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return $ip === $entry;
        }
        [$subnet, $bits] = explode('/', $entry, 2);
        $ipBin = inet_pton($ip);
        $subBin = inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subBin[$bytes]) & $mask);
    }
}
