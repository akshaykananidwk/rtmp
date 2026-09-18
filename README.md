# AK COMPUTER – ONE LIVE EVERYWHERE

**Stream Once. Reach Everywhere.**
OBSમાંથી એક જ જગ્યાએ Live કરો અને connected platforms પર એકસાથે Live પહોંચાડો.

Production-ready multi-platform live-streaming SaaS: one RTMP stream from OBS / vMix / Streamlabs is
received by our own ingest server and distributed to YouTube, Facebook, Twitch, LinkedIn, Instagram
(Live Producer) and any custom RTMP destination — with scheduling, recording, analytics, notifications,
a one-click installer and a GitHub-based auto-update system with backup and automatic rollback.

```
OBS / vMix / Streamlabs ─▶ RTMP Ingest (MediaMTX) ─▶ Streaming Engine (FFmpeg relays)
                                    │
                            AK Computer Web App (Laravel 12)
                                    │
                 YouTube · Facebook · Twitch · LinkedIn · Instagram · Custom RTMP
```

| | |
|---|---|
| Brand | AK COMPUTER · Owner Akshay Kanani · ☎ 9978123146 |
| Address | 1st Floor, Shreeji Shopping Center, Near City Palace Hotel, Dwarka, Gujarat – 361335 · GST 24JHVPD9382M1ZA |
| Stack | PHP 8.3+, Laravel 12, MySQL 8 / MariaDB, Apache, Redis (optional), MediaMTX, FFmpeg |
| Version | `VERSION` file → 1.0.0 |

## Feature overview

- **Public website** with SEO metadata, sitemap, robots, schema.org.
- **Authentication**: login, remember-me, password reset, change password, TOTP 2FA + recovery codes, login throttling, audit trail.
- **Roles**: Super Admin, Admin, Operator, Viewer — enforced with Laravel Policies/Gates server-side.
- **Multi-tenant ready**: `TenantContext`, `TenantScope`, `BelongsToTenant`, policies and middleware; tenant-owned queries fail closed.
- **Stream keys** (`AKDWK-XXXX-…`): hashed for auth lookups, encrypted for display; create/regenerate/revoke/enable/disable; audited reveal/copy.
- **OBS Setup page** with server/key copy buttons and OBS/vMix/Streamlabs instructions.
- **Destinations** via a plugin `StreamingDestinationInterface`: Custom RTMP, YouTube (Data API v3), Facebook Pages (Graph Live Video API), Twitch (Helix), LinkedIn & Instagram (official RTMP tools, honestly labelled).
- **Multi-destination engine**: independent FFmpeg process per destination, exponential backoff (5 → 15 → 30 → 60 → 120 s), configurable retry count, one failure never stops the others.
- **Live control**: Start/Stop live, restart/stop destination, refresh, real-time logs, stream test page, manual/automatic distribution modes.
- **Scheduled streams** with timezone, destinations, auto-start/auto-stop, recording, thumbnails.
- **Recording** (MP4, local or S3-compatible), library with authorized download, retention.
- **Analytics**: daily streams, duration, bandwidth, destination success rate, errors.
- **Notifications**: e-mail, dashboard, optional WhatsApp (Meta Cloud API).
- **Backups**: files + database, checksums, verification, optional XChaCha20 encryption, download/restore/prune.
- **GitHub auto-update**: check → show version/commit/changed files → maintenance → backup → download → verify → install (atomic symlink release or in-place) → migrate → cache clear → health check → activate; **automatic rollback** on any failure; protected paths; update lock; interrupted-update recovery.
- **Health dashboard**: Application, Database, Cache, Redis, Queue, Storage, PHP, Streaming Engine, RTMP, SSL, GitHub.
- **One-click installer** (`/install`) with requirement checks, DB test, admin creation, migrations, lock file.
- **REST API** `/api/v1` (Sanctum tokens, abilities, rate limits) and signed **webhooks**.
- **Security**: CSRF, CSP + security headers, encrypted secrets, secret masking in logs/errors, rate limiting, IDOR-safe bindings, file upload validation, error reference IDs.

## Documentation

| Document | Content |
|---|---|
| **[docs/SETUP_GUIDE_GUJARATI.md](docs/SETUP_GUIDE_GUJARATI.md)** | **સંપૂર્ણ step-by-step setup guide (ગુજરાતી) — શરૂઆત અહીંથી કરો** |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Shared hosting + VPS installation, the `/install` wizard |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Ubuntu VPS: Apache, PHP, MariaDB, Redis, FFmpeg, MediaMTX, SSL, systemd, cron, firewall, monitoring |
| [docs/STREAMING_SETUP.md](docs/STREAMING_SETUP.md) | MediaMTX configuration, engine hooks, supervisor, scaling |
| [docs/OBS_SETUP.md](docs/OBS_SETUP.md) | Encoder settings for OBS, vMix, Streamlabs |
| [docs/PLATFORM_SETUP.md](docs/PLATFORM_SETUP.md) | YouTube / Meta / Twitch API apps, LinkedIn & Instagram RTMP |
| [docs/UPDATE_SYSTEM.md](docs/UPDATE_SYSTEM.md) | GitHub auto-update, protected paths, rollback, recovery |
| [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) | Backup manager, encryption, restore procedures |
| [docs/SECURITY.md](docs/SECURITY.md) | Security architecture and checklist |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common problems and fixes |
| [docs/API.md](docs/API.md) | REST API v1 reference |
| [docs/PRODUCTION_READINESS_REPORT.md](docs/PRODUCTION_READINESS_REPORT.md) | Test results, known limitations, server specifications |

## Quick start (development)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite   # or configure MySQL in .env
php artisan migrate --seed       # roles, protected paths, settings
php artisan db:seed --class=DemoSeeder   # local only: admin@example.com / ChangeMe!12345
php artisan serve
```

Or open `http://localhost:8000/install` for the guided installer.

If the site returns a blank 500 on a new server, open `/diagnose.php` — a standalone page that
reports PHP, extensions, `vendor/`, `.env`, permissions and ownership without booting the framework.

## Tests

```bash
php artisan test
```

62 tests / 534 assertions covering authentication, authorization, tenant isolation (IDOR), security
hardening (XSS, SQLi, CSRF, path traversal, SSRF, upload), stream keys + engine hooks, multi-destination
distribution with failure isolation (fake ffmpeg), recording, scheduling, backups (+encryption, restore),
health checks, the installer (Workflow A), the API, webhooks, platform connectors (fake official APIs) and the
auto-update system (Workflow D success and Workflow E broken migration → automatic rollback).

## Repository structure

```
app/Domain/        Streaming · Destinations · Scheduling · Recording · Analytics · Updates · Backups · Health · Installer · Tenancy · Security · Settings · Audit · Notifications · Storage
app/Http/          Controllers (Public, Auth, Admin, Api/V1, Installer, Webhooks, Internal) · Middleware · Requests
app/Jobs           Queue jobs (destination preparation, update runner, tests)
app/Console        stream:supervisor, stream:sync, health:check, backup:run, update:check/install/recover, logs:prune, user:make
config/akstream.php  Application configuration
database/          Migrations, seeders, factories
resources/views/   Blade UI (public, auth, admin, installer, errors)
public/assets/     Dark streaming UI CSS + vanilla JS (no build step required)
scripts/           VPS installer, deploy script, MediaMTX config, systemd units, Apache vhost
docs/              Documentation
tests/             Unit · Feature · Security
```

## License

Proprietary — © AK COMPUTER, Dwarka. All rights reserved.
