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
| Destination stuck in *connecting* / *reconnecting* | Live Logs show the ffmpeg error; verify platform key/URL, test destination; `journalctl -u akstream-supervisor` |
| All destinations *pending*, nothing happens | supervisor not running: `systemctl start akstream-supervisor` (Health → Queue/Engine) |
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
