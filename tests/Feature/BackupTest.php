<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Backups\BackupService;
use App\Models\Backup;
use App\Models\Tenant;
use App\Support\Version;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Uses a file-based SQLite database so database dump/restore can be verified end-to-end. */
class BackupTest extends TestCase
{
    private string $dbFile;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ak-backup-'.uniqid();
        File::ensureDirectoryExists($this->root.'/app');
        File::ensureDirectoryExists($this->root.'/storage/app/backups');
        File::put($this->root.'/app/Hello.php', '<?php // v1');
        File::put($this->root.'/.env', 'SECRET=1');
        $this->dbFile = $this->root.'/db.sqlite';
        File::put($this->dbFile, '');
        config(['database.connections.sqlite.database' => $this->dbFile, 'akstream.backups.directory' => $this->root.'/storage/app/backups']);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seedSystem();
        app(BackupService::class)->setSourceRoot($this->root);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_full_backup_verify_restore_and_prune(): void
    {
        $tenant = Tenant::create(['name' => 'Before', 'slug' => 'before']);
        $backup = app(BackupService::class)->create('full', 'manual');

        $this->assertSame('completed', $backup->status);
        $this->assertTrue($backup->verified);
        $this->assertFileExists($backup->location);
        $this->assertFileExists($backup->db_location);
        $this->assertSame(hash_file('sha256', $backup->location), $backup->checksum);
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertSame(Version::current(), $backup->app_version);
        $this->assertFileExists($this->root.'/storage/app/backups/.htaccess');

        $zip = new \ZipArchive;
        $zip->open($backup->location);
        $this->assertNotFalse($zip->locateName('app/Hello.php'));
        $this->assertNotFalse($zip->locateName('BACKUP_META.json'));
        $this->assertFalse($zip->locateName('storage/app/backups/'.basename($backup->location)));
        $zip->close();

        // Mutate DB + files, then restore
        $tenant->update(['name' => 'After']);
        Tenant::create(['name' => 'Extra', 'slug' => 'extra']);
        File::put($this->root.'/app/Hello.php', '<?php // v2');
        File::put($this->root.'/.env', 'SECRET=CHANGED');

        $svc = app(BackupService::class);
        $this->assertTrue($svc->verify($backup));
        $svc->restoreDatabase($backup);
        $this->assertSame('Before', Tenant::find($tenant->id)->name);
        $this->assertNull(Tenant::where('slug', 'extra')->first());

        $restored = $svc->restoreFiles($backup, $this->root);
        $this->assertGreaterThan(0, $restored);
        $this->assertSame('<?php // v1', File::get($this->root.'/app/Hello.php'));
        $this->assertSame('SECRET=CHANGED', File::get($this->root.'/.env'), '.env is protected and must not be restored/overwritten');

        // Tampered backup refuses to restore
        File::append($backup->location, 'x');
        $this->assertFalse($svc->verify($backup));
        $this->expectException(\RuntimeException::class);
        $svc->restoreFiles($backup, $this->root);
    }

    public function test_encrypted_backup_roundtrip(): void
    {
        $svc = app(BackupService::class);
        $backup = $svc->create('database', 'manual', null, true);
        $this->assertTrue($backup->encrypted);
        $this->assertStringEndsWith('.enc', $backup->db_location);
        $raw = file_get_contents($backup->db_location);
        $this->assertStringNotContainsString('CREATE TABLE', $raw);
        $this->assertStringNotContainsString('SQLite format', $raw);
        Tenant::create(['name' => 'Gone', 'slug' => 'gone']);
        $svc->restoreDatabase($backup);
        $this->assertNull(Tenant::where('slug', 'gone')->first());
    }

    public function test_prune_keeps_minimum(): void
    {
        $svc = app(BackupService::class);
        for ($i = 0; $i < 4; $i++) {
            $b = $svc->create('database');
            $b->forceFill(['expires_at' => now()->subDay()])->save();
        }
        $this->assertSame(1, $svc->prune());
        $this->assertSame(3, Backup::where('status', 'completed')->count());
    }

    public function test_backup_http_flow(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $this->actingAs($user)->post('/admin/backups', ['type' => 'database'])->assertSessionHas('status');
        $b = Backup::first();
        $this->actingAs($user)->get('/admin/backups')->assertOk()->assertSee($b->id);
        $this->actingAs($user)->get('/admin/backups/'.$b->id.'/download/database')->assertOk();
        $this->actingAs($user)->post('/admin/backups/'.$b->id.'/restore', ['part' => 'database'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post('/admin/backups/'.$b->id.'/restore', ['part' => 'database', 'confirm' => 'RESTORE'])->assertSessionHas('status');
        $this->actingAs($user)->delete('/admin/backups/'.$b->id)->assertRedirect();
        $this->assertSame('deleted', $b->fresh()->status);
        $this->assertFileDoesNotExist($b->db_location);
    }
}
