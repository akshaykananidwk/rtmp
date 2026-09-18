# Streaming Server Setup (MediaMTX + FFmpeg)

## Architecture

```
OBS ──rtmp://HOST/live/<KEY>──▶ MediaMTX (:1935)
                                   │ authHTTP  → POST /api/internal/engine/auth   (key validated by hash)
                                   │ runOnReady → POST /api/internal/engine/ready  (session created, "Incoming stream detected")
                                   │ runOnNotReady → POST /api/internal/engine/not-ready (session ended)
                                   ▼
                     php artisan stream:supervisor  (media node daemon)
                         ├─ ffmpeg -i rtmp://127.0.0.1:1935/live/<KEY> -c copy -f flv rtmp://a.rtmp.youtube.com/live2/<yt-key>
                         ├─ ffmpeg … -f flv rtmps://live-api-s.facebook.com:443/rtmp/<fb-key>
                         ├─ ffmpeg … -f flv rtmp://custom/app/<key>
                         └─ ffmpeg … -f mp4 storage/app/recordings/<tenant>/<date>.mp4   (recording)
```

- The PHP application never touches RTMP bytes; it controls the engine via its HTTP API and the supervisor.
- Desired state lives in the database (`stream_session_destinations.desired_state`) so any web server can
  request start/stop and the media node executes it — no coupling to a PHP request process.
- Every destination is an independent FFmpeg process. A failing destination is retried with exponential
  backoff (`config/akstream.php → streaming.backoff`, default 5/15/30/60/120 s) up to *Settings → Streaming → Max retries*;
  the others are never interrupted.

## MediaMTX configuration

Template: `scripts/mediamtx/mediamtx.yml`. Replace `__APP_URL__` and `__ENGINE_SECRET__` (= `STREAM_ENGINE_SECRET` in `.env`).

Key points:

| Setting | Why |
|---|---|
| `api: yes`, `apiAddress: 127.0.0.1:9997` | control API for path listing, stats and kicking publishers – localhost only |
| `authMethod: http` + `authHTTPAddress` | every publish is authorised by the app (`live/<KEY>` → key hash lookup); reads only from local/private IPs |
| `runOnReady` / `runOnNotReady` | session lifecycle hooks (fallback: `stream:sync` runs every minute) |
| `hlsAddress: 127.0.0.1:8888` | optional preview; expose through Apache `ProxyPass /hls/` if needed |
| `srt: yes` | encoders can also use SRT: `srt://HOST:8890?streamid=publish:live/<KEY>` |

## Supervisor

```bash
php artisan stream:supervisor            # long-running (systemd unit provided)
php artisan stream:supervisor --once     # single reconciliation pass (debug)
php artisan stream:sync                  # reconcile DB sessions with engine paths
```

State machine per destination: `pending → connecting → live → (reconnecting)* → failed | stopped`.
Platform destinations (YouTube/Facebook with OAuth) go through `preparing` first while the queue job creates
the broadcast/live video via the official API; then the relay starts.

## Stats

The supervisor polls `GET /v3/paths/list` every 5 s: incoming bitrate (bytes delta), outgoing bitrate (sum of
relay `-progress` output), duration; `ffprobe` is used once per session for resolution/FPS/codecs.

## Ports

| Port | Protocol | Purpose |
|---|---|---|
| 1935/tcp | RTMP | encoder ingest (public) |
| 8890/udp | SRT | optional ingest (public) |
| 9997/tcp | HTTP | MediaMTX API (localhost only) |
| 8888/tcp | HTTP | HLS (localhost / proxied) |

## Multiple media nodes

Run MediaMTX + supervisor on each node with a unique `STREAM_NODE_ID`; the `runOnReady` hook passes `node=<id>`
so relays are executed where the source arrives. Sessions on other nodes are ignored by a node's supervisor.

## Shared hosting

The web app works without an engine (`STREAM_ENGINE=none`): dashboard, destinations, schedules, backups and updates
function; the Health page shows the engine as "not configured". Point `STREAM_SERVER_API_URL` to a VPS media
node to combine both.

## Overlays and the branded stream

When a stream key has an overlay attached, the supervisor runs one extra FFmpeg process:

```
OBS ─▶ live/<KEY> ──▶ [overlay encode: scale + drawbox + drawtext + logo] ──▶ branded/<KEY>
                                                                                   │
                                        relays (-c copy) ─────────────────────────┴─▶ YouTube / Facebook / …
                                        recording (-c copy) ───────────────────────┘
```

- Exactly **one** re-encode regardless of how many destinations there are; every relay still copies.
- The branded path is published from localhost only — `EngineHookController` accepts `branded/<key>`
  publishes solely from private addresses, and `scripts/mediamtx/mediamtx.yml` declares `~^branded/.+$`.
- Static text is passed through `drawtext textfile=…:reload=1`, so saving new wording in the admin
  panel changes the picture within about a second without restarting the encoder — and user text is
  never interpolated into the filter graph, which rules out filter-injection.
- The clock uses `%{localtime}`; FFmpeg parses that string three times, so the colons carry three
  backslashes (verified against FFmpeg 6.1 — fewer produce "requires at most 1 arguments").
- `drawbox` has no `tw`/`th`, and `overlay` uses `W/H` for the frame and `w/h` for the logo, so each
  element type gets its own position expressions. `tests/Feature/OverlayRenderingTest.php` renders
  every element and every position with the real binary and counts drawn pixels.
- Text needs a TrueType font (`fonts-dejavu-core`); without one, text elements are skipped rather
  than crashing the encoder, and the panel says so.

CPU guidance: 1080p30 at `veryfast` is roughly one core. Drop to 720p or `ultrafast` on small VPSes.
Without an overlay nothing is re-encoded at all.

## Live preview

`/admin/preview/{endpoint}/index.m3u8` proxies MediaMTX's HLS output for authorised users, rewriting
segment URLs so the browser never contacts the media server and never sees the stream key. The player
(hls.js, served locally to satisfy the CSP) shows the branded output when an overlay is live, with a
toggle for the raw source. `hlsVariant: mpegts` keeps the playlist simple to proxy.
