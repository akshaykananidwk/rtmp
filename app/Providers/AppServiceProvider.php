<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Backups\BackupService;
use App\Domain\Destinations\ConnectorRegistry;
use App\Domain\Settings\SettingsService;
use App\Domain\Streaming\Engines\MediaMtxEngine;
use App\Domain\Streaming\Engines\NullEngine;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use App\Domain\Streaming\Relay\RelaySupervisor;
use App\Domain\Streaming\StreamLogger;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Updates\ProtectedPaths;
use App\Domain\Updates\ReleaseManager;
use App\Listeners\StreamEventSubscriber;
use App\Models\Backup;
use App\Models\Overlay;
use App\Models\Recording;
use App\Models\ScheduledStream;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use App\Models\StreamSession;
use App\Models\Update;
use App\Models\User;
use App\Policies\BackupPolicy;
use App\Policies\OverlayPolicy;
use App\Policies\RecordingPolicy;
use App\Policies\ScheduledStreamPolicy;
use App\Policies\StreamDestinationPolicy;
use App\Policies\StreamEndpointPolicy;
use App\Policies\StreamSessionPolicy;
use App\Policies\UpdatePolicy;
use App\Policies\UserPolicy;
use App\Support\Version;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->useDatabaseFreeDriversBeforeInstall();

        $this->app->singleton(TenantContext::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(ProtectedPaths::class);
        $this->app->singleton(ConnectorRegistry::class);
        $this->app->singleton(ReleaseManager::class);
        $this->app->singleton(BackupService::class);

        $this->app->singleton(StreamEngineInterface::class, function () {
            return match ((string) config('akstream.streaming.engine', 'mediamtx')) {
                'none' => new NullEngine,
                default => MediaMtxEngine::fromConfig(),
            };
        });

        $this->app->singleton(RelaySupervisor::class, fn ($app) => new RelaySupervisor(
            $app->make(StreamEngineInterface::class),
            $app->make(StreamLogger::class),
            $app->make(ConnectorRegistry::class),
            (string) config('akstream.streaming.node_id', 'media-1'),
        ));
    }

    /**
     * Until the installer has run there is no database, so session/cache/queue must not
     * depend on one — otherwise /install itself fails with a connection error.
     */
    private function useDatabaseFreeDriversBeforeInstall(): void
    {
        if ($this->app->runningUnitTests() || file_exists(storage_path('app/installed.lock'))) {
            return;
        }

        $config = $this->app['config'];

        if ($config->get('session.driver') === 'database') {
            $config->set('session.driver', 'file');
        }
        if ($config->get('cache.default') === 'database') {
            $config->set('cache.default', 'file');
        }
        if ($config->get('queue.default') === 'database') {
            $config->set('queue.default', 'sync');
        }
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(false);

        Password::defaults(fn () => Password::min((int) config('akstream.security.password_min_length', 10))->letters()->mixedCase()->numbers()->symbols()->uncompromised());

        Gate::policy(StreamEndpoint::class, StreamEndpointPolicy::class);
        Gate::policy(StreamDestination::class, StreamDestinationPolicy::class);
        Gate::policy(StreamSession::class, StreamSessionPolicy::class);
        Gate::policy(ScheduledStream::class, ScheduledStreamPolicy::class);
        Gate::policy(Recording::class, RecordingPolicy::class);
        Gate::policy(Overlay::class, OverlayPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Backup::class, BackupPolicy::class);
        Gate::policy(Update::class, UpdatePolicy::class);

        // Permission-based gates: Gate::allows('settings.manage')
        Gate::before(function (User $user, string $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability) ?: null;
            }

            return null;
        });

        Event::subscribe(StreamEventSubscriber::class);

        RateLimiter::for('login', fn (Request $r) => [Limit::perMinutes(15, 5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perMinute(20)->by($r->ip())]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinutes(15, 5)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('api-control', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
        RateLimiter::for('engine', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
        RateLimiter::for('installer', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('status', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->getAuthIdentifier() ?: $r->ip()));

        View::composer('*', function ($view): void {
            $view->with('appVersion', Version::current());
            $view->with('brand', config('akstream.brand'));
        });
    }
}
