#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE — one-shot server diagnosis
#
#   sudo bash scripts/doctor.sh [app-directory]
#
# Prints everything needed to debug the streaming stack. Secrets are masked,
# so the output is safe to share.
###############################################################################
set -uo pipefail
APP_DIR="${1:-$PWD}"
cd "$APP_DIR" 2>/dev/null || { echo "No such directory: $APP_DIR"; exit 1; }
# shellcheck source=/dev/null
[[ -f scripts/lib-detect.sh ]] && source scripts/lib-detect.sh
PHPBIN="$(detect_php 2>/dev/null || echo php)"
WEBU="$(detect_web_user 2>/dev/null || echo '?')"
mask() { sed -E 's#(rtmps?://[^/]+/[^/]+/)[^[:space:]"]+#\1***#g; s#(SECRET|TOKEN|PASSWORD|KEY)=.*#\1=***#g; s#(secret=)[^&[:space:]]+#\1***#g'; }
hr() { printf '\n──────── %s ────────\n' "$1"; }

hr "SYSTEM"
echo "app dir : $APP_DIR"
echo "php     : $PHPBIN ($("$PHPBIN" -r 'echo PHP_VERSION;' 2>/dev/null))"
echo "web user: $WEBU"
echo "version : $(cat VERSION 2>/dev/null || echo '?')  commit: $(cut -c1-7 COMMIT 2>/dev/null || echo '?')"
echo "installed: $([[ -f storage/app/installed.lock ]] && echo yes || echo NO)"

hr "SERVICES"
for unit in mediamtx akstream-supervisor akstream-queue; do
  state="$(systemctl is-active "$unit" 2>/dev/null || echo 'not-installed')"
  printf '%-22s %s\n' "$unit" "$state"
done

hr "PORTS (1935 RTMP · 9997 engine API · 8888 HLS)"
( ss -lntup 2>/dev/null || netstat -lntup 2>/dev/null ) | grep -E ':(1935|9997|8888|8890)\b' || echo "none of these ports are listening"

hr "ENGINE API"
curl -s -m 5 -o /tmp/.ak_api -w 'GET /v3/paths/list -> HTTP %{http_code}\n' http://127.0.0.1:9997/v3/paths/list 2>/dev/null || echo "curl failed"
head -c 300 /tmp/.ak_api 2>/dev/null; echo; rm -f /tmp/.ak_api

hr "MEDIAMTX CONFIG CHECK"
if command -v mediamtx >/dev/null && [[ -f /etc/mediamtx/mediamtx.yml ]]; then
  grep -nE '^(rtmp|api|authMethod|authHTTPAddress|hls)' /etc/mediamtx/mediamtx.yml | mask
  echo "-- validation --"
  timeout 5 mediamtx /etc/mediamtx/mediamtx.yml 2>&1 | head -5 | mask
else
  echo "mediamtx binary or /etc/mediamtx/mediamtx.yml missing"
fi

hr "ENGINE CONFIG UP TO DATE?"
if [[ -f /etc/mediamtx/mediamtx.yml && -f scripts/mediamtx/mediamtx.yml ]]; then
  for path in branded live; do
    grep -q "\^$path/" /etc/mediamtx/mediamtx.yml && echo "  $path/ path: present" || echo "  $path/ path: MISSING – re-run scripts/sync-from-github.sh or install-mediamtx.sh"
  done
else
  echo "  /etc/mediamtx/mediamtx.yml not found"
fi

hr "OVERLAY STATE"
sudo -u "$WEBU" "$PHPBIN" artisan tinker --execute='
$s = App\Models\StreamSession::withoutGlobalScopes()->whereIn("status", ["detected","live"])->latest("started_at")->first();
echo "active session : ", $s?->id ?: "none", "\n";
echo "overlay        : ", $s?->overlay_id ?: "none", "\n";
echo "branding status: ", $s?->branding_status ?: "-", "\n";
echo "overlays       : ", App\Models\Overlay::withoutGlobalScopes()->count(), "\n";
echo "keys w/overlay : ", App\Models\StreamEndpoint::withoutGlobalScopes()->whereNotNull("overlay_id")->count(), "\n";' 2>/dev/null | tail -6

hr "DISTRIBUTION STATE"
sudo -u "$WEBU" "$PHPBIN" artisan tinker --execute='
$st = app(App\Domain\Streaming\SupervisorStatus::class);
echo "supervisor beat: ", $st->secondsSinceBeat() === null ? "NEVER - it is not running" : $st->secondsSinceBeat()."s ago".($st->isRunning() ? " (ok)" : " (STALE)"), "\n";
echo "supervisor node: ", $st->nodeId() ?: "-", "\n";
echo "configured node: ", config("akstream.streaming.node_id") ?: "-", "\n";
foreach (App\Models\StreamSessionDestination::withoutGlobalScopes()->whereHas("session", fn($q) => $q->withoutGlobalScopes()->whereIn("status", ["detected","live"]))->get() as $sd) {
  echo "  dest ", substr($sd->stream_destination_id, 0, 8), " status=", $sd->status, " want=", $sd->desired_state, " node=", $sd->node_id ?: "null", " retries=", $sd->retry_count, "\n";
  if ($sd->last_error) { echo "       last error: ", $sd->last_error, "\n"; }
}' 2>/dev/null | mask | tail -20
echo "  (a destination stuck at status=pending with no supervisor beat means the supervisor is down:"
echo "   sudo systemctl start akstream-supervisor)"

hr "FFMPEG"
if command -v ffmpeg >/dev/null 2>&1; then
  echo "  ffmpeg: $(command -v ffmpeg) ($(ffmpeg -version 2>/dev/null | head -1))"
else
  echo "  ffmpeg: NOT FOUND - relays cannot start. Install it (apt install ffmpeg / yum install ffmpeg)."
fi

hr "MEDIAMTX LOG (last 15)"
journalctl -u mediamtx -n 15 --no-pager 2>/dev/null | mask || echo "no journal"

hr "SUPERVISOR LOG (last 15)"
journalctl -u akstream-supervisor -n 15 --no-pager 2>/dev/null | mask || echo "no journal"

hr "QUEUE LOG (last 10)"
journalctl -u akstream-queue -n 10 --no-pager 2>/dev/null | mask || echo "no journal"

hr "APP CONFIG (.env, masked)"
grep -E '^(APP_URL|APP_ENV|APP_DEBUG|DB_CONNECTION|SESSION_DRIVER|QUEUE_CONNECTION|CACHE_STORE|STREAM_)' .env 2>/dev/null | mask || echo ".env missing"

hr "FFMPEG"
command -v ffmpeg >/dev/null && ffmpeg -version 2>/dev/null | head -1 || echo "ffmpeg NOT in PATH"
echo "open_basedir (web): $("$PHPBIN" -i 2>/dev/null | grep -i '^open_basedir' | head -1)"

hr "PERMISSIONS"
for d in storage storage/logs bootstrap/cache .env; do
  [[ -e "$d" ]] && printf '%-20s %s %s\n' "$d" "$(stat -c '%U:%G' "$d")" "$(stat -c '%a' "$d")"
done
sudo -u "$WEBU" test -w storage/logs 2>/dev/null && echo "storage writable by $WEBU: yes" || echo "storage writable by $WEBU: NO"

hr "SCHEDULER / CRON"
crontab -u "$WEBU" -l 2>/dev/null | grep -c 'schedule:run' | xargs -I{} echo "cron entries for $WEBU: {}"
crontab -l 2>/dev/null | grep -c 'schedule:run' | xargs -I{} echo "cron entries for root: {}"

hr "LARAVEL HEALTH"
sudo -u "$WEBU" "$PHPBIN" artisan health:check 2>&1 | tail -15 | mask

hr "LAST APP ERRORS"
tail -n 5 storage/logs/laravel-*.log 2>/dev/null | mask | cut -c1-200 || echo "no log"

printf '\n════════ end of report ════════\n'
