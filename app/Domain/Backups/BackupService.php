<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\SettingsService;
use App\Domain\Updates\ProtectedPaths;
use App\Events\SystemAlert;
use App\Models\Backup;
use App\Support\SecretMasker;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupService
{
    private ?string $sourceRoot = null;

    /** Override the application root that file backups archive (used by tests / multi-release layouts). */
    public function setSourceRoot(?string $root): void
    {
        $this->sourceRoot = $root;
    }

    public function sourceRoot(): string
    {
        return rtrim($this->sourceRoot ?? base_path(), '/');
    }

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupEncryptor $encryptor,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function directory(): string
    {
        $dir = (string) config('akstream.backups.directory') ?: storage_path('app/'.trim((string) config('akstream.backups.path', 'backups'), '/'));
        File::ensureDirectoryExists($dir, 0750);
        if (! File::exists($dir.'/.htaccess')) {
            File::put($dir.'/.htaccess', "Require all denied\n");
        }

        return $dir;
    }

    /**
     * Create a backup. Type: files | database | full.
     * Throws on failure after marking the record failed; callers (the updater) must not proceed.
     */
    public function create(string $type = 'full', string $trigger = 'manual', ?string $userId = null, ?bool $encrypt = null): Backup
    {
        $encrypt ??= $this->settings->bool('backups', 'encrypt', false);
        $stamp = now()->format('Ymd-His');

        $backup = Backup::create([
            'type' => $type,
            'trigger' => $trigger,
            'status' => 'running',
            'disk' => 'local',
            'encrypted' => $encrypt,
            'app_version' => Version::current(),
            'git_commit' => Version::commit(),
            'created_by' => $userId,
            'started_at' => now(),
            'expires_at' => now()->addDays((int) $this->settings->get('backups', 'retention_days', config('akstream.backups.retention_days', 14))),
            'metadata' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'db_driver' => DB::connection()->getDriverName(), 'app_url' => config('app.url')],
        ]);

        $dir = $this->directory();
        $size = 0;

        try {
            if (in_array($type, ['database', 'full'], true)) {
                $dbFile = $dir.'/db-'.$stamp.'-'.substr($backup->id, -6).'.sql';
                $info = $this->dumper->dump($dbFile);
                if ($info['format'] === 'sqlite') {
                    rename($dbFile, $dbFile = str_replace('.sql', '.sqlite', $dbFile));
                }
                if (! File::exists($dbFile) || File::size($dbFile) === 0) {
                    throw new \RuntimeException('Database dump is empty');
                }
                if ($encrypt) {
                    $this->encryptor->encrypt($dbFile, $dbFile.'.enc');
                    File::delete($dbFile);
                    $dbFile .= '.enc';
                }
                $backup->db_location = $dbFile;
                $backup->db_checksum = hash_file('sha256', $dbFile);
                $size += File::size($dbFile);
                $backup->metadata = array_merge($backup->metadata ?? [], ['db_method' => $info['method'], 'db_format' => $info['format']]);
            }

            if (in_array($type, ['files', 'full'], true)) {
                $zipFile = $dir.'/files-'.$stamp.'-'.substr($backup->id, -6).'.zip';
                $this->zipApplication($zipFile);
                if (! $this->verifyZip($zipFile)) {
                    throw new \RuntimeException('Files archive failed integrity verification');
                }
                if ($encrypt) {
                    $this->encryptor->encrypt($zipFile, $zipFile.'.enc');
                    File::delete($zipFile);
                    $zipFile .= '.enc';
                }
                $backup->location = $zipFile;
                $backup->checksum = hash_file('sha256', $zipFile);
                $size += File::size($zipFile);
            }

            $backup->forceFill(['status' => 'completed', 'verified' => true, 'size_bytes' => $size, 'completed_at' => now()])->save();
            $this->audit->log('backup.created', $backup, ['type' => $type, 'trigger' => $trigger, 'size' => $size], 'success', $userId);

            return $backup;
        } catch (\Throwable $e) {
            $msg = SecretMasker::maskString($e->getMessage());
            $backup->forceFill(['status' => 'failed', 'error' => $msg, 'completed_at' => now()])->save();
            $this->audit->log('backup.failed', $backup, ['error' => $msg], 'failure', $userId);
            event(new SystemAlert('backup.failed', 'Backup failed', $msg, 'critical'));
            Log::error('Backup failed: '.$msg);
            throw new \RuntimeException('Backup failed: '.$msg, 0, $e);
        }
    }

    /** Zip the application directory, excluding vendor/backups/logs and other bulky or sensitive dirs. */
    private function zipApplication(string $zipFile): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip archive');
        }
        $base = $this->sourceRoot();
        $exclude = array_map(fn ($p) => trim($p, '/'), (array) config('akstream.backups.exclude', []));

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                function (\SplFileInfo $file) use ($base, $exclude): bool {
                    $rel = ltrim(str_replace($base, '', $file->getPathname()), '/');
                    foreach ($exclude as $ex) {
                        if ($rel === $ex || str_starts_with($rel, $ex.'/')) {
                            return false;
                        }
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $rel = ltrim(str_replace($base, '', $file->getPathname()), '/');
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
            } elseif ($file->isFile() && $file->isReadable()) {
                $zip->addFile($file->getPathname(), $rel);
                if (++$count % 500 === 0) {
                    // flush periodically to keep memory bounded
                    $zip->close();
                    $zip->open($zipFile);
                }
            }
        }
        // Include the version + commit in the archive for restore metadata
        $zip->addFromString('BACKUP_META.json', json_encode(['version' => Version::current(), 'commit' => Version::commit(), 'created_at' => now()->toIso8601String()], JSON_PRETTY_PRINT));
        $zip->close();
    }

    private function verifyZip(string $zipFile): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile, ZipArchive::CHECKCONS) !== true) {
            return false;
        }
        $ok = $zip->numFiles > 0 && $zip->locateName('BACKUP_META.json') !== false;
        $zip->close();

        return $ok;
    }

    /** Re-verify a backup's checksums on disk. */
    public function verify(Backup $backup): bool
    {
        $ok = true;
        if ($backup->location) {
            $ok = File::exists($backup->location) && hash_file('sha256', $backup->location) === $backup->checksum;
        }
        if ($ok && $backup->db_location) {
            $ok = File::exists($backup->db_location) && hash_file('sha256', $backup->db_location) === $backup->db_checksum;
        }
        $backup->forceFill(['verified' => $ok])->save();

        return $ok;
    }

    /** Restore the database from a backup (plain path returned for decrypted temp file cleanup). */
    public function restoreDatabase(Backup $backup, ?string $userId = null): void
    {
        if (! $backup->db_location || ! File::exists($backup->db_location)) {
            throw new \RuntimeException('Backup has no database dump');
        }
        if (! $this->verify($backup)) {
            throw new \RuntimeException('Backup checksum mismatch; refusing to restore');
        }
        $file = $backup->db_location;
        $tmp = null;
        if ($backup->encrypted) {
            $tmp = sys_get_temp_dir().'/ak-restore-'.bin2hex(random_bytes(6));
            $this->encryptor->decrypt($file, $tmp);
            $file = $tmp;
        }
        $attributes = $backup->getAttributes();
        try {
            $this->dumper->restore($file);
            // The dump predates this backup's completion: make sure its own record survives the restore.
            Backup::query()->where('id', $backup->id)->exists()
                ? Backup::query()->where('id', $backup->id)->update($attributes)
                : Backup::query()->insert($attributes);
            $this->audit->log('backup.database_restored', $backup, [], 'success', $userId);
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Restore application files from a backup archive into the given directory (defaults to base_path()).
     * Protected paths are never overwritten.
     */
    public function restoreFiles(Backup $backup, ?string $target = null, ?string $userId = null): int
    {
        if (! $backup->location || ! File::exists($backup->location)) {
            throw new \RuntimeException('Backup has no files archive');
        }
        if (! $this->verify($backup)) {
            throw new \RuntimeException('Backup checksum mismatch; refusing to restore');
        }
        $target ??= base_path();
        $file = $backup->location;
        $tmp = null;
        if ($backup->encrypted) {
            $tmp = sys_get_temp_dir().'/ak-restore-'.bin2hex(random_bytes(6)).'.zip';
            $this->encryptor->decrypt($file, $tmp);
            $file = $tmp;
        }
        $protected = app(ProtectedPaths::class);
        $restored = 0;
        try {
            $zip = new ZipArchive;
            if ($zip->open($file) !== true) {
                throw new \RuntimeException('Cannot open backup archive');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || $name === 'BACKUP_META.json' || str_contains($name, '..') || $protected->isProtected($name)) {
                    continue;
                }
                $dest = rtrim($target, '/').'/'.$name;
                if (str_ends_with($name, '/')) {
                    File::ensureDirectoryExists($dest);

                    continue;
                }
                File::ensureDirectoryExists(dirname($dest));
                $stream = $zip->getStream($name);
                if ($stream) {
                    file_put_contents($dest, $stream);
                    fclose($stream);
                    $restored++;
                }
            }
            $zip->close();
            $this->audit->log('backup.files_restored', $backup, ['files' => $restored], 'success', $userId);
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }

        return $restored;
    }

    public function delete(Backup $backup, ?string $userId = null): void
    {
        foreach ([$backup->location, $backup->db_location] as $f) {
            if ($f && File::exists($f)) {
                File::delete($f);
            }
        }
        $backup->forceFill(['status' => 'deleted'])->save();
        $this->audit->log('backup.deleted', $backup, [], 'success', $userId);
    }

    /** Remove expired backups but always keep the newest N successful ones. */
    public function prune(): int
    {
        $keep = (int) config('akstream.backups.keep_minimum', 3);
        $keepIds = Backup::where('status', 'completed')->orderByDesc('created_at')->limit($keep)->pluck('id');
        $count = 0;
        foreach (Backup::where('status', 'completed')->whereNotNull('expires_at')->where('expires_at', '<', now())->whereNotIn('id', $keepIds)->get() as $b) {
            $this->delete($b);
            $count++;
        }

        return $count;
    }

    public function totalSize(): int
    {
        return (int) Backup::where('status', 'completed')->sum('size_bytes');
    }
}
