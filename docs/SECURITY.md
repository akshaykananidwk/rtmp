# Security

## Principles
Every important operation is **validated → authorized → logged → recoverable**. No client-side authorization is trusted.

## Implemented controls

| Area | Control |
|---|---|
| Transport | HTTPS enforced by Apache redirect; HSTS header; secure/HttpOnly/SameSite cookies (`SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`) |
| Headers | `SecurityHeaders` middleware: CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy |
| Authentication | bcrypt (12 rounds), strong password rules, login throttling (per e-mail+IP and per IP), account lock after N failures, TOTP 2FA with recovery codes, password reset without user enumeration, session regeneration |
| Authorization | Roles + permissions in DB, Policies for every model, `Gate::before` for super admin, role level hierarchy (no privilege escalation), Sanctum token abilities (`read/control/manage`) |
| Tenancy | `TenantScope` on all tenant-owned models fails closed; tenant derived only from the authenticated user; route bindings of foreign tenants resolve to 404 (IDOR-safe) |
| CSRF | Laravel CSRF on all web forms (webhooks/engine hooks excluded and separately authenticated) |
| Injection | Eloquent/prepared statements only; no shell commands from user input (`Symfony\Process` with argument arrays, fixed binaries) |
| XSS | Blade escaping everywhere; CSP; user content never rendered with `{!! !!}` |
| Secrets | Stream keys hashed (auth) + encrypted (display); platform tokens, GitHub token, SMTP/S3 secrets encrypted with `APP_KEY`; `SecretMasker` strips tokens/keys from logs, exceptions, audit properties and UI |
| Files | MIME + extension + size validation, randomised names, uploads stored outside `public` where possible, `.htaccess` disables PHP execution in `uploads/`, `storage` denied by Apache |
| Errors | Production shows `Something went wrong. Reference ID: ERR-…`; sanitized details in Admin → Logs → Errors |
| Rate limiting | login, password reset, API (120/min), API control (20/min), webhooks, engine hooks, installer, status polling |
| Installer | only before installation; lock file; input validation (host regex, sqlite path confined to `database/`); never echoes DB password; `/install` → 404 afterwards |
| Updates | GitHub API only (`api.github.com`), repository/branch/SHA validated, archive verified (gzip, tar, SHA match, no traversal), no arbitrary scripts executed, protected paths, lock, backup before change, rollback |
| Webhooks | Meta `X-Hub-Signature-256` HMAC verified; YouTube hub challenge + optional HMAC; payloads stored masked |
| Engine hooks | shared secret (`STREAM_ENGINE_SECRET`), rate limited; read access limited to private IPs |
| Audit | `activity_logs`: login, logout, failures, password changes, 2FA, keys created/regenerated/revoked/revealed, destinations, streams, settings, GitHub settings, updates, rollbacks, backups |
| Observability | request IDs on every request/response, dedicated log channels (streaming, updates, audit) with rotation |

## Checklist for go-live
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`
- [ ] `.env` permissions 640, owned by web user; never committed
- [ ] MySQL user limited to the app database
- [ ] MediaMTX API bound to localhost; firewall allows only 22/80/443/1935(/8890)
- [ ] Strong `STREAM_ENGINE_SECRET`
- [ ] 2FA enabled for all admins (Settings → Security → require)
- [ ] Optional admin IP allowlist
- [ ] GitHub token: fine-grained, Contents: Read only, single repository
- [ ] Backups encrypted + copied off-site; `APP_KEY` stored in a password manager
- [ ] Health checks green; SSL renewal via certbot timer

## Reporting
Security issues: contact AK COMPUTER, Akshay Kanani, 9978123146.
