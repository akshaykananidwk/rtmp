<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Installer is only reachable before installation; afterwards it is locked. */
class EnsureNotInstalled
{
    public function __construct(private readonly InstallerService $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->isInstalled()) {
            abort(404);
        }

        return $next($request);
    }
}
