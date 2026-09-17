# Production Deployment – Ubuntu VPS

Target: Ubuntu 22.04 / 24.04, 2 vCPU, 4 GB RAM, 40 GB SSD (see server specifications in the readiness report).

## 1. Bootstrap

```bash
git clone https://github.com/OWNER/REPO.git /tmp/akstream
sudo bash /tmp/akstream/scripts/install-vps.sh stream.example.com
```

The script installs Apache, PHP 8.3 (+ extensions), MariaDB, Redis, FFmpeg, certbot, Composer, MediaMTX,
opens firewall ports (22, 80, 443, 1935/tcp, 8890/udp), creates `/var/www/akstream/{releases,shared,current}`,
installs the Apache vhost, systemd units and the cron line.

## 2. Database

```bash
sudo mariadb
CREATE DATABASE ak_stream CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ak_stream'@'localhost' IDENTIFIED BY 'STRONG-PASSWORD';
GRANT ALL ON ak_stream.* TO 'ak_stream'@'localhost'; FLUSH PRIVILEGES;
```

## 3. Deploy the code (atomic release layout)

```bash
sudo -u www-data bash /tmp/akstream/scripts/deploy.sh https://github.com/OWNER/REPO.git main
```

Layout:

```
/var/www/akstream/
  releases/20260918120000-manual/   ← full app copy (COMMIT file records the git SHA)
  shared/.env                       ← protected configuration
  shared/storage/                   ← logs, backups, recordings, releases staging, sessions
  shared/public/uploads/            ← user uploads
  current -> releases/2026...       ← Apache DocumentRoot = current/public
```

The updater detects that `current` is a symlink and uses the **symlink strategy**: new releases are staged
side-by-side, migrated, health-checked and switched atomically; rollback = switch back.

## 4. SSL

```bash
sudo certbot --apache -d stream.example.com
```

## 5. Installer

Open `https://stream.example.com/install`. Use `rtmp://stream.example.com/live` as the public RTMP URL.

## 6. Streaming engine

```bash
sudo cp /var/www/akstream/current/scripts/mediamtx/mediamtx.yml /etc/mediamtx/mediamtx.yml
sudo sed -i "s#__APP_URL__#https://stream.example.com#g" /etc/mediamtx/mediamtx.yml
sudo sed -i "s#__ENGINE_SECRET__#$(grep STREAM_ENGINE_SECRET /var/www/akstream/shared/.env | cut -d= -f2)#g" /etc/mediamtx/mediamtx.yml
sudo systemctl restart mediamtx
```

Ensure `.env` on the VPS contains:

```
STREAM_ENGINE=mediamtx
STREAM_SERVER_URL=rtmp://stream.example.com/live
STREAM_SERVER_API_URL=http://127.0.0.1:9997
STREAM_INTERNAL_RTMP_URL=rtmp://127.0.0.1:1935/live
STREAM_HLS_URL=http://127.0.0.1:8888
STREAM_NODE_ID=media-1
FFMPEG_BINARY=/usr/bin/ffmpeg
FFPROBE_BINARY=/usr/bin/ffprobe
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

## 7. Services

```bash
sudo systemctl enable --now mediamtx akstream-supervisor akstream-queue
sudo systemctl status akstream-supervisor
journalctl -u akstream-supervisor -f
```

| Service | Purpose |
|---|---|
| `mediamtx` | RTMP/SRT ingest, HLS preview, control API |
| `akstream-supervisor` | `php artisan stream:supervisor` – FFmpeg relays, recording, stats (one per media node) |
| `akstream-queue` | `php artisan queue:work` – notifications, platform API calls, updates, backups |
| cron `schedule:run` | scheduled streams, health checks, backups, retention, update checks |

## 8. Permissions

```bash
sudo chown -R www-data:www-data /var/www/akstream
sudo find /var/www/akstream/shared/storage -type d -exec chmod 750 {} \;
sudo chmod 640 /var/www/akstream/shared/.env
```

## 9. PHP production settings (`/etc/php/8.3/apache2/php.ini`)

```
opcache.enable=1
opcache.validate_timestamps=1      ; the updater calls opcache_reset(); keep 1 unless you restart Apache on deploy
upload_max_filesize=8M
post_max_size=8M
memory_limit=512M
max_execution_time=300
expose_php=Off
```

## 10. Monitoring & alerts

- Admin → Health (auto every 10 min; alerts on failures)
- Disk usage warnings at 80 % / critical at 90 %
- `journalctl -u akstream-supervisor`, `storage/logs/streaming-*.log`, `updates-*.log`, `laravel-*.log` (daily rotation)
- Optional: point an uptime monitor at `https://stream.example.com/up` and `/api/v1/health`

## 11. Backups

Automatic daily backups (Settings → Backups) are stored in `shared/storage/app/backups`. Copy them off-site
(rsync/S3 lifecycle) — see `docs/BACKUP_RESTORE.md`.

## 12. Scaling out

- **App servers**: put N app servers behind a load balancer; use Redis for cache/session/queue and a shared MySQL. Only the media node runs `stream:supervisor`.
- **Media nodes**: each node runs MediaMTX + `stream:supervisor` with its own `STREAM_NODE_ID`; sessions record the `node_id` reported by the `runOnReady` hook and relays run on that node. Use an RTMP load balancer / DNS per event to route encoders.
- **Recordings**: set Settings → Storage → S3 and the retention job moves finished recordings to object storage.
