<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Installer\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function __construct(private readonly InstallerService $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installer->isInstalled() && ! app()->runningUnitTests()) {
            return redirect('/install');
        }

        return $next($request);
    }
}
