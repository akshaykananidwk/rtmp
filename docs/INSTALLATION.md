# Installation

## Requirements

| Component | Minimum |
|---|---|
| PHP | 8.2 (8.3 recommended) with pdo, pdo_mysql, openssl, curl, json, mbstring, tokenizer, xml, ctype, fileinfo, zip; recommended: redis, gd, intl, sodium, pcntl, posix, opcache |
| Database | MySQL 8.0+ / MariaDB 10.6+ (PostgreSQL and SQLite also work for the web app) |
| Web server | Apache 2.4 with mod_rewrite (document root = `public/`) |
| Composer | 2.x (only needed if `vendor/` is not shipped) |
| VPS-only | FFmpeg 5+, MediaMTX 1.9+, Redis (recommended), root access for systemd |

**Shared hosting** runs the complete web application (dashboard, destinations, scheduling, backups, updates,
API). **RTMP ingest, FFmpeg relays and recording require a VPS** — see `docs/DEPLOYMENT.md`. The two can
be split: web app on shared hosting + media node on a VPS (set `STREAM_SERVER_API_URL`, `STREAM_INTERNAL_RTMP_URL`
to the VPS and run `stream:supervisor` there against the same database).

## A. Shared hosting (cPanel / Plesk)

1. Upload the release archive (with `vendor/`) into a folder, e.g. `~/akstream`.
2. Point the domain / subdomain document root to `~/akstream/public`
   (or move the contents of `public/` into `public_html` and adjust `index.php` paths — see cPanel notes below).
3. Create a MySQL database + user in cPanel.
4. Open `https://your-domain/install` and complete the 6 steps:
   1. **System requirements** – PHP, extensions, writable `storage/`, `bootstrap/cache/`, `.env`
   2. **Database** – host, port, name, user, password → *Test Database Connection*
   3. **Application** – name, URL, timezone, admin e-mail, public RTMP URL
   4. **Admin account** – strong password (10+ chars, mixed case, number, symbol)
   5. **Install** – migrations, roles, settings, admin user, storage directories, `APP_KEY` generation
   6. **Complete** – installer is locked (`storage/app/installed.lock`), `/install` returns 404
5. Add the cron job (cPanel → Cron Jobs, every minute):
   ```
   * * * * * cd /home/USER/akstream && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
   * * * * * cd /home/USER/akstream && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
   ```
6. Log in at `/admin`, configure **Settings → Streaming** (RTMP host of your media VPS) and **System → Updates**.

### cPanel `public_html` layout
If you cannot change the document root, upload the app to `~/akstream` and copy `public/*` to `~/public_html`,
then edit `public_html/index.php`: replace `__DIR__.'/../vendor/autoload.php'` with `__DIR__.'/../akstream/vendor/autoload.php'`
and `__DIR__.'/../bootstrap/app.php'` with `__DIR__.'/../akstream/bootstrap/app.php'`.
Add `public_html` to the protected paths in **System → Updates** so updates never overwrite your edited `index.php`
(or better: keep the standard layout and use a subdomain document root).

## B. VPS (recommended for streaming)

```bash
git clone https://github.com/OWNER/REPO.git /tmp/akstream
sudo bash /tmp/akstream/scripts/install-vps.sh stream.example.com
sudo -u www-data bash /tmp/akstream/scripts/deploy.sh https://github.com/OWNER/REPO.git main
```

Then open `https://stream.example.com/install`. Full details in `docs/DEPLOYMENT.md`.

## C. Manual / developer installation

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force --seed
php artisan user:make admin@example.com --name="Akshay Kanani" --password='Str0ng!Password#2026'
echo '{"installed_at":"now"}' > storage/app/installed.lock
```

## After installation

| Task | Where |
|---|---|
| Public RTMP URL shown in OBS | Settings → Streaming → RTMP host |
| Engine API / internal RTMP / node id / ffmpeg path | `.env` (`STREAM_SERVER_API_URL`, `STREAM_INTERNAL_RTMP_URL`, `STREAM_NODE_ID`, `FFMPEG_BINARY`) |
| Platform API credentials | Settings → Platforms |
| SMTP | Settings → E-mail |
| GitHub auto-update | System → Updates |
| Cron / systemd | Admin → Cron Setup |

## Upgrading `.env` after updates
Updates never modify `.env`. New keys introduced by a release are detected and shown as a warning on the
Updates page; safe defaults are used until you add them.
