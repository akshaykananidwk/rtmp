# Production Readiness Report

Project: **AK COMPUTER – ONE LIVE EVERYWHERE** · Version 1.0.0 · Date: 17 Sep 2026
Environment used for verification: PHP 8.4 CLI, SQLite (file + memory), Laravel 12.69, PHPUnit 11 — **no real
media server, public network or platform accounts were available in the build environment**. Statuses below
distinguish what was actually executed from what could not be executed here.

Legend: **PASS** = automated test executed and green · **VERIFIED-SIMULATED** = executed end-to-end with a
faithful stand-in (fake ffmpeg process / faked official API responses) · **NOT TESTED HERE** = code complete,
requires a real VPS/platform account · **NOT SUPPORTED BY CURRENT OFFICIAL API** = intentionally not implemented.

## Automated test run

```
php artisan test
Tests: 62 passed (534 assertions)   Duration ≈ 16 s
```

| Feature | Status | Evidence |
|---|---|---|
| Authentication (login, lockout, 2FA, reset, logout, audit) | PASS | `tests/Feature/AuthTest.php` |
| Authorization (Super Admin / Admin / Operator / Viewer, no escalation) | PASS | `tests/Feature/AuthorizationTest.php` |
| Tenant isolation / IDOR | PASS | `tests/Security/TenantIsolationTest.php` |
| Security tests (SQLi, XSS, CSRF, path traversal, SSRF, upload, error page, secret masking, hook auth) | PASS | `tests/Security/SecurityHardeningTest.php`, `tests/Unit/SecretMaskerTest.php` |
| RTMP ingest (engine auth hook, key hashing, ready/not-ready lifecycle) | PASS (hooks) · NOT TESTED HERE (real MediaMTX) | `tests/Feature/StreamKeyAndEngineTest.php` |
| Streaming engine / multi-destination / destination failure / automatic retry / recovery | VERIFIED-SIMULATED (fake ffmpeg: one destination live, one failing → backoff → failed, others unaffected; restart; stop) | `tests/Feature/DistributionTest.php` |
| Recording (start, finalize, authorized download, delete) | VERIFIED-SIMULATED | `tests/Feature/DistributionTest.php` |
| YouTube integration (broadcast + stream + bind + transition + complete) | VERIFIED-SIMULATED (faked YouTube Data API v3 responses) · NOT TESTED HERE (live channel) | `tests/Feature/DestinationConnectorTest.php` |
| Facebook integration (page token, live_videos, end) | VERIFIED-SIMULATED (faked Graph API) · NOT TESTED HERE (live page, App Review) | same |
| Custom RTMP | PASS | same |
| Twitch | code complete, NOT TESTED HERE | — |
| LinkedIn Live | PARTIAL — custom RTMP only (API partner-only) | — |
| Instagram Live one-click | NOT SUPPORTED BY CURRENT OFFICIAL API (Live Producer RTMP supported) | — |
| Scheduling (create, timezone maths, auto-start, auto-stop, missed) | PASS | `tests/Feature/ScheduleTest.php` |
| Analytics | PASS (API + page render) | `ApiTest`, `AuthorizationTest` |
| Backup (files + DB, checksums, verify, encryption, prune, HTTP) | PASS | `tests/Feature/BackupTest.php` |
| Restore (DB + files; protected paths preserved; tampered archive refused) | PASS | same |
| GitHub update check (version, commit, author, date, file counts, protected skips) | PASS (faked GitHub API) | `tests/Feature/UpdateSystemTest.php` |
| Update install 1.0.0 → 1.0.1 (maintenance, backup, download, verify, install, migrate, cache, health, activate) | PASS – Workflow D | same |
| Protected files (`.env`, uploads never overwritten) | PASS | same |
| Migration during update | PASS | same |
| Health check (11 services, report, command, JSON) | PASS | `tests/Feature/HealthTest.php` |
| Automatic rollback on broken migration (files + DB restored, health re-run) | PASS – Workflow E | `UpdateSystemTest` |
| Backup failure aborts update / corrupt archive rejected / lock / interrupted recovery | PASS | same |
| One-click installer (requirements, DB test, app, admin, migrations, lock, reinstall prevention, login) | PASS – Workflow A (SQLite driver) · NOT TESTED HERE (Apache + MySQL host) | `tests/Feature/InstallerTest.php` |
| REST API v1 (tokens, abilities, CRUD, no key leakage) | PASS | `tests/Feature/ApiTest.php` |
| Webhooks (Meta HMAC, verify challenge, YouTube hub) | PASS | `tests/Feature/WebhookTest.php` |
| Unit tests (masking, TOTP RFC vector, protected paths, GitHub validation, state machine, env writer, key service) | PASS | `tests/Unit/*` |
| Deployment on a real Ubuntu VPS (Apache/MySQL/Redis/MediaMTX/systemd) | NOT TESTED HERE — scripts + documentation provided (`scripts/install-vps.sh`, `deploy.sh`) | — |

## Acceptance workflows

| Workflow | Result |
|---|---|
| A – Fresh install via `/install` (no SQL import, no `.env` editing) | PASS (automated, SQLite) |
| B – OBS → RTMP → engine → YouTube/Facebook/Custom RTMP actual delivery | NOT TESTED HERE: requires VPS + MediaMTX + platform accounts. Relay commands, hooks and platform API flows are implemented and unit/integration-tested with stand-ins. Must be verified on the target server before go-live (see checklist below). |
| C – Destination failure isolation | VERIFIED-SIMULATED (fake ffmpeg) |
| D – Automatic update | PASS |
| E – Failed update → rollback → previous version restored → health PASS | PASS |

## Go-live verification checklist (to be executed by AK COMPUTER on the production VPS)

1. `bash scripts/install-vps.sh`, deploy, `/install`, cron + systemd units running, Health page all green.
2. OBS → `rtmp://host/live/<key>` → dashboard shows *Incoming Stream Detected* with resolution/FPS (ffprobe).
3. Add a Custom RTMP destination pointing at a second MediaMTX path (`rtmp://127.0.0.1:1935/test/x`) → START LIVE → status *live*, HLS preview plays.
4. Connect YouTube (OAuth) → destination → START LIVE → YouTube Studio shows the broadcast live; STOP → broadcast completes.
5. Connect Facebook Page → same.
6. Unplug one destination key → confirm reconnect/backoff and the others keep running (Workflow C on real hardware).
7. Configure GitHub repo/token → Check for update → Update Now on a test commit → then a commit with a deliberately failing migration → rollback.

## Known limitations

- Viewer counts are shown only where the platform API returns them (Facebook `live_views`); YouTube concurrent viewers require the Analytics/Live API polling not enabled by default.
- Long-lived Facebook tokens (~60 days) require re-authentication; a notification is sent when a token expires.
- Instagram Live and LinkedIn Live are RTMP-only per official API availability at build time.
- In-place update strategy on shared hosting cannot run `composer install`; releases must ship `vendor/` when dependencies change (the updater warns).
- Post-update HTTP self-check (`/up`) is skipped when the server cannot reach its own `APP_URL` (warning only).
- QR code on the OBS page is not included (would expose the key in an image; copy buttons used instead).
- Sanctum tokens are not issued via `/auth/login` for 2FA-enabled accounts (create them from the profile page).
- `stream:supervisor` handles one media node per process; multi-node routing is data-model ready (`node_id`) but an RTMP load balancer is external.

## Required server specifications

| Deployment | Specification |
|---|---|
| Web app only (shared hosting) | PHP 8.2+, MySQL/MariaDB, 256 MB PHP memory, cron access |
| Streaming VPS (up to ~5 destinations, 1080p30) | 2 vCPU, 4 GB RAM, 40 GB SSD, 100 Mbit/s uplink (each destination ≈ source bitrate; 5 × 6 Mbit/s ≈ 30 Mbit/s), Ubuntu 22.04/24.04 |
| Streaming VPS (multiple simultaneous events / recording) | 4 vCPU, 8 GB RAM, 200 GB SSD or S3 for recordings, 500 Mbit/s uplink |
| Ports | 80, 443, 1935/tcp (RTMP), 8890/udp (SRT optional); 9997/8888 localhost only |
