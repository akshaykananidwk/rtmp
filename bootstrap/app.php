<?php

use App\Exceptions\ErrorReporter;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\IpAllowlist;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\VerifyEngineSecret;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(__DIR__.'/../routes/installer.php');
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetTenantContext::class]);
        $middleware->api(append: [SetTenantContext::class]);
        // Tenant context must be resolved (after auth/session) BEFORE route-model binding runs,
        // so tenant-scoped bindings work and foreign-tenant IDs resolve to 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class);
        $middleware->alias([
            'role' => EnsureRole::class,
            'installed' => EnsureInstalled::class,
            'engine.secret' => VerifyEngineSecret::class,
            'ip.allowlist' => IpAllowlist::class,
        ]);
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'internal/engine/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([AuthenticationException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status >= 500 && ! config('app.debug')) {
                $ref = app(ErrorReporter::class)->report($e, $request);

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['message' => 'Something went wrong.', 'reference' => $ref], 500);
                }

                return response()->view('errors.500', ['reference' => $ref], 500);
            }

            return null;
        });
    })->create();
