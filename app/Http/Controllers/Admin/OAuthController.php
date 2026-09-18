<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\PlanLimits;
use App\Domain\Audit\AuditLogger;
use App\Domain\Destinations\ConnectorRegistry;
use App\Domain\Destinations\OAuth\AbstractOAuthService;
use App\Domain\Destinations\OAuth\GoogleOAuthService;
use App\Domain\Destinations\OAuth\MetaOAuthService;
use App\Domain\Destinations\OAuth\TwitchOAuthService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\PlatformAccount;
use App\Models\StreamDestination;
use App\Support\SecretMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    private function service(string $platform): AbstractOAuthService
    {
        return match ($platform) {
            'youtube' => app(GoogleOAuthService::class),
            'facebook' => app(MetaOAuthService::class),
            'twitch' => app(TwitchOAuthService::class),
            default => abort(404),
        };
    }

    public function redirect(Request $request, string $platform): RedirectResponse
    {
        $this->authorize('create', StreamDestination::class);
        $service = $this->service($platform);
        if (! $service->isConfigured()) {
            // A tenant admin cannot open Settings, so sending them there would only 403.
            return $request->user()->can('settings.manage')
                ? redirect()->route('admin.settings', ['tab' => 'platforms'])->with('error', ucfirst($platform).' API credentials are not configured. Add them in Settings → Platforms.')
                : redirect()->route('admin.destinations.index')->with('error', ucfirst($platform).' connection is not set up on this server yet. Ask the administrator to add the '.ucfirst($platform).' API credentials, or add the destination with an RTMP key instead.');
        }
        $state = Str::random(40);
        $request->session()->put('oauth:state', $state);
        $request->session()->put('oauth:platform', $platform);

        return redirect()->away($service->authorizationUrl($state, route('admin.platforms.callback', $platform)));
    }

    public function callback(Request $request, string $platform, TenantContext $tenant, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', StreamDestination::class);
        $expected = (string) $request->session()->pull('oauth:state');
        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state')) || $request->session()->pull('oauth:platform') !== $platform) {
            return redirect()->route('admin.destinations.index')->with('error', 'OAuth state mismatch. Please try again.');
        }
        if ($request->query('error')) {
            return redirect()->route('admin.destinations.index')->with('error', 'Authorization denied: '.e((string) $request->query('error_description', $request->query('error'))));
        }
        try {
            $service = $this->service($platform);
            $tokens = $service->exchangeCode((string) $request->query('code'), route('admin.platforms.callback', $platform));
            $account = $service->storeAccount($tenant->get(), $request->user(), $tokens);
            $audit->log('platform.connected', $account, ['platform' => $platform, 'name' => $account->name]);
        } catch (\Throwable $e) {
            return redirect()->route('admin.destinations.index')->with('error', 'Could not connect account: '.SecretMasker::maskString($e->getMessage()));
        }

        // Connecting an account is the point at which someone wants to go live, so the
        // destination is created here rather than left as a second form to find.
        $destination = $this->destinationFor($account, $platform, $request);

        if ($destination === null) {
            return redirect()->route('admin.destinations.create', ['platform' => $platform])
                ->with('status', ucfirst($platform).' account "'.$account->name.'" connected. Create a destination for it to finish.');
        }

        $needsPage = $platform === 'facebook' && ! $destination->option('page_id');

        return redirect()->route($needsPage ? 'admin.destinations.edit' : 'admin.destinations.index', $needsPage ? $destination : [])
            ->with('status', $needsPage
                ? ucfirst($platform).' account "'.$account->name.'" connected. Choose which Page to go live on, then save.'
                : ucfirst($platform).' account "'.$account->name.'" connected and ready — press START LIVE when your stream is running.');
    }

    /**
     * A destination bound to the freshly connected account, reusing one if it already exists.
     *
     * Returns null when the account's allowance is already used up — the operator is told,
     * rather than silently ending up with nothing.
     */
    private function destinationFor(PlatformAccount $account, string $platform, Request $request): ?StreamDestination
    {
        $existing = StreamDestination::where('platform_account_id', $account->id)->first();
        if ($existing) {
            return $existing;
        }

        if (app(PlanLimits::class)->reached(PlanLimits::DESTINATIONS, $request->user())) {
            return null;
        }

        $options = [];
        if ($platform === 'facebook') {
            $pages = $account->meta['pages'] ?? [];
            // With one Page there is nothing to choose, so pick it and let them go live.
            if (count($pages) === 1 && isset($pages[0]['id'])) {
                $options['page_id'] = (string) $pages[0]['id'];
            }
        }

        $destination = new StreamDestination;
        $destination->forceFill([
            'tenant_id' => $account->tenant_id,
            'platform' => $platform,
            'name' => $account->name,
            'account_name' => $account->name,
            'platform_account_id' => $account->id,
            'connection_method' => 'oauth',
            'rtmp_url' => app(ConnectorRegistry::class)->definitions()[$platform]->defaultRtmpUrl,
            'options' => $options,
            'is_enabled' => true,
            'status' => 'idle',
        ]);
        $destination->save();

        return $destination;
    }

    public function disconnect(PlatformAccount $account, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('create', StreamDestination::class);
        $account->tokens()->delete();
        $account->destinations()->update(['platform_account_id' => null, 'connection_method' => 'rtmp', 'status' => 'idle']);
        $account->delete();
        $audit->log('platform.disconnected', $account, ['platform' => $account->platform]);

        return back()->with('status', 'Account disconnected and tokens deleted.');
    }
}
