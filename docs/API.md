# REST API v1

Base URL: `https://your-domain/api/v1` · Auth: `Authorization: Bearer <token>` (Laravel Sanctum) ·
Rate limit: 120 req/min (control endpoints 20/min) · All responses JSON.

Tokens are created in **Profile → API tokens** (abilities `read`, `control`, `manage`) or via `POST /auth/login`
(30-day token with abilities derived from the role; not available for 2FA accounts).

| Method | Endpoint | Ability / permission | Description |
|---|---|---|---|
| POST | `/auth/login` | – | `{email,password,device_name}` → `{token,abilities,expires_at}` |
| POST | `/auth/logout` | any | revoke current token |
| GET | `/auth/me` | any | current user |
| GET | `/health` | – | public liveness `{status,version}` |
| GET | `/system/health` | health.view | full health report |
| GET | `/dashboard` | dashboard.view | live payload (session, destinations, summary, counts) |
| GET | `/stream-status` | dashboard.view | compact live status |
| GET | `/analytics?days=7|30|90` | analytics.view | analytics overview |
| GET | `/streams` | streams.view | paginated sessions |
| GET | `/streams/{id}` | streams.view | session with destinations |
| GET | `/streams/{id}/logs` | streams.logs | session log lines |
| POST | `/streams/start` | control + streams.control | `{destination_ids?:[]}` start distribution of the detected source (409 if none) |
| POST | `/streams/stop` | control + streams.control | stop the live session |
| GET | `/destinations` | destinations.view | list (keys masked) |
| POST | `/destinations` | manage + destinations.manage | create (`platform,name,rtmp_url,stream_key,stream_endpoint_id,platform_account_id,opt_*`) |
| GET | `/destinations/{id}` | destinations.view | show |
| PUT | `/destinations/{id}` | manage | update (blank `stream_key` keeps the existing one) |
| DELETE | `/destinations/{id}` | manage | soft delete |
| POST | `/destinations/{id}/test` | destinations.test | run connector validation |
| GET | `/stream-keys` | stream_keys.view | endpoints (masked keys) |
| POST | `/stream-keys` | manage + stream_keys.manage | create; plaintext key returned **once** |
| GET | `/updates/check` | updates.view | check GitHub for updates |
| POST | `/updates/install` | manage + updates.manage | start update (202, `update_id`, `status_url`) |

### Example
```bash
TOKEN=$(curl -s -X POST https://stream.example.com/api/v1/auth/login -H 'Accept: application/json' \
  -d email=admin@example.com -d password='…' | jq -r .token)
curl -s https://stream.example.com/api/v1/stream-status -H "Authorization: Bearer $TOKEN"
curl -s -X POST https://stream.example.com/api/v1/streams/start -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

### Errors
`401` unauthenticated · `403` missing ability/permission · `404` not found (also for other tenants' resources) ·
`409` conflict (no active stream / update running) · `422` validation · `429` rate limited · `500` `{message, reference}`.

### Internal engine hooks (MediaMTX only, secret-protected)
`POST /api/internal/engine/auth|ready|not-ready` with header `X-Engine-Secret` — see STREAMING_SETUP.md.

### Webhooks
`GET|POST /webhooks/youtube`, `/webhooks/meta` (HMAC verified), `/webhooks/platform/{name}`.
