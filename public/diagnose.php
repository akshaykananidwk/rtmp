<?php

/**
 * Standalone pre-installation diagnostic page — AK COMPUTER · ONE LIVE EVERYWHERE
 *
 * Open  https://your-domain/diagnose.php  when the site shows a blank 500 and you need to know
 * why. It deliberately does NOT boot the framework, so it still works when the application
 * cannot start. It never prints secrets: values from .env are reported as "set / not set" only.
 *
 * Once the application is installed this page reports status only (no log contents) — use
 * Admin → Logs → Errors instead, which is authenticated.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
$installed = is_file($base.'/storage/app/installed.lock');

/** @var array<int, array{0:string,1:string,2:string}> $checks  [status, label, detail] */
$checks = [];
$add = static function (string $status, string $label, string $detail = '') use (&$checks): void {
    $checks[] = [$status, $label, $detail];
};

// --- PHP ---------------------------------------------------------------------
$add(version_compare(PHP_VERSION, '8.2.0', '>=') ? 'pass' : 'fail', 'PHP version', PHP_VERSION.' ('.PHP_SAPI.')');

$required = ['pdo', 'openssl', 'curl', 'json', 'mbstring', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'zip'];
$missing = array_values(array_filter($required, static fn ($e) => ! extension_loaded($e)));
$add($missing === [] ? 'pass' : 'fail', 'Required extensions', $missing === [] ? implode(', ', $required) : 'MISSING: '.implode(', ', $missing));

$driver = extension_loaded('pdo_mysql') ? 'pdo_mysql' : (extension_loaded('pdo_sqlite') ? 'pdo_sqlite' : (extension_loaded('pdo_pgsql') ? 'pdo_pgsql' : ''));
$add($driver !== '' ? 'pass' : 'fail', 'Database driver', $driver !== '' ? $driver : 'no PDO driver available');

$optional = array_values(array_filter(['redis', 'gd', 'intl', 'sodium', 'pcntl', 'posix'], static fn ($e) => ! extension_loaded($e)));
$add($optional === [] ? 'pass' : 'warn', 'Recommended extensions', $optional === [] ? 'all present' : 'missing: '.implode(', ', $optional));

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$needed = array_values(array_intersect(['proc_open', 'symlink', 'exec'], $disabled));
$add($needed === [] ? 'pass' : 'warn', 'Disabled functions', $needed === [] ? 'none relevant' : implode(', ', $needed).' (streaming/update features may be limited)');

// --- Files -------------------------------------------------------------------
$autoload = $base.'/vendor/autoload.php';
$add(is_file($autoload) ? 'pass' : 'fail', 'Dependencies (vendor/)', is_file($autoload) ? 'vendor/autoload.php found' : 'MISSING — run: composer install --no-dev --optimize-autoloader');

foreach (['artisan', 'bootstrap/app.php', 'public/index.php', '.env.example'] as $f) {
    $add(is_file($base.'/'.$f) ? 'pass' : 'fail', 'File: '.$f, is_file($base.'/'.$f) ? 'present' : 'missing');
}

// --- .env --------------------------------------------------------------------
$envPath = $base.'/.env';
if (is_file($envPath)) {
    $env = (string) @file_get_contents($envPath);
    $add(is_readable($envPath) ? 'pass' : 'fail', 'Configuration (.env)', is_readable($envPath) ? 'present and readable' : 'present but NOT readable');
    $add(preg_match('/^APP_KEY=.+$/m', $env) ? 'pass' : 'fail', 'APP_KEY', preg_match('/^APP_KEY=.+$/m', $env) ? 'set' : 'EMPTY — run: php artisan key:generate');

    $val = static function (string $key) use ($env): string {
        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $m) ? trim($m[1], " \"'") : '';
    };
    $sessionDriver = $val('SESSION_DRIVER') ?: 'file';
    $add(! $installed && $sessionDriver === 'database' ? 'warn' : 'pass', 'Session driver', $sessionDriver.($installed ? '' : ' (file/sync are used until installation completes)'));
    $add($val('APP_URL') !== '' ? 'pass' : 'warn', 'APP_URL', $val('APP_URL') ?: 'not set');
    $add($val('APP_DEBUG') === 'false' || $installed === false ? 'pass' : 'warn', 'APP_DEBUG', $val('APP_DEBUG') ?: 'not set');
    foreach (['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $k) {
        $add($val($k) !== '' ? 'pass' : 'warn', $k, $val($k) !== '' ? $val($k) : 'not set');
    }
    $add($val('DB_PASSWORD') !== '' ? 'pass' : 'warn', 'DB_PASSWORD', $val('DB_PASSWORD') !== '' ? 'set (hidden)' : 'empty');
} else {
    $add(is_writable($base) ? 'warn' : 'fail', 'Configuration (.env)', is_writable($base)
        ? 'missing — it is created automatically on the next page load'
        : 'missing AND the application directory is not writable (chmod 755 + correct owner)');
}

// --- Writability -------------------------------------------------------------
foreach (['storage', 'storage/app', 'storage/framework', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs', 'bootstrap/cache'] as $dir) {
    $path = $base.'/'.$dir;
    $ok = is_dir($path) && is_writable($path);
    $add($ok ? 'pass' : 'fail', 'Writable: '.$dir, is_dir($path) ? ($ok ? 'writable' : 'NOT writable — chmod -R 775 '.$dir) : 'directory missing');
}

$owner = function_exists('posix_getpwuid') && function_exists('fileowner') ? (posix_getpwuid((int) fileowner($base))['name'] ?? '?') : '?';
$process = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : (get_current_user() ?: '?');
$add($owner === $process || $owner === '?' ? 'pass' : 'warn', 'Ownership', 'files owned by "'.$owner.'", PHP runs as "'.$process.'"'.($owner !== $process && $owner !== '?' ? ' — chown -R '.$process.':'.$process.' .' : ''));

// --- Stale caches ------------------------------------------------------------
$stale = array_values(array_filter((array) @glob($base.'/bootstrap/cache/*.php'), static fn ($f) => basename((string) $f) !== '.gitignore'));
$add($stale === [] ? 'pass' : 'warn', 'Compiled caches', $stale === [] ? 'none' : count($stale).' file(s) — delete bootstrap/cache/*.php after changing dependencies');

// --- Install state -----------------------------------------------------------
$add($installed ? 'pass' : 'warn', 'Installation', $installed ? 'installed (/install is locked)' : 'not installed yet — open /install');

$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as [$s]) {
    $counts[$s]++;
}

// --- Recent errors (only before installation) --------------------------------
$logTail = '';
if (! $installed) {
    $logs = (array) @glob($base.'/storage/logs/*.log');
    usort($logs, static fn ($a, $b) => (int) @filemtime((string) $b) <=> (int) @filemtime((string) $a));
    if (isset($logs[0]) && is_readable((string) $logs[0])) {
        $lines = @file((string) $logs[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail = array_slice($lines, -25);
        $text = implode("\n", $tail);
        // strip anything that could be a secret before showing it
        $text = (string) preg_replace('#(rtmps?://[^\s/]+/[^\s/]+/)([^\s/?"\']+)#i', '$1***', $text);
        $text = (string) preg_replace('#\b(gh[pousr]_[A-Za-z0-9]{10,}|github_pat_[A-Za-z0-9_]{10,}|base64:[A-Za-z0-9+/=]{20,})\b#', '***', $text);
        $text = (string) preg_replace('#((?:password|token|secret|key)\s*[=:]\s*)\S+#i', '$1***', $text);
        $logTail = $text;
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Diagnostics | AK COMPUTER</title>
<style>
body{margin:0;background:#0b0f1a;color:#e5e9f2;font-family:Inter,system-ui,-apple-system,sans-serif;padding:24px 16px}
.w{max-width:860px;margin:0 auto}
.t{color:#8b95ab;letter-spacing:.18em;font-size:.72rem}h1{font-size:1.5rem;margin:4px 0 16px}
.sum{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.sum span{padding:6px 14px;border-radius:999px;font-weight:700;font-size:.8rem;border:1px solid}
.p{background:rgba(34,197,94,.14);color:#4ade80;border-color:rgba(34,197,94,.4)}
.w2{background:rgba(245,158,11,.14);color:#fbbf24;border-color:rgba(245,158,11,.4)}
.f{background:rgba(239,68,68,.14);color:#f87171;border-color:rgba(239,68,68,.4)}
table{width:100%;border-collapse:collapse;background:#151c2c;border:1px solid #243049;border-radius:12px;overflow:hidden}
td{padding:9px 12px;border-bottom:1px solid #243049;font-size:.88rem;vertical-align:top}
tr:last-child td{border-bottom:none}
td.s{width:56px;font-weight:700;text-align:center}
td.l{width:34%;color:#c7d2fe}td.d{color:#8b95ab;word-break:break-word}
pre{background:#070a12;border:1px solid #243049;border-radius:10px;padding:12px;overflow:auto;font-size:.76rem;color:#c7d2fe;max-height:340px;white-space:pre-wrap;word-break:break-word}
a{color:#22d3ee}.n{color:#8b95ab;font-size:.82rem;line-height:1.7}
</style>
</head>
<body><div class="w">
<div class="t">AK COMPUTER · ONE LIVE EVERYWHERE</div>
<h1>Installation diagnostics</h1>
<div class="sum">
  <span class="p"><?= $counts['pass'] ?> PASS</span>
  <span class="w2"><?= $counts['warn'] ?> WARN</span>
  <span class="f"><?= $counts['fail'] ?> FAIL</span>
</div>
<table>
<?php foreach ($checks as [$status, $label, $detail]) { ?>
  <tr>
    <td class="s <?= $status === 'pass' ? 'pgreen' : '' ?>" style="color:<?= $status === 'pass' ? '#4ade80' : ($status === 'warn' ? '#fbbf24' : '#f87171') ?>"><?= $status === 'pass' ? '&#10003;' : ($status === 'warn' ? '!' : '&#10007;') ?></td>
    <td class="l"><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
    <td class="d"><?= htmlspecialchars($detail, ENT_QUOTES) ?></td>
  </tr>
<?php } ?>
</table>

<?php if ($logTail !== '') { ?>
  <h2 style="font-size:1rem;margin:22px 0 8px">Last log entries (secrets removed)</h2>
  <pre><?= htmlspecialchars($logTail, ENT_QUOTES) ?></pre>
<?php } ?>

<p class="n" style="margin-top:20px">
  <?php if ($counts['fail'] === 0) { ?>
    No blocking problems found. Continue at <a href="install">/install</a><?= $installed ? ' (already installed — go to <a href="admin">/admin</a>)' : '' ?>.
  <?php } else { ?>
    Fix the red items above, then reload this page.
  <?php } ?>
  <br>Delete <code>public/diagnose.php</code> once the site is running if you prefer not to expose it.
</p>
</div></body></html>
