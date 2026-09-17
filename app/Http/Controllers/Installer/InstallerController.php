<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domain\Installer\InstallerService;
use App\Http\Controllers\Controller;
use App\Support\SecretMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function __construct(private readonly InstallerService $installer) {}

    public function welcome(): View
    {
        return view('installer.welcome', ['step' => 0]);
    }

    public function requirements(): View
    {
        return view('installer.requirements', ['step' => 1, 'result' => $this->installer->requirements()]);
    }

    public function database(Request $request): View
    {
        $db = $request->session()->get('install.db', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => '', 'username' => '']);
        unset($db['password']); // never echo the password

        return view('installer.database', ['step' => 2, 'db' => $db]);
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $data = $this->dbRules($request);

        return response()->json($this->installer->testDatabase($data));
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        $data = $this->dbRules($request);
        $test = $this->installer->testDatabase($data);
        if (! $test['ok']) {
            return back()->withErrors(['database' => $test['message']])->withInput($request->except('password'));
        }
        $request->session()->put('install.db', $data);

        return redirect()->route('install.application');
    }

    public function application(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('install.db')) {
            return redirect()->route('install.database');
        }

        return view('installer.application', ['step' => 3, 'app' => $request->session()->get('install.app', ['name' => 'AK COMPUTER – ONE LIVE EVERYWHERE', 'url' => $request->getSchemeAndHttpHost(), 'timezone' => 'Asia/Kolkata', 'admin_email' => '', 'rtmp_url' => 'rtmp://'.$request->getHost().'/live']), 'timezones' => \DateTimeZone::listIdentifiers()]);
    }

    public function saveApplication(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:255', 'regex:#^https?://#'],
            'timezone' => ['required', 'timezone:all'],
            'admin_email' => ['required', 'email', 'max:190'],
            'rtmp_url' => ['required', 'string', 'max:255', 'regex:#^rtmps?://[A-Za-z0-9.\-]+(?::\d{1,5})?/[A-Za-z0-9_\-]+$#'],
        ]);
        $request->session()->put('install.app', $data);

        return redirect()->route('install.admin');
    }

    public function admin(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('install.app')) {
            return redirect()->route('install.application');
        }

        return view('installer.admin', ['step' => 4, 'email' => $request->session()->get('install.app.admin_email')]);
    }

    public function saveAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ]);
        $request->session()->put('install.admin', $data);

        return redirect()->route('install.run');
    }

    public function run(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('install.admin')) {
            return redirect()->route('install.admin');
        }

        return view('installer.run', ['step' => 5]);
    }

    public function execute(Request $request): RedirectResponse
    {
        $config = ['db' => $request->session()->get('install.db'), 'app' => $request->session()->get('install.app'), 'admin' => $request->session()->get('install.admin')];
        if (! $config['db'] || ! $config['app'] || ! $config['admin']) {
            return redirect()->route('install.welcome')->withErrors(['install' => 'Session expired, please start again.']);
        }
        @set_time_limit(300);
        try {
            $result = $this->installer->install($config);
        } catch (\Throwable $e) {
            return redirect()->route('install.run')->withErrors(['install' => 'Installation failed: '.SecretMasker::maskString($e->getMessage())]);
        }
        $request->session()->forget(['install.db', 'install.app', 'install.admin']);
        $request->session()->put('install.result', $result);

        return redirect()->route('install.complete');
    }

    public function complete(Request $request): View|RedirectResponse
    {
        $result = $request->session()->pull('install.result');
        if (! $result) {
            return redirect('/');
        }

        return view('installer.complete', ['step' => 6, 'result' => $result]);
    }

    private function dbRules(Request $request): array
    {
        return $request->validate([
            'driver' => ['required', 'in:mysql,mariadb,pgsql,sqlite'],
            'host' => ['required_unless:driver,sqlite', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-_]+$/'],
            'port' => ['required_unless:driver,sqlite', 'nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.\-\/]+$/'],
            'username' => ['required_unless:driver,sqlite', 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
