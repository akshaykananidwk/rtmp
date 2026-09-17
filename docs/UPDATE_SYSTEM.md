# GitHub Auto-Update System

After the first configuration **no manual file upload is ever required again**.

## Configuration (System → Updates)

| Field | Notes |
|---|---|
| Repository | `owner/repo` (GitHub only; full URLs are normalised, anything else rejected → no SSRF) |
| Branch | e.g. `main` (validated) |
| Personal Access Token | fine-grained token with **Contents: Read** only. Stored encrypted (`system_settings`), masked in UI, never logged (`SecretMasker`), never sent to the browser |

Saving verifies repository access via `GET /repos/{owner}/{repo}`.

## Check for update (information only)

`GET /repos/{r}/branches/{branch}` (head commit) → `VERSION` at that commit → `GET /compare/{current}...{head}` (file list)
→ optional latest release notes. Shows: current/latest version, commit SHA, message, author, date, changed /
added / modified / deleted files, protected files that will be skipped, estimated size, release notes.
Nothing is downloaded. The current commit comes from the `COMMIT` file written at deploy/update time.

## Update Now – transaction & state machine

```
IDLE → CHECKING → BACKING_UP → DOWNLOADING → VERIFYING → INSTALLING → MIGRATING → HEALTH_CHECK → ACTIVATING → COMPLETED
                                                                 └───────── any failure ─────────▶ ROLLING_BACK → ROLLED_BACK
                                    (failures before INSTALLING → FAILED, nothing was changed)
```

| Step | What happens |
|---|---|
| Lock | `Cache::lock('system-update')` + `storage/framework/update.lock` (JSON with update id/pid) — only one update at a time |
| Maintenance | `php artisan down --secret=<random>`; the bypass URL is written to the update log so admins keep access |
| Backup | **Full files + database backup, verified by checksum**. Backup failure ⇒ update aborted, nothing changed |
| Download | `GET /repos/{r}/tarball/{sha}` streamed to `storage/app/releases/download-<id>.tar.gz` |
| Verify | gzip magic, tar readable, no unsafe paths, top-level dir must contain the commit short SHA, sha256 recorded |
| Install | Extract to a release dir; `COMMIT` written; dependency check (`composer install --no-dev` when `composer.lock` changed and composer exists; otherwise the release must ship `vendor/`); `.env.example` compared with `.env` → missing keys are logged and shown as warning (never written) |
| Migrate | `php artisan migrate --force` executed **in a fresh PHP process against the new code** (in-process fallback when `proc_open` is disabled) |
| Cache | `config:clear cache:clear route:clear view:clear event:clear` + `opcache_reset()` + `optimize` in production |
| Health | all health checks on the new code; a failing **critical** check (Application, Database, Cache, Storage, PHP) triggers rollback |
| Activate | symlink strategy: atomic `current` switch; in-place strategy: files were synced before migration |
| Done | maintenance off, version + duration recorded, admins notified, old releases pruned (keep 3) |

### Strategies

- **symlink** (VPS, `current` is a symlink): staged release directory, shared `.env`/`storage`/`public/uploads`, atomic switch. Rollback = switch back to the previous release directory.
- **inplace** (shared hosting): staged under `storage/app/releases`, then copied file-by-file (temp + rename) over the live tree, never touching protected paths, deleting files removed upstream. Rollback = restore the verified files backup + remove files added by the update.

`UPDATE_STRATEGY=auto|symlink|inplace` in `.env`.

### Protected paths
Defaults (`config/akstream.php`): `.env`, `.env.*` (except `.env.example`), `config.php`, `uploads/`, `storage/`, `public/uploads/`,
`user_uploads/`, `storage/app/`, `backup/`, `public/storage`, `vendor/`, `node_modules/`, `database/database.sqlite`, `installed.lock`.
Admins can add more (System → Updates → Protected paths). Patterns: exact file, `dir/`, glob. Traversal is rejected.

### Automatic rollback
1. Restore previous release (symlink) or files backup (in-place) — protected configuration is never touched
2. Restore database from the update backup **only if migrations ran**
3. Clear caches, run health checks, log the report
4. State `ROLLED_BACK` (or `FAILED` if the rollback itself hit an error — manual intervention, see Troubleshooting)
5. Maintenance off, admins alerted

The update's own row and log lines are preserved across the database restore.

### Interrupted updates
If PHP dies mid-update, the lock file remains. `php artisan update:recover` (or *Run recovery* in the UI, also
attempted automatically) detects that the recorded PID is dead / the update is stale (> 30 min), rolls back if
files or DB may have been touched, marks the update FAILED/ROLLED_BACK, disables maintenance and releases the lock.

### Manual rollback
A completed update can be rolled back from its detail page (type `ROLLBACK`).

## Update history
Version, previous version, commit, date, admin, status, backup, migration, health, rollback, duration + detailed log per update.

## CLI
```
php artisan update:check [--notify]
php artisan update:install [--sha=…]
php artisan update:recover
```

## Versioning
`VERSION` file (semver) is the application version; tag releases in GitHub with the same version so release
notes are shown. `COMMIT` records the deployed SHA.

## Requirements on the server
- PHP `zip`, `Phar` (default), write access to the app directory (in-place) or the releases parent (symlink)
- Outbound HTTPS to `api.github.com` (and `codeload.github.com` redirect)
- Enough disk for one extra release + one backup
