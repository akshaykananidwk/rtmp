<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
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
            return redirect()->route('admin.settings', ['tab' => 'platforms'])->with('error', ucfirst($platform).' API credentials are not configured. Add them in Settings → Platforms.');
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

        return redirect()->route('admin.destinations.create', ['platform' => $platform])->with('status', ucfirst($platform).' account "'.$account->name.'" connected. Now create a destination for it.');
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
