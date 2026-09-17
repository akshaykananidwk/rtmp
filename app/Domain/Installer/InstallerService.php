<?php

declare(strict_types=1);

namespace App\Domain\Installer;

use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Version;
use Database\Seeders\ProtectedPathSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;

class InstallerService
{
    public function lockFile(): string
    {
        return (string) (config('akstream.installer.lock_path') ?: storage_path('app/installed.lock'));
    }

    private function envWriter(): EnvWriter
    {
        $path = config('akstream.installer.env_path');

        return $path ? new EnvWriter((string) $path) : EnvWriter::forApp();
    }

    private function sqlitePath(array $db): string
    {
        $dir = (string) (config('akstream.installer.sqlite_dir') ?: database_path());

        return rtrim($dir, '/').'/'.basename((string) ($db['database'] ?: 'database.sqlite'));
    }

    public function isInstalled(): bool
    {
        return File::exists($this->lockFile());
    }

    // ------------------------------------------------------------------ step 1

    public function requirements(): array
    {
        $checks = [
            ['name' => 'PHP >= 8.2', 'pass' => version_compare(PHP_VERSION, '8.2.0', '>='), 'detail' => PHP_VERSION],
        ];
        foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL', 'openssl' => 'OpenSSL', 'curl' => 'cURL', 'json' => 'JSON', 'mbstring' => 'Mbstring', 'tokenizer' => 'Tokenizer', 'xml' => 'XML', 'ctype' => 'Ctype', 'fileinfo' => 'Fileinfo', 'zip' => 'Zip', 'sodium' => 'Sodium (backup encryption)', 'gd' => 'GD (images)'] as $ext => $label) {
            $loaded = extension_loaded($ext) || ($ext === 'pdo_mysql' && (extension_loaded('pdo_sqlite') || extension_loaded('pdo_pgsql')));
            $optional = in_array($ext, ['sodium', 'gd'], true);
            $checks[] = ['name' => $label, 'pass' => $loaded || $optional, 'warn' => ! $loaded && $optional, 'detail' => $loaded ? 'loaded' : ($optional ? 'optional, missing' : 'missing')];
        }
        foreach (['storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            File::ensureDirectoryExists($path);
            $checks[] = ['name' => "Writable: $dir", 'pass' => is_writable($path), 'detail' => is_writable($path) ? 'writable' : 'NOT writable'];
        }
        $envDir = base_path();
        $checks[] = ['name' => 'Writable: .env', 'pass' => (File::exists($envDir.'/.env') && is_writable($envDir.'/.env')) || is_writable($envDir), 'detail' => 'application root'];
        $checks[] = ['name' => 'Composer dependencies', 'pass' => File::exists(base_path('vendor/autoload.php')), 'detail' => 'vendor/autoload.php'];
        $checks[] = ['name' => 'proc_open (VPS streaming features)', 'pass' => true, 'warn' => ! function_exists('proc_open'), 'detail' => function_exists('proc_open') ? 'available' : 'disabled – streaming/ffmpeg must run on a VPS'];

        return ['checks' => $checks, 'ok' => ! in_array(false, array_column($checks, 'pass'), true)];
    }

    // ------------------------------------------------------------------ step 2

    public function testDatabase(array $db): array
    {
        try {
            $driver = $db['driver'] ?? 'mysql';
            if ($driver === 'sqlite') {
                $file = $this->sqlitePath($db);
                File::ensureDirectoryExists(dirname($file));
                if (! File::exists($file)) {
                    File::put($file, '');
                }
                new PDO('sqlite:'.$file);

                return ['ok' => true, 'message' => 'SQLite database ready'];
            }
            $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=utf8mb4', $driver === 'pgsql' ? 'pgsql' : 'mysql', $db['host'], (int) $db['port'], $db['database']);
            $pdo = new PDO($dsn, $db['username'], $db['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            return ['ok' => true, 'message' => 'Connected to '.$driver.' '.$version];
        } catch (\Throwable $e) {
            // Never echo the password back
            return ['ok' => false, 'message' => 'Connection failed: '.preg_replace('/password.*$/i', '', $e->getMessage())];
        }
    }

    // ------------------------------------------------------------------ step 5

    /**
     * Perform the installation. $config: db[], app[], admin[].
     */
    public function install(array $config): array
    {
        if ($this->isInstalled()) {
            throw new \RuntimeException('Application is already installed.');
        }

        $db = $config['db'];
        $app = $config['app'];
        $admin = $config['admin'];

        $test = $this->testDatabase($db);
        if (! $test['ok']) {
            throw new \RuntimeException($test['message']);
        }

        $appKey = 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
        $engineSecret = Str::random(48);

        $env = [
            'APP_NAME' => $app['name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $appKey,
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($app['url'], '/'),
            'APP_TIMEZONE' => $app['timezone'],
            'DB_CONNECTION' => $db['driver'] ?? 'mysql',
            'DB_HOST' => $db['host'] ?? '127.0.0.1',
            'DB_PORT' => $db['port'] ?? 3306,
            'DB_DATABASE' => ($db['driver'] ?? 'mysql') === 'sqlite' ? $this->sqlitePath($db) : $db['database'],
            'DB_USERNAME' => $db['username'] ?? '',
            'DB_PASSWORD' => $db['password'] ?? '',
            'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => str_starts_with($app['url'], 'https://'),
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'MAIL_FROM_ADDRESS' => $admin['email'],
            'STREAM_ENGINE_SECRET' => $engineSecret,
            'STREAM_SERVER_URL' => $app['rtmp_url'] ?? 'rtmp://'.parse_url($app['url'], PHP_URL_HOST).'/live',
            'LOG_LEVEL' => 'info',
        ];
        $this->envWriter()->write($env);

        // Apply to the running process so migrations use the new connection
        Config::set('app.key', $appKey);
        Config::set('app.url', $env['APP_URL']);
        Config::set('app.name', $app['name']);
        Config::set('database.default', $env['DB_CONNECTION']);
        Config::set("database.connections.{$env['DB_CONNECTION']}.host", $env['DB_HOST']);
        Config::set("database.connections.{$env['DB_CONNECTION']}.port", $env['DB_PORT']);
        Config::set("database.connections.{$env['DB_CONNECTION']}.database", $env['DB_DATABASE']);
        Config::set("database.connections.{$env['DB_CONNECTION']}.username", $env['DB_USERNAME']);
        Config::set("database.connections.{$env['DB_CONNECTION']}.password", $env['DB_PASSWORD']);
        app()->instance('encrypter', new Encrypter(base64_decode(substr($appKey, 7)), 'AES-256-CBC'));
        Crypt::clearResolvedInstance('encrypter');
        DB::purge($env['DB_CONNECTION']);
        DB::reconnect($env['DB_CONNECTION']);

        Artisan::call('migrate', ['--force' => true]);

        foreach ([RoleSeeder::class, ProtectedPathSeeder::class, SettingsSeeder::class] as $seeder) {
            (new $seeder)->run();
        }

        $tenant = Tenant::create(['name' => $app['name'], 'slug' => Str::slug($app['name']) ?: 'default']);
        app(TenantContext::class)->set($tenant);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $admin['name'],
            'email' => $admin['email'],
            'password' => $admin['password'],
            'email_verified_at' => now(),
            'timezone' => $app['timezone'],
        ]);
        $user->assignRole(Role::SUPER_ADMIN);

        foreach (['app/backups', 'app/recordings', 'app/releases', 'app/private', 'app/public/uploads'] as $dir) {
            File::ensureDirectoryExists(storage_path($dir), 0750);
        }
        foreach (['app/backups', 'app/recordings', 'app/releases', 'app/private'] as $dir) {
            File::put(storage_path($dir.'/.htaccess'), "Require all denied\n");
        }

        File::put($this->lockFile(), json_encode(['installed_at' => now()->toIso8601String(), 'version' => Version::current(), 'admin' => $admin['email']]));

        try {
            Artisan::call('config:clear');
            Artisan::call('storage:link');
        } catch (\Throwable) {
        }

        return ['app_url' => $env['APP_URL'], 'admin_url' => $env['APP_URL'].'/admin', 'admin_email' => $admin['email']];
    }
}
