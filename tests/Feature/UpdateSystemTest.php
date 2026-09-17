<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Backups\BackupService;
use App\Domain\Settings\SettingsService;
use App\Domain\Updates\ReleaseManager;
use App\Domain\Updates\UpdateChecker;
use App\Domain\Updates\UpdateManager;
use App\Models\Backup;
use App\Models\Tenant;
use App\Models\Update;
use App\Models\UpdateLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WORKFLOW D (1.0.0 → 1.0.1 success) and WORKFLOW E (broken migration → automatic rollback),
 * executed against a temporary application root with a file-based SQLite database and a
 * fake GitHub API + real tar.gz archives.
 */
class UpdateSystemTest extends TestCase
{
    private const OLD_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const NEW_SHA = 'bbbbbbbb1234567890abcdef1234567890abcdef';

    private string $root;

    private string $dbFile;

    private string $pkgDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ak-update-'.uniqid();
        $this->pkgDir = sys_get_temp_dir().'/ak-pkg-'.uniqid();
        foreach (['app', 'database/migrations', 'public/uploads', 'storage/app/releases', 'storage/app/backups', 'storage/framework'] as $d) {
            File::ensureDirectoryExists($this->root.'/'.$d);
        }
        File::copy(base_path('artisan'), $this->root.'/artisan');
        File::put($this->root.'/composer.json', '{"name":"ak/test"}');
        File::put($this->root.'/VERSION', "1.0.0\n");
        File::put($this->root.'/COMMIT', self::OLD_SHA."\n");
        File::put($this->root.'/.env', "APP_KEY=keep-me\nOLD_ONLY=1\n");
        File::put($this->root.'/.env.example', "APP_KEY=\n");
        File::put($this->root.'/app/Existing.php', '<?php // v1');
        File::put($this->root.'/app/Obsolete.php', '<?php // to be deleted');
        File::put($this->root.'/public/uploads/keep.txt', 'user upload');

        $this->dbFile = $this->root.'/storage/db.sqlite';
        File::put($this->dbFile, '');
        config([
            'database.connections.sqlite.database' => $this->dbFile,
            'akstream.version_file' => $this->root.'/VERSION',
            'akstream.updates.in_process' => true,
            'akstream.updates.strategy' => 'inplace',
            'akstream.updates.releases_path' => $this->root.'/storage/app/releases',
            'akstream.backups.directory' => $this->root.'/storage/app/backups',
            'akstream.streaming.engine' => 'none',
            'akstream.commit_file' => $this->root.'/COMMIT',
            'akstream.disk.warning_percent' => 100,
            'akstream.disk.critical_percent' => 101,
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seedSystem();

        app(ReleaseManager::class)->setAppRoot($this->root);
        app(BackupService::class)->setSourceRoot($this->root);
        $s = app(SettingsService::class);
        $s->set('updates', 'repository', 'akcomputer/onelive');
        $s->set('updates', 'branch', 'main');
        $s->set('updates', 'github_token', 'ghp_testtoken1234567890', true);
        File::delete(storage_path('framework/update.lock'));
    }

    protected function tearDown(): void
    {
        try {
            Artisan::call('up');
        } catch (\Throwable) {
        }
        File::delete(storage_path('framework/update.lock'));
        DB::purge('sqlite');
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->pkgDir);
        parent::tearDown();
    }

    /** Builds a GitHub-style tarball "owner-repo-<sha7>/..." with the given files. */
    private function buildTarball(array $files): string
    {
        $top = $this->pkgDir.'/src/akcomputer-onelive-'.substr(self::NEW_SHA, 0, 7);
        File::deleteDirectory($this->pkgDir);
        File::ensureDirectoryExists($top);
        foreach ($files as $path => $content) {
            File::ensureDirectoryExists(dirname($top.'/'.$path));
            File::put($top.'/'.$path, $content);
        }
        $tar = $this->pkgDir.'/release.tar';
        $phar = new \PharData($tar);
        $phar->buildFromDirectory($this->pkgDir.'/src');
        $phar->compress(\Phar::GZ);
        unset($phar);

        return $tar.'.gz';
    }

    private function fakeGitHub(string $tarball, array $files): void
    {
        $compareFiles = array_map(fn ($f, $st) => ['filename' => $f, 'status' => $st, 'additions' => 1, 'deletions' => 0, 'changes' => 1], array_keys($files), array_values($files));
        Http::fake([
            'api.github.com/repos/akcomputer/onelive/branches/main' => Http::response(['name' => 'main', 'commit' => ['sha' => self::NEW_SHA, 'html_url' => 'https://github.com/x', 'commit' => ['message' => "Fix streaming retry issue\n\nDetails", 'author' => ['name' => 'Akshay Kanani', 'date' => '2026-09-18T10:00:00Z']]]]),
            'api.github.com/repos/akcomputer/onelive/contents/VERSION*' => Http::response("1.0.1\n"),
            'api.github.com/repos/akcomputer/onelive/releases/latest' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/akcomputer/onelive/compare/*' => Http::response(['ahead_by' => 1, 'behind_by' => 0, 'total_commits' => 1, 'commits' => [], 'files' => $compareFiles]),
            'api.github.com/repos/akcomputer/onelive/tarball/*' => Http::response(file_get_contents($tarball), 200, ['Content-Type' => 'application/x-gzip']),
            'api.github.com/repos/akcomputer/onelive' => Http::response(['full_name' => 'akcomputer/onelive', 'private' => true, 'default_branch' => 'main']),
        ]);
    }

    private function baseFiles(): array
    {
        return [
            'artisan' => File::get(base_path('artisan')),
            'composer.json' => '{"name":"ak/test"}',
            'VERSION' => "1.0.1\n",
            '.env.example' => "APP_KEY=\nAPP_NEW_SETTING=\n",
            '.env' => "HACKED=1\n", // must never overwrite the live .env
            'app/Existing.php' => '<?php // v2',
            'app/NewFeature.php' => '<?php // new',
            'public/uploads/evil.txt' => 'must not be written',
            'database/migrations/2026_02_01_000000_create_feature_flags_table.php' => $this->migration('feature_flags'),
        ];
    }

    private function migration(string $table, bool $broken = false): string
    {
        $body = $broken
            ? "throw new \\RuntimeException('Broken migration: simulated failure');"
            : "Schema::create('$table', function (Blueprint \$t) { \$t->id(); \$t->string('name'); });";

        return "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration {\n    public function up(): void { $body }\n    public function down(): void { Schema::dropIfExists('$table'); }\n};\n";
    }

    public function test_workflow_d_check_then_successful_update(): void
    {
        $files = $this->baseFiles();
        $tarball = $this->buildTarball($files);
        $this->fakeGitHub($tarball, ['VERSION' => 'modified', 'app/Existing.php' => 'modified', 'app/NewFeature.php' => 'added', 'app/Obsolete.php' => 'removed', '.env' => 'modified', 'database/migrations/2026_02_01_000000_create_feature_flags_table.php' => 'added']);

        // CHECK FOR UPDATE – information only, nothing downloaded
        $check = app(UpdateChecker::class)->check();
        $this->assertTrue($check['update_available']);
        $this->assertSame('1.0.0', $check['current_version']);
        $this->assertSame('1.0.1', $check['latest_version']);
        $this->assertSame(self::NEW_SHA, $check['commit']['sha']);
        $this->assertSame('Akshay Kanani', $check['commit']['author']);
        $this->assertSame(6, $check['changed_files']);
        $this->assertSame(2, $check['counts']['added']);
        $this->assertSame(1, $check['counts']['deleted']);
        $this->assertSame(1, $check['counts']['protected_skipped']);
        $this->assertSame(0, Update::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tarball/'));

        // UPDATE NOW
        $manager = app(UpdateManager::class);
        $update = $manager->begin('user-1');
        $this->assertSame('checking', $update->state);
        $update = $manager->run($update);

        $log = UpdateLog::where('update_id', $update->id)->pluck('message')->implode("\n");
        $this->assertSame('completed', $update->state, $log);
        $this->assertSame('Completed', $update->status);
        $this->assertTrue($update->backup_ok);
        $this->assertTrue($update->migration_ok);
        $this->assertTrue($update->health_ok);
        $this->assertFalse($update->rolled_back);
        $this->assertSame('1.0.1', $update->version);
        $this->assertSame('1.0.0', $update->previous_version);
        $this->assertNotEmpty($update->archive_checksum);

        // Files: updated, added, deleted; protected preserved
        $this->assertSame("1.0.1\n", File::get($this->root.'/VERSION'));
        $this->assertSame(trim(self::NEW_SHA), trim(File::get($this->root.'/COMMIT')));
        $this->assertSame('<?php // v2', File::get($this->root.'/app/Existing.php'));
        $this->assertFileExists($this->root.'/app/NewFeature.php');
        $this->assertFileDoesNotExist($this->root.'/app/Obsolete.php');
        $this->assertSame("APP_KEY=keep-me\nOLD_ONLY=1\n", File::get($this->root.'/.env'));
        $this->assertSame('user upload', File::get($this->root.'/public/uploads/keep.txt'));
        $this->assertFileDoesNotExist($this->root.'/public/uploads/evil.txt');

        // Migration ran, backup exists & verified, maintenance off, caches cleared, config compatibility warning
        $this->assertTrue(Schema::hasTable('feature_flags'));
        $backup = Backup::find($update->backup_id);
        $this->assertTrue($backup->isUsable());
        $this->assertFileExists($backup->location);
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertFileDoesNotExist(storage_path('framework/update.lock'));
        $this->assertStringContainsString('Caches cleared', $log);
        $this->assertStringContainsString('APP_NEW_SETTING', $log);
        $this->assertStringContainsString('Health check', $log);
        $this->assertStringNotContainsString('ghp_testtoken', $log);
        $this->assertDatabaseHas('activity_logs', ['action' => 'update.completed']);

        // Staged release dir + downloaded archive cleaned
        $this->assertCount(0, File::glob($this->root.'/storage/app/releases/download-*'));

        // Second run: same commit → not available
        $again = app(UpdateChecker::class)->check();
        $this->assertFalse($again['update_available']);
    }

    public function test_workflow_e_broken_migration_triggers_automatic_rollback(): void
    {
        $files = $this->baseFiles();
        $files['database/migrations/2026_02_01_000001_broken_migration.php'] = $this->migration('never', true);
        $tarball = $this->buildTarball($files);
        $this->fakeGitHub($tarball, ['VERSION' => 'modified', 'app/Existing.php' => 'modified', 'app/NewFeature.php' => 'added', 'app/Obsolete.php' => 'removed']);
        Tenant::create(['name' => 'Pre-update tenant', 'slug' => 'pre']);

        app(UpdateChecker::class)->check();
        $manager = app(UpdateManager::class);
        $update = $manager->run($manager->begin('user-1'));

        $log = UpdateLog::where('update_id', $update->id)->pluck('message')->implode("\n");
        $this->assertSame('rolled_back', $update->state, $log);
        $this->assertSame('Rolled Back', $update->status);
        $this->assertTrue($update->rolled_back);
        $this->assertTrue($update->backup_ok);
        $this->assertFalse($update->migration_ok);
        $this->assertStringContainsString('Broken migration', (string) $update->error);

        // Previous version restored
        $this->assertSame("1.0.0\n", File::get($this->root.'/VERSION'));
        $this->assertSame('<?php // v1', File::get($this->root.'/app/Existing.php'));
        $this->assertFileDoesNotExist($this->root.'/app/NewFeature.php');
        $this->assertFileExists($this->root.'/app/Obsolete.php');
        $this->assertSame("APP_KEY=keep-me\nOLD_ONLY=1\n", File::get($this->root.'/.env'));
        $this->assertSame('user upload', File::get($this->root.'/public/uploads/keep.txt'));

        // Database restored: the first (successful) migration of the release is gone again
        $this->assertFalse(Schema::hasTable('feature_flags'));
        $this->assertNotNull(Tenant::where('slug', 'pre')->first());
        $this->assertNull(DB::table('migrations')->where('migration', 'like', '%feature_flags%')->first());

        // System healthy again, maintenance off, lock released
        $this->assertStringContainsString('Post-rollback health', $log);
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertFileDoesNotExist(storage_path('framework/update.lock'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'update.rolled_back']);

        // A fresh update can be started afterwards (lock released)
        $this->assertNull($manager->inProgress());
    }

    public function test_backup_failure_aborts_update_before_any_change(): void
    {
        $tarball = $this->buildTarball($this->baseFiles());
        $this->fakeGitHub($tarball, ['VERSION' => 'modified']);
        app(UpdateChecker::class)->check();
        config(['akstream.backups.directory' => '/proc/definitely-not-writable/x']);

        $manager = app(UpdateManager::class);
        $update = $manager->run($manager->begin('user-1'));
        $this->assertSame('failed', $update->state);
        $this->assertFalse($update->backup_ok);
        $this->assertSame("1.0.0\n", File::get($this->root.'/VERSION'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/tarball/'));
        $this->assertFileDoesNotExist(storage_path('framework/down'));
    }

    public function test_corrupt_archive_is_rejected(): void
    {
        $tarball = $this->buildTarball($this->baseFiles());
        Http::fake(['api.github.com/repos/akcomputer/onelive/tarball/*' => Http::response(str_repeat('garbage-not-gzip', 100), 200)]);
        $this->fakeGitHub($tarball, ['VERSION' => 'modified']);
        app(UpdateChecker::class)->check();
        $manager = app(UpdateManager::class);
        $update = $manager->run($manager->begin('user-1'));
        $this->assertSame('failed', $update->state);
        $this->assertStringContainsString('not a gzip', (string) $update->error);
        $this->assertSame("1.0.0\n", File::get($this->root.'/VERSION'));
    }

    public function test_update_lock_prevents_concurrent_updates(): void
    {
        $tarball = $this->buildTarball($this->baseFiles());
        $this->fakeGitHub($tarball, ['VERSION' => 'modified']);
        app(UpdateChecker::class)->check();
        $manager = app(UpdateManager::class);
        $first = $manager->begin('admin-a');
        $this->expectExceptionMessage('already in progress');
        $manager->begin('admin-b');
    }

    public function test_interrupted_update_is_recovered(): void
    {
        $update = Update::create(['state' => 'migrating', 'status' => 'Migrating', 'commit_sha' => self::NEW_SHA, 'previous_version' => '1.0.0', 'started_at' => now()->subHour(), 'migration_ran' => false]);
        Update::where('id', $update->id)->update(['updated_at' => now()->subHour()]);
        File::put(storage_path('framework/update.lock'), json_encode(['update_id' => $update->id, 'pid' => 999999999]));
        $manager = app(UpdateManager::class);
        $this->assertTrue($manager->isLocked());
        $r = $manager->recover();
        $this->assertContains($r->state, ['failed', 'rolled_back']);
        $this->assertFileDoesNotExist(storage_path('framework/update.lock'));
        $this->assertNull($manager->inProgress());
    }

    public function test_admin_ui_shows_check_and_history(): void
    {
        $tarball = $this->buildTarball($this->baseFiles());
        $this->fakeGitHub($tarball, ['VERSION' => 'modified']);
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $this->actingAs($user)->post('/admin/updates/check')->assertSessionHas('status');
        $this->actingAs($user)->get('/admin/updates')->assertOk()->assertSee('1.0.1')->assertSee('Fix streaming retry issue')->assertSee('UPDATE NOW')->assertDontSee('ghp_testtoken');
        $this->actingAs($user)->post('/admin/updates/install', [])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post('/admin/updates/install', ['confirm' => 'UPDATE'])->assertRedirect();
        $update = Update::first();
        $this->assertSame('completed', $update->fresh()->state, UpdateLog::pluck('message')->implode("\n"));
        $this->actingAs($user)->get('/admin/updates/'.$update->id)->assertOk()->assertSee('Completed');
        $this->actingAs($user)->getJson('/admin/updates/'.$update->id.'/status')->assertOk()->assertJson(['finished' => true, 'state' => 'completed']);
    }
}
