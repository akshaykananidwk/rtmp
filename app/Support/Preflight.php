<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Runs before the framework boots (see public/index.php).
 *
 * A freshly uploaded copy has no .env — git never carries one — which makes Laravel fail
 * with a blank HTTP 500 before the installer can ever be reached. Preflight creates the
 * file from .env.example, generates APP_KEY, and turns the remaining fatal conditions
 * (unwritable storage, missing dependencies) into a readable page instead of a blank 500.
 *
 * It only ever acts while the application is NOT installed; afterwards it does nothing.
 */
final class Preflight
{
    public static function run(string $basePath): void
    {
        if (is_file($basePath.'/storage/app/installed.lock')) {
            return;
        }

        self::ensureDirectories($basePath);
        self::ensureEnvFile($basePath);
    }

    private static function ensureEnvFile(string $basePath): void
    {
        $env = $basePath.'/.env';
        $example = $basePath.'/.env.example';

        if (is_file($env)) {
            self::ensureAppKey($env);

            return;
        }

        if (! is_file($example)) {
            self::fail('Configuration template missing', 'The file <code>.env.example</code> was not uploaded. Upload the complete application, then reload this page.');
        }

        if (! is_writable($basePath)) {
            self::fail('Cannot create configuration file', 'The application directory is not writable, so <code>.env</code> cannot be created.<br><br>Fix with:<br><code>chmod 755 '.htmlspecialchars($basePath, ENT_QUOTES).'</code><br>and make sure the directory is owned by the web-server user, then reload.');
        }

        if (! @copy($example, $env)) {
            self::fail('Cannot create configuration file', 'Copying <code>.env.example</code> to <code>.env</code> failed. Check the directory permissions and owner, then reload.');
        }
        @chmod($env, 0640);

        self::ensureAppKey($env);
    }

    private static function ensureAppKey(string $env): void
    {
        $contents = @file_get_contents($env);
        if ($contents === false) {
            self::fail('Cannot read configuration file', 'The file <code>.env</code> exists but is not readable by the web server.');
        }

        if (preg_match('/^APP_KEY=.+$/m', $contents)) {
            return;
        }

        if (! is_writable($env)) {
            self::fail('Cannot write configuration file', 'The file <code>.env</code> is not writable, so the application key cannot be generated.<br><br><code>chmod 640 .env</code> and set the owner to the web-server user, then reload.');
        }

        $key = 'base64:'.base64_encode(random_bytes(32));
        $contents = preg_match('/^APP_KEY=.*$/m', $contents)
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1)
            : rtrim($contents, "\n")."\nAPP_KEY=".$key."\n";

        if (@file_put_contents($env, $contents) === false) {
            self::fail('Cannot write configuration file', 'Writing the generated application key to <code>.env</code> failed.');
        }
    }

    private static function ensureDirectories(string $basePath): void
    {
        $required = [
            'storage/app', 'storage/app/public', 'storage/app/private', 'storage/app/backups',
            'storage/app/recordings', 'storage/app/releases', 'storage/framework/cache/data',
            'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache',
        ];

        $unwritable = [];
        foreach ($required as $relative) {
            $path = $basePath.'/'.$relative;
            if (! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            if (! is_dir($path) || ! is_writable($path)) {
                $unwritable[] = $relative;
            }
        }

        if ($unwritable !== []) {
            self::fail(
                'Storage is not writable',
                'The web server cannot write to:<br><code>'.implode('<br>', array_map(fn ($p) => htmlspecialchars($p, ENT_QUOTES), $unwritable)).'</code>'
                .'<br><br>Fix with:<br><code>chmod -R 775 storage bootstrap/cache</code><br>'
                .'and set the owner to the web-server user (e.g. <code>chown -R www:www .</code> on aaPanel, <code>www-data</code> on Ubuntu), then reload.'
            );
        }
    }

    /** Readable failure page instead of a blank 500. Never prints secrets. */
    private static function fail(string $title, string $message): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, strip_tags(str_replace('<br>', "\n", $title."\n".$message))."\n");
            exit(1);
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup required | AK COMPUTER</title>'
            .'<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b0f1a;color:#e5e9f2;font-family:Inter,system-ui,sans-serif;padding:24px}'
            .'.b{max-width:640px}h1{font-size:1.4rem;margin:0 0 4px}.t{color:#8b95ab;letter-spacing:.18em;font-size:.72rem;margin-bottom:18px}'
            .'p{color:#c7d2fe;line-height:1.7}code{background:#151c2c;border:1px solid #243049;border-radius:6px;padding:2px 7px;display:inline-block;margin:2px 0;color:#a5f3fc}'
            .'a{color:#22d3ee}</style></head><body><div class="b"><div class="t">AK COMPUTER · ONE LIVE EVERYWHERE</div>'
            .'<h1>'.htmlspecialchars($title, ENT_QUOTES).'</h1><p>'.$message.'</p>'
            .'<p style="color:#8b95ab;font-size:.85rem">Open <a href="diagnose.php">diagnose.php</a> for a full pre-installation check.</p></div></body></html>';
        exit;
    }
}
