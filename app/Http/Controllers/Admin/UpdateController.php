<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Domain\Updates\GitHubClient;
use App\Domain\Updates\ProtectedPaths;
use App\Domain\Updates\ReleaseManager;
use App\Domain\Updates\UpdateChecker;
use App\Domain\Updates\UpdateManager;
use App\Http\Controllers\Controller;
use App\Jobs\RunUpdateJob;
use App\Models\Update;
use App\Models\UpdateProtectedPath;
use App\Support\SecretMasker;
use App\Support\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class UpdateController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly GitHubClient $github,
        private readonly UpdateChecker $checker,
        private readonly UpdateManager $manager,
        private readonly AuditLogger $audit,
    ) {}

    public function index(ReleaseManager $releases): View
    {
        $this->authorize('viewAny', Update::class);

        return view('admin.updates.index', [
            'repository' => $this->settings->get('updates', 'repository', config('akstream.updates.repository')),
            'branch' => $this->settings->get('updates', 'branch', config('akstream.updates.branch', 'main')),
            'hasToken' => (bool) $this->github->token(),
            'tokenHint' => SecretMasker::hint($this->github->token()),
            'check' => $this->checker->lastCheck(),
            'inProgress' => $this->manager->inProgress(),
            'locked' => $this->manager->isLocked(),
            'history' => Update::latest()->paginate(15),
            'protected' => UpdateProtectedPath::orderBy('path')->get(),
            'strategy' => $releases->strategy(),
            'currentVersion' => Version::current(),
            'currentCommit' => Version::commit(),
            'missingEnv' => Cache::get('updates.missing_env', []),
        ]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        $data = $request->validate([
            'repository' => ['required', 'string', 'max:200'],
            'branch' => ['required', 'string', 'max:120'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);
        $repo = preg_replace('#^https?://github\.com/#i', '', trim($data['repository']));
        $repo = preg_replace('#\.git$#', '', $repo);
        if (! GitHubClient::isValidRepository($repo)) {
            return back()->withErrors(['repository' => 'Repository must be in the form owner/repo (GitHub only).'])->withInput();
        }
        if (! GitHubClient::isValidBranch($data['branch'])) {
            return back()->withErrors(['branch' => 'Invalid branch name.'])->withInput();
        }

        $this->settings->set('updates', 'repository', $repo);
        $this->settings->set('updates', 'branch', $data['branch']);
        if (! empty($data['token'])) {
            $this->settings->set('updates', 'github_token', $data['token'], true);
        }
        $this->audit->log('settings.github_changed', null, ['repository' => $repo, 'branch' => $data['branch'], 'token_changed' => ! empty($data['token'])]);

        try {
            $access = $this->github->validateAccess();
            Cache::forget(UpdateChecker::CACHE_KEY);

            return back()->with('status', 'GitHub settings saved. Repository access verified'.($access['private'] ? ' (private repo)' : '').'.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Settings saved but repository access failed: '.SecretMasker::maskString($e->getMessage()));
        }
    }

    public function check(): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        try {
            $r = $this->checker->check();
            $this->audit->log('update.checked', null, ['latest' => $r['latest_version'], 'available' => $r['update_available']]);

            return back()->with('status', $r['update_available'] ? 'Update available: '.$r['latest_version'].' ('.substr((string) $r['commit']['sha'], 0, 7).')' : 'You are running the latest version.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Check failed: '.SecretMasker::maskString($e->getMessage()));
        }
    }

    public function install(Request $request): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        $request->validate(['confirm' => ['required', 'in:UPDATE']]);
        try {
            $update = $this->manager->begin($request->user()->id);
        } catch (\Throwable $e) {
            return back()->with('error', SecretMasker::maskString($e->getMessage()));
        }

        RunUpdateJob::dispatch($update->id);

        return redirect()->route('admin.updates.show', $update)->with('status', 'Update started. Do not close this page.');
    }

    public function recover(): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        $r = $this->manager->recover();

        return back()->with('status', $r ? 'Recovery finished: '.$r->status : 'No interrupted update found.');
    }

    public function show(Update $update): View
    {
        $this->authorize('view', $update);

        return view('admin.updates.show', ['update' => $update->load('logs')]);
    }

    public function status(Update $update): JsonResponse
    {
        $this->authorize('view', $update);
        $update->refresh();

        return response()->json([
            'state' => $update->state,
            'status' => $update->status,
            'error' => $update->error,
            'rolled_back' => $update->rolled_back,
            'finished' => in_array($update->state, ['completed', 'failed', 'rolled_back'], true),
            'logs' => $update->logs()->orderBy('id')->get()->map(fn ($l) => ['time' => $l->created_at->format('H:i:s'), 'level' => $l->level, 'step' => $l->step, 'message' => $l->message]),
        ]);
    }

    public function rollback(Request $request, Update $update): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        $request->validate(['confirm' => ['required', 'in:ROLLBACK']]);
        if ($update->state !== 'completed') {
            return back()->with('error', 'Only completed updates can be rolled back manually.');
        }
        $this->manager->rollback($update, 'manual rollback by '.$request->user()->email);

        return redirect()->route('admin.updates.show', $update)->with('status', 'Rollback finished: '.$update->fresh()->status);
    }

    public function addProtectedPath(Request $request, ProtectedPaths $paths): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        $data = $request->validate(['path' => ['required', 'string', 'max:500']]);
        if (! ProtectedPaths::isSafePattern($data['path'])) {
            return back()->withErrors(['path' => 'Invalid path pattern.']);
        }
        UpdateProtectedPath::firstOrCreate(['path' => ProtectedPaths::normalize($data['path'])], ['is_default' => false]);
        $paths->refresh();
        $this->audit->log('settings.protected_path_added', null, ['path' => $data['path']]);

        return back()->with('status', 'Protected path added.');
    }

    public function removeProtectedPath(UpdateProtectedPath $path, ProtectedPaths $paths): RedirectResponse
    {
        $this->authorize('manage', Update::class);
        if ($path->is_default) {
            return back()->with('error', 'Default protected paths cannot be removed.');
        }
        $path->delete();
        $paths->refresh();
        $this->audit->log('settings.protected_path_removed', null, ['path' => $path->path]);

        return back()->with('status', 'Protected path removed.');
    }
}
