<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupService;
use App\Domain\Health\HealthService;
use App\Events\SystemAlert;
use App\Models\Update;
use App\Models\UpdateLog;
use App\Support\SecretMasker;
use App\Support\Version;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates a full update transaction:
 *   CHECKING → BACKING_UP → DOWNLOADING → VERIFYING → INSTALLING → MIGRATING → HEALTH_CHECK → ACTIVATING → COMPLETED
 * Any failure after INSTALLING triggers ROLLING_BACK → ROLLED_BACK.
 */
class UpdateManager
{
    public const LOCK_NAME = 'system-update';

    public function __construct(
        private readonly GitHubClient $github,
        private readonly UpdateChecker $checker,
        private readonly ReleaseManager $releases,
        private readonly BackupService $backups,
        private readonly HealthService $health,
        private readonly AuditLogger $audit,
        private readonly ProtectedPaths $protected,
    ) {}

    // ------------------------------------------------------------------ locking

    public function lockFile(): string
    {
        return storage_path('framework/update.lock');
    }

    public function isLocked(): bool
    {
        if (File::exists($this->lockFile())) {
            return true;
        }
        $probe = Cache::lock(self::LOCK_NAME, 1);
        if ($probe->get()) {
            $probe->release();

            return false;
        }

        return true;
    }

    public function currentLock(): ?array
    {
        if (! File::exists($this->lockFile())) {
            return null;
        }

        return json_decode((string) File::get($this->lockFile()), true) ?: null;
    }

    private function acquireLock(Update $update): Lock
    {
        $lock = Cache::lock(self::LOCK_NAME, 7200);
        if (! $lock->get()) {
            throw new \RuntimeException('Another update is already running.');
        }
        if (File::exists($this->lockFile())) {
            $lock->release();
            throw new \RuntimeException('Update lock file exists; another update is running or was interrupted. Run recovery first.');
        }
        File::put($this->lockFile(), json_encode(['update_id' => $update->id, 'pid' => getmypid(), 'started_at' => now()->toIso8601String()]));

        return $lock;
    }

    private function releaseLock(?Lock $lock): void
    {
        File::delete($this->lockFile());
        try {
            $lock?->release();
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------ public API

    public function inProgress(): ?Update
    {
        return Update::whereIn('state', UpdateState::IN_PROGRESS)->latest()->first();
    }

    /** Create the update record (state CHECKING) and lock. Execution happens in run(). */
    public function begin(?string $userId = null, ?string $targetSha = null): Update
    {
        if ($this->inProgress()) {
            throw new \RuntimeException('An update is already in progress.');
        }
        if (! $this->github->isConfigured()) {
            throw new \RuntimeException('GitHub repository is not configured.');
        }

        $info = $this->checker->lastCheck() ?? $this->checker->check();
        $sha = $targetSha ?: ($info['commit']['sha'] ?? null);
        if (! $sha) {
            throw new \RuntimeException('Could not determine the commit to install.');
        }
        GitHubClient::assertSha($sha);

        $update = Update::create([
            'state' => UpdateState::CHECKING,
            'status' => UpdateState::label(UpdateState::CHECKING),
            'previous_version' => Version::current(),
            'previous_commit' => Version::commit(),
            'version' => $info['latest_version'] ?? null,
            'commit_sha' => $sha,
            'commit_message' => Str::limit((string) ($info['commit']['message'] ?? ''), 1000),
            'commit_author' => $info['commit']['author'] ?? null,
            'commit_date' => $info['commit']['date'] ?? null,
            'repository' => $this->github->repositoryName(),
            'branch' => $this->github->branch(),
            'changed_files' => $info['changed_files'] ?? 0,
            'added_files' => $info['counts']['added'] ?? 0,
            'modified_files' => $info['counts']['modified'] ?? 0,
            'deleted_files' => $info['counts']['deleted'] ?? 0,
            'file_changes' => $info['changes'] ?? [],
            'release_notes' => $info['release'] ?? null,
            'previous_release_path' => $this->releases->currentReleasePath(),
            'started_by' => $userId,
            'started_at' => now(),
        ]);

        $this->log($update, 'info', 'checking', 'Update requested: '.substr($sha, 0, 7).' ('.($update->version ?? 'unknown version').') by '.($userId ?? 'system'));
        $this->audit->log('update.started', $update, ['sha' => $sha, 'version' => $update->version], 'success', $userId);

        return $update;
    }

    /** Execute the whole pipeline. Never throws; the Update row carries the outcome. */
    public function run(Update $update): Update
    {
        $lock = null;
        $tarball = null;
        $maintenance = false;
        $secret = null;

        try {
            $lock = $this->acquireLock($update);
            $start = microtime(true);

            // STEP 1 – Maintenance mode (secret bypass for admins)
            $secret = Str::random(32);
            $this->maintenance(true, $secret);
            $maintenance = true;
            $this->log($update, 'info', 'checking', 'Maintenance mode enabled. Admin bypass: '.rtrim((string) config('app.url'), '/').'/'.$secret);

            // STEP 2 – Backup (mandatory)
            $this->transition($update, UpdateState::BACKING_UP);
            $backup = $this->backups->create('full', 'update', $update->started_by);
            if (! $backup->isUsable()) {
                throw new \RuntimeException('Backup could not be verified; update aborted.');
            }
            $update->forceFill(['backup_id' => $backup->id, 'backup_ok' => true])->save();
            $this->log($update, 'info', 'backing_up', 'Backup '.$backup->id.' verified ('.number_format($backup->size_bytes / 1048576, 1).' MB, sha256 '.substr((string) $backup->checksum, 0, 12).'…)');

            // STEP 3 – Download
            $this->transition($update, UpdateState::DOWNLOADING);
            $tarball = $this->releases->releasesDir().'/download-'.$update->id.'.tar.gz';
            $size = $this->github->downloadTarball($update->commit_sha, $tarball);
            $update->forceFill(['download_size' => $size])->save();
            $this->log($update, 'info', 'downloading', 'Downloaded '.number_format($size / 1048576, 2).' MB');

            // STEP 4 – Verify
            $this->transition($update, UpdateState::VERIFYING);
            $v = $this->releases->verifyArchive($tarball, $update->commit_sha);
            $update->forceFill(['archive_checksum' => $v['checksum']])->save();
            $this->log($update, 'info', 'verifying', 'Archive OK: '.$v['entries'].' entries, sha256 '.substr($v['checksum'], 0, 12).'…, matches commit '.substr($update->commit_sha, 0, 7));

            // STEP 5 – Install (stage + dependency check + activate files)
            $this->transition($update, UpdateState::INSTALLING);
            $release = $this->releases->stage($update, $tarball, $update->commit_sha);
            $update->forceFill(['release_path' => $release])->save();
            $this->log($update, 'info', 'installing', 'Staged release at '.basename($release).' (strategy: '.$this->releases->strategy().', '.count($this->protected->all()).' protected patterns)');
            foreach ($this->releases->dependencyCheck($update, $release) as $w) {
                $this->log($update, 'warning', 'installing', $w);
            }

            $codeRoot = $release;
            if ($this->releases->strategy() === 'inplace') {
                $result = $this->releases->activate($update, $release);
                $codeRoot = $this->releases->appRoot();
                $this->log($update, 'info', 'installing', 'Files synced in place: '.$result['written'].' written, '.$result['skipped_protected'].' protected skipped, '.count($result['deleted']).' deleted');
            }
            $this->checkEnvCompatibility($update, $codeRoot);

            // STEP 6 – Migrate
            $this->transition($update, UpdateState::MIGRATING);
            $update->forceFill(['migration_ran' => true])->save();
            [$code, $out] = $this->releases->artisan($codeRoot, ['migrate', '--force', '--path='.$codeRoot.'/database/migrations', '--realpath']);
            if ($code !== 0) {
                $update->forceFill(['migration_ok' => false])->save();
                throw new \RuntimeException('Migration failed: '.SecretMasker::maskString(mb_substr(trim($out), -1500)));
            }
            $update->forceFill(['migration_ok' => true])->save();
            $this->log($update, 'info', 'migrating', 'Migrations OK'.(trim($out) ? ': '.Str::limit(trim(preg_replace('/\s+/', ' ', $out)), 300) : ''));

            // STEP 7 – Cache clear + health check on the new code
            $this->transition($update, UpdateState::HEALTH_CHECK);
            $this->clearCaches($codeRoot, $update);
            $report = $this->runHealth($codeRoot);
            $update->forceFill(['health_ok' => $report['critical_ok']])->save();
            $this->log($update, $report['critical_ok'] ? 'info' : 'error', 'health_check', "Health check:\n".$report['text']);
            if (! $report['critical_ok']) {
                throw new \RuntimeException('Post-update health check failed');
            }

            // STEP 8 – Activate (symlink switch) and final HTTP check
            $this->transition($update, UpdateState::ACTIVATING);
            if ($this->releases->strategy() === 'symlink') {
                $this->releases->activate($update, $release);
                $this->clearCaches($this->releases->appRoot(), $update);
                $this->log($update, 'info', 'activating', 'Symlink switched to '.basename($release));
            }
            $this->maintenance(false);
            $maintenance = false;
            $this->log($update, 'info', 'activating', 'Maintenance mode disabled');

            $update->forceFill([
                'state' => UpdateState::COMPLETED,
                'status' => UpdateState::label(UpdateState::COMPLETED),
                'version' => $this->readVersion($this->releases->appRoot()) ?? $update->version,
                'completed_at' => now(),
                'duration_seconds' => (int) (microtime(true) - $start),
            ])->save();
            $this->log($update, 'info', 'completed', 'Update completed: '.$update->previous_version.' → '.$update->version.' in '.$update->duration_seconds.'s');
            $this->audit->log('update.completed', $update, ['version' => $update->version], 'success', $update->started_by);
            event(new SystemAlert('update.completed', 'Update completed', 'Application updated to version '.$update->version.' ('.substr($update->commit_sha, 0, 7).').', 'info'));
            $this->releases->pruneReleases(3);
        } catch (\Throwable $e) {
            $this->handleFailure($update, $e, $maintenance);
        } finally {
            if ($tarball && File::exists($tarball)) {
                File::delete($tarball);
            }
            $this->releaseLock($lock);
            Cache::forget(UpdateChecker::CACHE_KEY);
        }

        return $update->fresh();
    }

    // ------------------------------------------------------------------ failure / rollback

    private function handleFailure(Update $update, \Throwable $e, bool $maintenance): void
    {
        $msg = SecretMasker::maskString($e->getMessage());
        Log::error('Update failed: '.$msg);
        $this->log($update, 'error', $update->state, 'FAILED: '.$msg);
        $update->forceFill(['error' => $msg])->save();

        $needsRollback = in_array($update->state, [UpdateState::INSTALLING, UpdateState::MIGRATING, UpdateState::HEALTH_CHECK, UpdateState::ACTIVATING], true);

        if ($needsRollback) {
            $this->rollback($update, $msg);
        } else {
            $update->forceFill(['state' => UpdateState::FAILED, 'status' => UpdateState::label(UpdateState::FAILED), 'completed_at' => now()])->save();
            event(new SystemAlert('update.failed', 'Update failed', $msg, 'critical'));
        }

        if ($maintenance) {
            $this->maintenance(false);
            $this->log($update, 'info', 'completed', 'Maintenance mode disabled');
        }
        $this->audit->log('update.failed', $update, ['error' => $msg, 'rolled_back' => $update->rolled_back], 'failure', $update->started_by);
    }

    /** Automatic rollback: files → protected config (never touched) → database → cache → health. */
    public function rollback(Update $update, string $reason = 'manual'): void
    {
        $update->forceFill(['state' => UpdateState::ROLLING_BACK, 'status' => UpdateState::label(UpdateState::ROLLING_BACK)])->save();
        $this->log($update, 'warning', 'rolling_back', 'Rolling back: '.$reason);
        $ok = true;

        try {
            $r = $this->releases->rollbackFiles($update, $this->backups);
            $this->log($update, 'info', 'rolling_back', 'Files restored ('.json_encode($r).')');
        } catch (\Throwable $e) {
            $ok = false;
            $this->log($update, 'error', 'rolling_back', 'File rollback failed: '.SecretMasker::maskString($e->getMessage()));
        }

        if ($update->migration_ran && $update->backup) {
            try {
                // Preserve this update's own audit trail across the database restore
                $snapshotLogs = UpdateLog::where('update_id', $update->id)->get()->map(fn ($l) => $l->getAttributes())->all();
                $snapshotUpdate = $update->getAttributes();
                $this->backups->restoreDatabase($update->backup, $update->started_by);
                Update::query()->where('id', $update->id)->exists()
                    ? Update::query()->where('id', $update->id)->update($snapshotUpdate)
                    : Update::query()->insert($snapshotUpdate);
                foreach ($snapshotLogs as $row) {
                    UpdateLog::query()->insertOrIgnore($row);
                }
                $this->log($update, 'info', 'rolling_back', 'Database restored from backup '.$update->backup->id);
            } catch (\Throwable $e) {
                $ok = false;
                $this->log($update, 'error', 'rolling_back', 'Database restore failed: '.SecretMasker::maskString($e->getMessage()));
            }
        } else {
            $this->log($update, 'info', 'rolling_back', 'Database untouched (no migration ran)');
        }

        try {
            $this->clearCaches($this->releases->appRoot(), $update);
            $report = $this->runHealth($this->releases->appRoot());
            $this->log($update, $report['critical_ok'] ? 'info' : 'error', 'rolling_back', "Post-rollback health:\n".$report['text']);
            $ok = $ok && $report['critical_ok'];
        } catch (\Throwable $e) {
            $ok = false;
            $this->log($update, 'error', 'rolling_back', 'Post-rollback health check crashed: '.$e->getMessage());
        }

        $update->forceFill([
            'state' => $ok ? UpdateState::ROLLED_BACK : UpdateState::FAILED,
            'status' => UpdateState::label($ok ? UpdateState::ROLLED_BACK : UpdateState::FAILED),
            'rolled_back' => $ok,
            'completed_at' => now(),
            'duration_seconds' => $update->started_at ? (int) $update->started_at->diffInSeconds(now()) : 0,
        ])->save();

        $this->audit->log('update.rolled_back', $update, ['ok' => $ok, 'reason' => $reason], $ok ? 'success' : 'failure', $update->started_by);
        event(new SystemAlert('update.rolled_back', $ok ? 'Update rolled back' : 'Rollback FAILED – manual intervention required', 'Reason: '.$reason, 'critical'));
    }

    /** Detect an interrupted update (PHP process died) and recover safely. */
    public function recover(): ?Update
    {
        $update = $this->inProgress();
        $lock = $this->currentLock();
        if (! $update && ! $lock) {
            return null;
        }

        $pidAlive = isset($lock['pid']) && function_exists('posix_kill') && @posix_kill((int) $lock['pid'], 0);
        $stale = ! $pidAlive && (! $update || ! $update->updated_at || $update->updated_at->lt(now()->subMinutes(30)) || ! $lock);
        if (! $stale) {
            return $update;
        }

        if ($update) {
            $this->log($update, 'error', $update->state, 'Interrupted update detected (process not running). Recovering.');
            $update->forceFill(['error' => 'Interrupted at state '.$update->state])->save();
            if (in_array($update->state, [UpdateState::INSTALLING, UpdateState::MIGRATING, UpdateState::HEALTH_CHECK, UpdateState::ACTIVATING, UpdateState::ROLLING_BACK], true)) {
                $this->rollback($update, 'interrupted process');
            } else {
                $update->forceFill(['state' => UpdateState::FAILED, 'status' => UpdateState::label(UpdateState::FAILED), 'completed_at' => now()])->save();
            }
        }
        $this->maintenance(false);
        $this->releaseLock(null);
        try {
            Cache::lock(self::LOCK_NAME)->forceRelease();
        } catch (\Throwable) {
        }

        return $update?->fresh();
    }

    // ------------------------------------------------------------------ helpers

    private function transition(Update $update, string $to): void
    {
        if (! UpdateState::allowed($update->state, $to)) {
            throw new \LogicException("Illegal update state transition {$update->state} → $to");
        }
        $update->forceFill(['state' => $to, 'status' => UpdateState::label($to)])->save();
    }

    public function log(Update $update, string $level, string $step, string $message, array $context = []): void
    {
        UpdateLog::create(['update_id' => $update->id, 'level' => $level, 'step' => $step, 'message' => SecretMasker::maskString($message), 'context' => SecretMasker::maskArray($context) ?: null, 'created_at' => now()]);
        Log::channel('updates')->log($level === 'error' ? 'error' : ($level === 'warning' ? 'warning' : 'info'), "[$step] ".SecretMasker::maskString($message));
    }

    private function maintenance(bool $on, ?string $secret = null): void
    {
        try {
            if ($on) {
                Artisan::call('down', array_filter(['--secret' => $secret, '--retry' => 60, '--render' => 'errors::503']));
            } else {
                Artisan::call('up');
            }
        } catch (\Throwable $e) {
            Log::warning('Maintenance toggle failed: '.$e->getMessage());
        }
    }

    private function clearCaches(string $root, Update $update): void
    {
        foreach ([['config:clear'], ['cache:clear'], ['route:clear'], ['view:clear'], ['event:clear']] as $cmd) {
            [$code] = $this->releases->artisan($root, $cmd, 120);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        // Rebuild optimized caches (config/route/view) when not in local dev
        if (app()->isProduction()) {
            $this->releases->artisan($root, ['optimize'], 300);
        }
        $this->log($update, 'info', $update->state, 'Caches cleared'.(app()->isProduction() ? ' and rebuilt' : ''));
    }

    private function runHealth(string $root): array
    {
        $inProcess = (bool) config('akstream.updates.in_process', false) || ! function_exists('proc_open') || realpath($root) === realpath(base_path());
        if ($inProcess) {
            $run = $this->health->run('update', true, false);

            return ['critical_ok' => $run['critical_ok'], 'text' => $this->health->report($run)];
        }
        [$code, $out] = $this->releases->artisan($root, ['health:check', '--json', '--trigger=update'], 120);
        $json = json_decode(trim(substr($out, (int) strpos($out, '{'))), true);
        if (! is_array($json)) {
            return ['critical_ok' => false, 'text' => 'Health check did not return a report (exit '.$code.'): '.Str::limit($out, 500)];
        }

        return ['critical_ok' => (bool) ($json['critical_ok'] ?? false), 'text' => $json['report'] ?? json_encode($json)];
    }

    private function readVersion(string $root): ?string
    {
        $f = $root.'/VERSION';

        return File::exists($f) ? trim((string) File::get($f)) ?: null : null;
    }

    /** Detect new configuration variables introduced by the update; warn, never overwrite .env. */
    private function checkEnvCompatibility(Update $update, string $codeRoot): void
    {
        $example = $codeRoot.'/.env.example';
        $env = $this->releases->appRoot().'/.env';
        if (! File::exists($example) || ! File::exists($env)) {
            return;
        }
        $parse = fn (string $file) => collect(explode("\n", (string) File::get($file)))->map(fn ($l) => trim($l))->filter(fn ($l) => $l !== '' && ! str_starts_with($l, '#') && str_contains($l, '='))->map(fn ($l) => strtok($l, '='))->unique()->values();
        $missing = $parse($example)->diff($parse($env))->values();
        if ($missing->isNotEmpty()) {
            $this->log($update, 'warning', 'installing', 'New configuration keys not present in .env (safe defaults will be used, review Settings): '.$missing->implode(', '));
            Cache::put('updates.missing_env', $missing->all(), 86400 * 30);
        }
    }
}
