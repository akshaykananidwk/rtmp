<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Backups\BackupService;
use App\Models\Update;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PharData;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Handles the file side of updates.
 *
 * Strategy "symlink" (recommended VPS layout):
 *    /var/www/app/releases/<id>/   full application copies
 *    /var/www/app/shared/{.env,storage,public/uploads}
 *    /var/www/app/current -> releases/<id>   (Apache DocumentRoot = current/public)
 *  Activation = atomic symlink switch; rollback = point the symlink back.
 *
 * Strategy "inplace" (shared hosting): the release is staged under storage/app/releases,
 *  then copied over the live tree (protected paths untouched); rollback = restore the
 *  verified files backup taken before the update.
 */
class ReleaseManager
{
    private ?string $appRoot = null;

    public function __construct(private readonly ProtectedPaths $protected) {}

    public function setAppRoot(?string $path): void
    {
        $this->appRoot = $path;
    }

    public function appRoot(): string
    {
        return rtrim($this->appRoot ?? base_path(), '/');
    }

    public function strategy(): string
    {
        $configured = (string) config('akstream.updates.strategy', 'auto');
        if (in_array($configured, ['symlink', 'inplace'], true)) {
            return $configured;
        }

        return is_link($this->appRoot()) ? 'symlink' : 'inplace';
    }

    public function releasesDir(): string
    {
        $configured = (string) config('akstream.updates.releases_path');
        if ($configured !== '') {
            $dir = $configured;
        } elseif ($this->strategy() === 'symlink') {
            $dir = dirname($this->appRoot()).'/releases';
        } else {
            $dir = $this->appRoot().'/storage/app/releases';
        }
        File::ensureDirectoryExists($dir, 0750);

        return rtrim($dir, '/');
    }

    public function sharedDir(): string
    {
        return dirname($this->appRoot()).'/shared';
    }

    public function currentReleasePath(): ?string
    {
        return is_link($this->appRoot()) ? (realpath($this->appRoot()) ?: null) : null;
    }

    // ------------------------------------------------------------------ verification

    /** Basic archive validation: gzip magic + readable tar + top-level dir contains the short SHA. */
    public function verifyArchive(string $tarball, string $sha, ?string $expectedChecksum = null): array
    {
        if (! is_file($tarball) || filesize($tarball) < 100) {
            throw new \RuntimeException('Archive missing or too small');
        }
        $fh = fopen($tarball, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);
        if ($magic !== "\x1f\x8b") {
            throw new \RuntimeException('Archive is not a gzip file');
        }
        $checksum = hash_file('sha256', $tarball);
        if ($expectedChecksum && ! hash_equals($expectedChecksum, $checksum)) {
            throw new \RuntimeException('Archive checksum mismatch');
        }

        $phar = new PharData($tarball);
        $prefix = 'phar://'.$phar->getPath().'/';
        $top = null;
        $count = 0;
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $count++;
            $pathname = (string) $file->getPathname();
            $rel = str_starts_with($pathname, $prefix) ? substr($pathname, strlen($prefix)) : ltrim(preg_replace('#^phar://[^/]*.*?\.tar(\.gz)?/#', '', $pathname) ?? $pathname, '/');
            $first = explode('/', $rel)[0];
            $top ??= $first;
            if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
                throw new \RuntimeException('Archive contains unsafe path: '.$rel);
            }
            if ($count > 200000) {
                throw new \RuntimeException('Archive has too many entries');
            }
        }
        if ($count === 0) {
            throw new \RuntimeException('Archive is empty');
        }
        $short = strtolower(substr($sha, 0, 7));
        if (! $top || ! str_contains(strtolower($top), $short)) {
            throw new \RuntimeException("Archive top-level directory '$top' does not match commit $short");
        }

        return ['checksum' => $checksum, 'entries' => $count, 'top' => $top];
    }

    // ------------------------------------------------------------------ staging

    /** Extract the tarball into a new release directory. Returns the release path. */
    public function stage(Update $update, string $tarball, string $sha): string
    {
        $releaseId = now()->format('YmdHis').'-'.substr($sha, 0, 7);
        $release = $this->releasesDir().'/'.$releaseId;
        $tmp = $this->releasesDir().'/.extract-'.$releaseId;
        File::deleteDirectory($tmp);
        File::ensureDirectoryExists($tmp, 0750);

        $phar = new PharData($tarball);
        $phar->extractTo($tmp, null, true);

        $dirs = array_values(array_filter(File::directories($tmp), fn ($d) => ! str_starts_with(basename($d), '.')));
        if (count($dirs) !== 1) {
            File::deleteDirectory($tmp);
            throw new \RuntimeException('Unexpected archive layout (expected a single top-level directory)');
        }
        if (! File::exists($dirs[0].'/artisan') || ! File::exists($dirs[0].'/composer.json')) {
            File::deleteDirectory($tmp);
            throw new \RuntimeException('Archive does not look like this application (artisan/composer.json missing)');
        }
        File::moveDirectory($dirs[0], $release);
        File::deleteDirectory($tmp);
        File::put($release.'/COMMIT', $sha."\n");

        if ($this->strategy() === 'symlink') {
            $this->prepareSymlinkRelease($release);
        }

        return $release;
    }

    private function prepareSymlinkRelease(string $release): void
    {
        $shared = $this->sharedDir();
        File::ensureDirectoryExists($shared.'/storage');
        File::ensureDirectoryExists($shared.'/public/uploads');

        // Seed shared from current release on first use
        $current = $this->currentReleasePath();
        if ($current && ! File::exists($shared.'/.env') && File::exists($current.'/.env')) {
            File::copy($current.'/.env', $shared.'/.env');
        }
        if ($current && ! File::exists($shared.'/storage/app') && File::isDirectory($current.'/storage') && ! is_link($current.'/storage')) {
            File::copyDirectory($current.'/storage', $shared.'/storage');
        }

        foreach (['storage' => $shared.'/storage', '.env' => $shared.'/.env', 'public/uploads' => $shared.'/public/uploads'] as $rel => $target) {
            $path = $release.'/'.$rel;
            if (File::exists($path) || is_link($path)) {
                File::isDirectory($path) && ! is_link($path) ? File::deleteDirectory($path) : File::delete($path);
            }
            if (File::exists($target)) {
                symlink($target, $path);
            }
        }

        // vendor: reuse the current one unless the release ships its own
        if (! File::exists($release.'/vendor/autoload.php') && $current && File::isDirectory($current.'/vendor')) {
            File::copyDirectory($current.'/vendor', $release.'/vendor');
        }
    }

    /** Ensure composer dependencies are present; run composer when the lock changed and composer is available. */
    public function dependencyCheck(Update $update, string $release): array
    {
        $lockChanged = collect($update->file_changes ?? [])->contains(fn ($c) => ($c['file'] ?? '') === 'composer.lock');
        $targetRoot = $this->strategy() === 'symlink' ? $release : $this->appRoot();
        $composer = (new ExecutableFinder)->find('composer');
        $warnings = [];

        if ($lockChanged) {
            if ($composer && function_exists('proc_open')) {
                $cwd = $this->strategy() === 'symlink' ? $release : $release; // in-place: install into the staged tree, then vendor is synced
                $p = new Process([$composer, 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader', '--no-scripts'], $cwd, ['COMPOSER_ALLOW_SUPERUSER' => '1', 'COMPOSER_HOME' => sys_get_temp_dir().'/composer-home']);
                $p->setTimeout(900);
                $p->run();
                if (! $p->isSuccessful()) {
                    throw new \RuntimeException('composer install failed: '.mb_substr($p->getErrorOutput(), -800));
                }
            } else {
                $warnings[] = 'composer.lock changed but composer is not available on this server; the release must ship its vendor/ directory.';
                if (! File::exists($release.'/vendor/autoload.php') && ! File::exists($targetRoot.'/vendor/autoload.php')) {
                    throw new \RuntimeException('Dependencies changed and no vendor/ directory is available.');
                }
            }
        }

        if ($this->strategy() === 'symlink' && ! File::exists($release.'/vendor/autoload.php')) {
            throw new \RuntimeException('Release has no vendor/autoload.php');
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ activation

    /**
     * Make the staged release live. Returns list of written/deleted files (inplace) for logs.
     */
    public function activate(Update $update, string $release): array
    {
        if ($this->strategy() === 'symlink') {
            $this->switchSymlink($release);

            return ['mode' => 'symlink', 'previous' => $update->previous_release_path];
        }

        return $this->syncInPlace($update, $release);
    }

    private function switchSymlink(string $release): void
    {
        $link = $this->appRoot();
        $tmp = $link.'.tmp-'.bin2hex(random_bytes(3));
        if (! @symlink($release, $tmp)) {
            throw new \RuntimeException('Cannot create symlink '.$tmp);
        }
        if (! @rename($tmp, $link)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot switch current symlink');
        }
    }

    /** Copy the staged tree over the live tree, honouring protected paths; delete removed files. */
    private function syncInPlace(Update $update, string $release): array
    {
        $root = $this->appRoot();
        $written = [];
        $skipped = [];
        $deleted = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $rel = ltrim(substr($file->getPathname(), strlen($release)), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            if ($this->protected->isProtected($rel)) {
                $skipped[] = $rel;

                continue;
            }
            $dest = $root.'/'.$rel;
            if ($file->isDir()) {
                File::ensureDirectoryExists($dest);

                continue;
            }
            File::ensureDirectoryExists(dirname($dest));
            // write to temp then rename => per-file atomic
            $tmp = $dest.'.aktmp';
            if (! @copy($file->getPathname(), $tmp)) {
                throw new \RuntimeException("Cannot write $rel");
            }
            @chmod($tmp, fileperms($file->getPathname()) & 0777);
            if (! @rename($tmp, $dest)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot replace $rel");
            }
            $written[] = $rel;
        }

        foreach ($update->file_changes ?? [] as $change) {
            if (($change['status'] ?? '') !== 'deleted') {
                continue;
            }
            $rel = ProtectedPaths::normalize((string) ($change['file'] ?? ''));
            if ($rel === '' || $this->protected->isProtected($rel) || str_contains($rel, '..')) {
                continue;
            }
            $path = $root.'/'.$rel;
            if (File::isFile($path)) {
                File::delete($path);
                $deleted[] = $rel;
            }
        }

        return ['mode' => 'inplace', 'written' => count($written), 'skipped_protected' => count($skipped), 'deleted' => $deleted];
    }

    // ------------------------------------------------------------------ rollback

    public function rollbackFiles(Update $update, BackupService $backups): array
    {
        if ($this->strategy() === 'symlink') {
            $previous = $update->previous_release_path ?: $this->currentReleasePath();
            if (! $previous || ! File::isDirectory($previous)) {
                throw new \RuntimeException('Previous release directory not available for rollback');
            }
            if (realpath($this->appRoot()) !== realpath($previous)) {
                $this->switchSymlink($previous);
            }

            return ['mode' => 'symlink', 'restored' => $previous];
        }

        $backup = $update->backup;
        if (! $backup || ! $backup->location) {
            throw new \RuntimeException('No files backup available for rollback');
        }
        $restored = $backups->restoreFiles($backup, $this->appRoot());

        // Remove files that the update added (not present before)
        $removed = 0;
        foreach ($update->file_changes ?? [] as $change) {
            if (($change['status'] ?? '') !== 'added') {
                continue;
            }
            $rel = ProtectedPaths::normalize((string) ($change['file'] ?? ''));
            if ($rel === '' || $this->protected->isProtected($rel) || str_contains($rel, '..')) {
                continue;
            }
            $path = $this->appRoot().'/'.$rel;
            if (File::isFile($path)) {
                File::delete($path);
                $removed++;
            }
        }

        return ['mode' => 'inplace', 'restored' => $restored, 'removed_added' => $removed];
    }

    /** Delete old release directories, keeping the newest N. */
    public function pruneReleases(int $keep = 3): int
    {
        $dirs = collect(File::directories($this->releasesDir()))->sortDesc()->values();
        $current = $this->currentReleasePath();
        $removed = 0;
        foreach ($dirs->slice($keep) as $dir) {
            if ($current && realpath($dir) === realpath($current)) {
                continue;
            }
            File::deleteDirectory($dir);
            $removed++;
        }

        return $removed;
    }

    // ------------------------------------------------------------------ artisan in target

    /**
     * Run an artisan command against the given application root. Uses a fresh PHP process so the
     * NEW code is loaded; falls back to in-process Artisan when proc_open is unavailable.
     * Returns [exitCode, output].
     */
    public function artisan(string $root, array $args, int $timeout = 900): array
    {
        $inProcess = (bool) config('akstream.updates.in_process', false) || ! function_exists('proc_open');
        if (! $inProcess) {
            $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
            $p = new Process(array_merge([$php, $root.'/artisan'], $args, ['--no-interaction']), $root, ['APP_RUNNING_UPDATE' => '1']);
            $p->setTimeout($timeout);
            $p->run();

            return [$p->getExitCode() ?? 1, $p->getOutput().$p->getErrorOutput()];
        }

        $command = array_shift($args);
        $params = [];
        foreach ($args as $a) {
            if (str_starts_with($a, '--')) {
                [$k, $v] = array_pad(explode('=', $a, 2), 2, true);
                $params[$k] = $v;
            } elseif (! isset($params['command_args'])) {
                $params[] = $a;
            }
        }
        try {
            $code = Artisan::call($command, $params);

            return [$code, Artisan::output()];
        } catch (\Throwable $e) {
            return [1, $e->getMessage()];
        }
    }
}
