# Platform Integration Guide

All integrations use **official APIs only**. Where a platform provides no public Live API, the connector is a
labelled RTMP destination and the UI says **NOT SUPPORTED BY CURRENT OFFICIAL API**. Access tokens are stored
encrypted (`platform_tokens`) and never displayed.

## YouTube Live (YouTube Data API v3) – SUPPORTED

1. Google Cloud Console → create project → enable **YouTube Data API v3**.
2. OAuth consent screen (External; add test users until verified) → scopes `youtube`, `youtube.force-ssl`.
3. Credentials → OAuth client ID (Web) → Authorized redirect URI: `https://your-domain/admin/platforms/youtube/callback`.
4. Admin → Settings → Platforms → YouTube client ID/secret.
5. Admin → Destinations → **Connect YouTube** → authorize.
6. Add destination *YouTube Live*: title, description, privacy, category, optional existing broadcast ID, scheduled start.

Per session the connector: `liveBroadcasts.insert` (or reuses your broadcast) → `liveStreams.insert` (reusable RTMP
stream, cached) → `liveBroadcasts.bind` → relay pushes to the ingestion address → `liveBroadcasts.transition(live)`
(`enableAutoStart` is also set) → on stop `transition(complete)` for auto-created broadcasts.
Requirement: the channel must have live streaming enabled (phone verified, 24 h wait).

Fallback without OAuth: paste the manual stream key from YouTube Studio (`rtmp://a.rtmp.youtube.com/live2`).

## Facebook Page Live (Meta Graph API, Live Video API) – SUPPORTED (Pages only)

1. developers.facebook.com → create app (Business) → add **Facebook Login**.
2. Valid OAuth redirect URI: `https://your-domain/admin/platforms/facebook/callback`.
3. Permissions: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `publish_video` (App Review required for production use by other users; app admins/testers can use them in development mode).
4. Settings → Platforms → App ID / App secret / Webhook verify token; webhook URL `https://your-domain/webhooks/meta` (signature verified with `X-Hub-Signature-256`).
5. Destinations → **Connect Facebook** → select Page in the destination form.

Per session: `POST /{page-id}/live_videos` (status LIVE_NOW) → `secure_stream_url` (RTMPS, contains the key) → relay
→ `POST /{id}?end_live_video=true` on stop. Long-lived user tokens (~60 days) cannot be refreshed silently; the
account status becomes *expired* and admins reconnect.

**Not supported by the current official API**: personal profile Live, Groups, Instagram Live creation.

## Twitch (Helix) – SUPPORTED

Twitch Developer Console → app → redirect `https://your-domain/admin/platforms/twitch/callback` → client ID/secret
in Settings → Platforms. With OAuth (`channel:read:stream_key`) the stream key is fetched automatically; otherwise
paste it from the Creator Dashboard. Ingest default `rtmp://live.twitch.tv/app` (choose nearest from Twitch ingest list).

## LinkedIn Live – PARTIAL (custom RTMP)

The LinkedIn Live API is limited to approved partners. Create the LinkedIn Live event, choose **Custom stream (RTMP)**
and paste the RTMP URL + key into a *LinkedIn Live (Custom RTMP)* destination.

## Instagram – NOT SUPPORTED BY CURRENT OFFICIAL API for one-click Live

Eligible professional accounts can use **Live Producer** (instagram.com → Create → Live → Live Producer) which
provides an RTMPS URL + key; paste them into an *Instagram (Live Producer RTMP)* destination. This is the only
officially supported path today; the application does not scrape or automate the Instagram UI.

## Custom RTMP – SUPPORTED

Any `rtmp://` or `rtmps://` server + stream key: Vimeo, Dailymotion, Kick, Restream, private servers, a second
MediaMTX, etc.

## Adding a new platform (developer)

1. Create `app/Domain/Destinations/Connectors/<Name>Connector.php` implementing `StreamingDestinationInterface`
   (extend `AbstractConnector`; implement `platform()`, `definition()`, `validate()`, `rtmpTarget()`; optionally
   `requiresPreparation()`, `startStream()`, `stopStream()`, `onRelayLive()`, `getStatus()`).
2. Register it in `ConnectorRegistry::__construct()`.
3. Add an OAuth service in `app/Domain/Destinations/OAuth/` and a case in `OAuthController::service()` if OAuth is used.

No change to the streaming engine is required.
