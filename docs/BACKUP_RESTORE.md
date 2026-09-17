# Backup & Restore

## What is backed up
- **Files**: the application directory (excluding `vendor`, `node_modules`, backups, staged releases, cache, logs, `.git`) as ZIP with `BACKUP_META.json` (version, commit, timestamp).
- **Database**: `mysqldump` (single-transaction, routines, triggers) when available, otherwise a pure-PHP dumper (works on shared hosting); SQLite databases are copied; PostgreSQL via `pg_dump`.
- Each artefact has a SHA-256 checksum; the backup is marked *verified* only when the archive passes `ZipArchive::CHECKCONS`.

## Encryption
Settings → Backups → *Encrypt backups*: files are encrypted with libsodium XChaCha20-Poly1305 secretstream.
The key is derived from `APP_KEY` (or a dedicated `backups.encryption_key` setting). **Losing `APP_KEY` makes encrypted backups unreadable — keep `.env` backed up separately.**

## Manual backup
Admin → Backups → type (full / database / files) → *Create backup now*, or `php artisan backup:run --type=full`.

## Automatic backup
Daily at 02:30 (Settings → Backups; scheduler must run). Retention: expired backups are pruned but the newest 3 are always kept. Before every update a mandatory full backup is created and verified.

## Download
Files and database parts can be downloaded by users with `backups.manage` (audited). Copy them off-site regularly.

## Restore
Admin → Backups → *Restore DB* / *Restore files* (type `RESTORE`) — requires `backups.restore` (Super Admin by default).

- Database restore overwrites the current database from the dump (checksum re-verified first; encrypted dumps are decrypted to a temp file).
- Files restore writes archive contents over the application, **never overwriting protected paths** (`.env`, uploads, storage…). Added files that are not in the archive are left in place.
- After restoring, run `php artisan optimize:clear` and check Admin → Health.

### CLI restore (emergency)
```bash
php artisan tinker
>>> $b = App\Models\Backup::find('01…');
>>> app(App\Domain\Backups\BackupService::class)->restoreDatabase($b);
>>> app(App\Domain\Backups\BackupService::class)->restoreFiles($b);
```
Unencrypted dumps can also be imported with `mysql ak_stream < db-…sql`.

## Location
`storage/app/backups/` (protected by `.htaccess` and outside `public/`). Configure `BACKUP_DISK`/retention in Settings.
