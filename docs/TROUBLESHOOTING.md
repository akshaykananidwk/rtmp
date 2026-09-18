# Troubleshooting

| Symptom | Check / fix |
|---|---|
| `/install` shows 404 | Already installed (`storage/app/installed.lock`). Delete the lock only for a fresh reinstall. |
| Installer step 1 fails: storage not writable | `chown -R www-data:www-data storage bootstrap/cache; chmod -R 750 storage` |
| Login: "Too many login attempts" | Wait 15 min or clear the cache (`php artisan cache:clear`). |
| Locked out of admin | `php artisan user:make you@example.com --password='…' --role=super_admin` |
| 500 page with Reference ID | Admin → Logs → Errors → open reference; or `storage/logs/laravel-*.log` |
| OBS cannot connect | `systemctl status mediamtx`; port 1935 open; key enabled/not revoked; `authHTTPAddress` reaches the app (curl from the VPS) |
| Stream never shows "Incoming Stream Detected" | hooks not reaching the app: check `STREAM_ENGINE_SECRET` in `mediamtx.yml`; run `php artisan stream:sync`; look at `journalctl -u mediamtx` |
| "The rtmp url field is required" when adding a destination, although the box was filled | Fixed in 1.0.1 — every platform's field block was posted at once and the last one blanked the chosen platform. Update, then hard-refresh the page (Ctrl+F5) so the new `app.js` loads. |
| The overlay renders ("Overlay is being rendered into the stream") but never appears on the platform | Fixed in 1.0.6. The overlay encoder starts a few seconds after the relays, and a relay kept whichever stream it was given — so it carried on copying the un-branded source. Relays now follow the branded stream as soon as it is live, and fall back if the encoder stops. |
| `Error during demuxing: Broken pipe` in the overlay encoder, every 30–60 s | The media server dropped the encoder for reading too slowly. The log now states the measured speed (e.g. "encoded at 0.61x real time (17 fps)"). Below 1x the server cannot encode that overlay live: lower the overlay resolution, bitrate or preset in **Overlays → edit**, or use a faster CPU. `writeQueueSize` was also raised in `mediamtx.yml` to absorb brief stalls. |
| MediaMTX lists a path like `live/live/<key>` | The internal RTMP base was configured with the `/live` app segment (as the OBS ingest URL has), while the code appends the full path — producing `live/live/<key>`, which has no publisher. Fixed in 1.0.5: the base is normalised to host and port, so no `.env` change is needed. The extra path disappears once the supervisor restarts. |
| `Error opening input files: Input/output error` on every relay and the overlay encoder | FFmpeg cannot read the source from MediaMTX. The log now also carries the line that says why (e.g. `NetStream.Play.StreamNotFound`, `Server error: …`). Check MediaMTX is up (`systemctl status mediamtx`), that OBS is still publishing (`curl -s http://127.0.0.1:9997/v3/paths/list`), and that `/etc/mediamtx/mediamtx.yml` has both the `live/` and `branded/` paths. |
| Destination stuck in *connecting* / *reconnecting* | Live Logs show the ffmpeg error; verify platform key/URL, test destination; `journalctl -u akstream-supervisor` |
| I want to check the whole thing works without setting up OBS | `sudo -u www php artisan stream:selftest --distribute` publishes a real test broadcast (colour bars and a tone) into this server and reports each step: ffmpeg, the media server, the ingest hook, the supervisor, the relays, and the overlay. Add `--seconds=60` for a longer run, `--key=<slug>` to pick a stream key. |
| A colour bar in an overlay shows up white, hiding the text on it | Fixed in 1.8.0. A colour written as `black@0.6` — FFmpeg's own spelling — failed the colour check and fell back to solid white. Colours with an alpha suffix are honoured now. |
| Admin → Updates never finds an update | The in-panel updater ships unconfigured: the repository setting is empty and the branch defaults to `main`, which is not where this copy came from. Fill both in on that page, or let the update script do it — from 1.2.2 it records the commit in `COMMIT` and runs `php artisan updates:source <owner/repo> <branch>`, which sets them without overwriting anything you chose yourself. |
| Changing `disable_functions` would affect my other websites | It would — that setting is per PHP version, shared by every site using it. You do not need to change it: the services now start through `scripts/run-artisan.sh`, which re-enables only the functions the streaming processes need **for that one process**, computed from whatever the panel currently disables. Everything else the panel blocked (`exec`, `system`, `passthru`, …) stays blocked, and no other site is touched. |
| `Call to undefined function pcntl_signal()` / `proc_open()` in the supervisor log | The hosting panel disables these in `disable_functions`. `pcntl_signal` is now optional (the supervisor runs without it). `proc_open`, `proc_get_status`, `proc_terminate` and `proc_close` are required — without them PHP cannot start FFmpeg at all. Remove them from the **CLI** php.ini (aaPanel: App Store → PHP → Settings → Disabled functions), then `sudo systemctl restart akstream-supervisor`. |
| All destinations *pending*, nothing happens | The relay supervisor is down — it is the process that pushes video to the platforms; the web panel only records the request. Live Stream now shows a red banner saying so, and Health → Relay Supervisor fails. Fix: `sudo systemctl start akstream-supervisor && sudo systemctl enable akstream-supervisor`. If it refuses to start: `sudo journalctl -u akstream-supervisor -n 50 --no-pager`, or run one pass in the foreground to see the error: `sudo -u www php artisan stream:supervisor --once` |
| YouTube "live streaming not enabled" | enable Live in YouTube Studio (24 h wait after phone verification) |
| Facebook token expired | reconnect the account (long-lived tokens ≈ 60 days) |
| Queue jobs not processed | `systemctl status akstream-queue` or cron `queue:work --stop-when-empty` on shared hosting |
| Scheduled stream marked *missed* | source was not publishing within 30 min of the scheduled time |
| Update: "Another update is already running" | `php artisan update:recover` (stale lock after crash) |
| Update failed & rolled back | open the update log; common causes: migration error, missing PHP extension, composer.lock changed without vendor; fix upstream and retry |
| Update: composer.lock changed but composer missing | commit `vendor/` to the repository or install composer on the server |
| Rollback FAILED | restore manually from Admin → Backups (files + database) then `php artisan optimize:clear` |
| Maintenance page stuck | `php artisan up` |
| Health: Storage critical | free disk space; prune backups/recordings; move recordings to S3 |
| Health: SSL warn | renew certificate: `certbot renew` |
| Health: Redis fail | only relevant when redis drivers configured; `systemctl status redis` |
| Recording missing | `record_enabled` on key or Settings → Streaming; supervisor logs; disk space |
| Session expired (419) on forms | cookie domain / `APP_URL` mismatch; `SESSION_SECURE_COOKIE=true` requires HTTPS |
| E-mails not sent | Settings → E-mail (SMTP); `MAIL_MAILER` in `.env`; queue running |

## "403 Forbidden – You don't have permission to access this resource" (Apache)

The domain's **Document Root points at the project root** instead of `public/`, so Apache finds no
index file. Three ways to fix it, best first:

1. **Set the document root to `public/`** (recommended, most secure)
   cPanel → *Domains* → the domain → *Manage* → Document Root → `.../rtmp/public` → Save.
   DirectAdmin/Plesk have the same setting; on a VPS edit the vhost (`scripts/apache/akstream.conf`).

2. **Use the shipped root `.htaccess` fallback** (already in the repository)
   It rewrites every request into `public/` and denies `app/`, `config/`, `storage/`, `.env`, etc.
   Requires `mod_rewrite` and `AllowOverride All` for the directory. Nothing else to configure —
   just make sure `.htaccess` and `index.php` from the repository root were uploaded (hidden files
   are easy to miss in FTP/File Manager: enable "show hidden files").

3. **Move `public/` contents into `public_html/`**
   Copy everything from `public/` into `public_html/`, then edit `public_html/index.php`:
   `require __DIR__.'/../rtmp/vendor/autoload.php';` and
   `$app = require_once __DIR__.'/../rtmp/bootstrap/app.php';`
   Add `public_html` to *System → Updates → Protected paths* so updates never overwrite it.

Other causes of 403 on a correct document root:
- Wrong permissions: directories must be `755`, files `644` (`chmod -R 755 rtmp`), owner = the hosting user (not root).
- `vendor/` missing → the app cannot boot. Run `composer install --no-dev --optimize-autoloader`, or upload `vendor/` from a machine that has composer.
- `storage/` and `bootstrap/cache/` must be writable (`chmod -R 775`).
- SELinux/ModSecurity on some hosts blocks `.htaccess` rewrites — ask the host to allow `AllowOverride All`.

## "Failed to open stream: vendor/autoload.php ... No such file or directory"

The PHP dependencies are not on the server. `vendor/` is intentionally **not** stored in git
(tens of thousands of files). Install it in one of these ways:

1. **On the server (best)** — SSH / aaPanel Terminal / cPanel Terminal:
   ```bash
   cd /www/wwwroot/rtmp.akdwk.in      # your application directory
   composer install --no-dev --optimize-autoloader
   ```
   No composer? `curl -sS https://getcomposer.org/installer | php && php composer.phar install --no-dev --optimize-autoloader`

2. **Upload a prepared `vendor/`** — build it on any machine with composer
   (`composer install --no-dev --optimize-autoloader`), zip the `vendor` folder, upload it next to
   `artisan` and extract. The folder must end up at `<app>/vendor/autoload.php`.

3. After installing dependencies, clear stale caches: `php artisan optimize:clear`
   (or delete `bootstrap/cache/*.php`).

Do **not** build `vendor/` with `--classmap-authoritative`: the application's own `App\` classes are
then not autoloadable if the classmap was generated without the app files present.

## `/install` shows a database connection error on a brand-new upload

Fixed in 1.0.0: before installation the application forces file-based session/cache drivers, and
`.env.example` ships with `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`.
The installer switches them to the database drivers once the database is configured and migrated.
If you copied an older `.env`, set those three values back to file/file/sync, delete
`storage/app/installed.lock` if present, and reload `/install`.

## Blank "HTTP ERROR 500" with no message

PHP is hiding the error (`display_errors=Off`). Open **`https://your-domain/diagnose.php`** — a
standalone page that does not boot the framework and reports PHP version, extensions, `vendor/`,
`.env`, `APP_KEY`, directory permissions, ownership, stale caches and the last log lines (secrets
removed). Fix every red row and reload.

Most common causes, in order:

1. **`.env` missing.** Git never stores it. Since 1.0.0 the application creates it from
   `.env.example` and generates `APP_KEY` on the first request (`App\Support\Preflight`), so this
   only remains an error when the application directory is not writable — `chmod 755 <app-dir>` and
   set the owner to the web-server user (`www` on aaPanel, `www-data` on Ubuntu, your cPanel user on cPanel).
2. **`storage/` or `bootstrap/cache/` not writable** → `chmod -R 775 storage bootstrap/cache`.
3. **Stale compiled caches** after changing dependencies → delete `bootstrap/cache/*.php`.
4. **`vendor/` missing or incomplete** → see the autoload section above.
5. Wrong PHP version selected for the domain (needs 8.2+).

To see the real message temporarily, set `APP_DEBUG=true` in `.env`, reload, then set it back to
`false` — never leave debug on in production.

## "Something went wrong. Reference ID: ERR-…" during setup

The application booted but a request failed. **Before installation** the page also shows the
exception class and the (secret-masked) message, plus a link to `diagnose.php` — fix what it names
and reload. **After installation** only the reference is shown; look it up in
*Admin → Logs → Errors* (or `storage/logs/laravel-*.log`).

## Site returns 500 right after running a script or uploading files as root

The files became owned by `root`, so PHP-FPM (running as `www`, `www-data`, `nginx`…) can no longer
write to `storage/` and `bootstrap/cache/`. Repair it with:

```bash
sudo bash scripts/fix-permissions.sh /path/to/app
```

It detects the web-server user from the running PHP-FPM/nginx/Apache processes, restores ownership
and permissions, clears the compiled caches and restarts the services. Force a user with
`WEB_USER=www sudo -E bash scripts/fix-permissions.sh`.

## systemd services say "NOT running" after install-mediamtx.sh

Usually the PHP binary is not on `PATH` (aaPanel keeps it at `/www/server/php/83/bin/php`) or the
unit runs as the wrong user. The installer now detects both; re-run it, or inspect:

```bash
systemctl status akstream-supervisor --no-pager -n 20
journalctl -u akstream-supervisor -n 40 --no-pager
```

Override detection when needed:
```bash
PHP_BIN=/www/server/php/83/bin/php WEB_USER=www sudo -E bash scripts/install-mediamtx.sh /path/to/app https://your-domain
```

## open_basedir: where does that line go?

It is a **PHP setting in the hosting panel**, not a shell command — pasting it into the terminal
gives "No such file or directory". In aaPanel: **Website → your domain → Config → PHP settings**
(or *Configuration file*), find `open_basedir` and append `:/usr/bin/:/usr/local/bin/:/tmp/`, then
restart PHP. Commenting the line out with `;` also works.

## Streaming Engine: "MediaMTX API not reachable" / RTMP port closed

MediaMTX exits immediately when its configuration contains an unknown key, so systemd reports the
unit as started and it is gone a second later. Check it directly:

```bash
sudo mediamtx /etc/mediamtx/mediamtx.yml     # prints the exact error and exits
sudo journalctl -u mediamtx -n 30 --no-pager
sudo ss -lntp | grep -E '1935|9997'
```

`ERR: json: unknown field "x"` means that key does not exist in your MediaMTX version — remove it.
(RTMPS is configured with `rtmpEncryption: "no"|"strict"|"optional"`, there is no `rtmps` key.)
`scripts/install-mediamtx.sh` now validates the rendered configuration before enabling the service.

For a single command that reports services, ports, engine API, configuration, logs, permissions,
cron and the Laravel health check in one go:

```bash
sudo bash scripts/doctor.sh /path/to/app
```

Its output masks stream keys, tokens and passwords, so it is safe to share.

## Queue: "Jobs are waiting > 10 min – is the worker running?"

Start the worker: `sudo systemctl enable --now akstream-queue`, or on shared hosting add the cron
line from *Admin → Cron Setup*. Without it, platform API calls (creating YouTube broadcasts and
Facebook live videos), notifications and updates never run.

## Streaming Engine: "Engine reachable but ffmpeg binary not found"

MediaMTX is running, but PHP cannot see `/usr/bin/ffmpeg` because the panel restricts
`open_basedir` to the website directory (aaPanel stores this in an immutable `<site>/.user.ini`).

```bash
sudo bash scripts/fix-open-basedir.sh /path/to/app
```

The script clears the immutable flag, appends `/usr/bin/:/usr/local/bin/:/bin/:/tmp/`, restores the
flag and reloads PHP-FPM. Manually: aaPanel → Website → Config → PHP settings → `open_basedir`.

Relays are started by the CLI worker (`stream:supervisor`), which usually has no such restriction,
so streaming can work while this warning is shown — but the panel cannot verify ffmpeg until it is fixed.

## "open_basedir restriction in effect" on a panel-managed host

aaPanel/cPanel confine PHP to the site directory, and `is_file()` on a path outside it does not
return false — it raises an error. Every filesystem probe in the application is therefore guarded
(`OverlayRenderer::readable()`, `App\Support\BinaryLocator`), and the overlay font ships inside
`resources/fonts/`, so overlays work without touching `open_basedir` at all.

`open_basedir` still matters for one thing: the panel cannot *verify* `/usr/bin/ffmpeg`, so the
Streaming Engine health check shows a warning. Relays run from the CLI worker, which is normally
unrestricted, so streaming itself is unaffected. To clear the warning:
`sudo bash scripts/fix-open-basedir.sh /path/to/app`.

## The overlay never appears in the live stream

The overlay is rendered into a second stream (`branded/<key>`) that the destinations copy. If the
media server configuration on disk predates the overlay feature it does not declare that path, the
encoder cannot publish, and the picture silently stays unbranded.

```bash
sudo bash scripts/doctor.sh /path/to/app     # "branded/ path: MISSING" says exactly this
sudo bash scripts/sync-from-github.sh /path/to/app
```

The sync script now re-renders `/etc/mediamtx/mediamtx.yml` from the shipped template whenever it
differs (keeping a timestamped backup, validating before replacing) and restarts the engine.

Also check, in that order:
1. `akstream-supervisor` is running — it is the process that renders overlays.
2. The stream key has an overlay applied (Overlays → *Which stream key uses which overlay*).
3. `Live Stream → Live preview` — untick *show source* to see the branded output.
4. The overlay has at least one enabled element.
