#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE
# Installs the streaming engine (MediaMTX + FFmpeg) and wires it to an
# application that is ALREADY installed (aaPanel / cPanel / plain VPS).
#
#   sudo bash scripts/install-mediamtx.sh /www/wwwroot/rtmp.akdwk.in https://rtmp.akdwk.in
#
# Safe to re-run.
###############################################################################
set -euo pipefail

APP_DIR="${1:-}"
APP_URL="${2:-}"
MEDIAMTX_VERSION="${MEDIAMTX_VERSION:-1.9.3}"

if [[ -z "$APP_DIR" || -z "$APP_URL" ]]; then
  echo "Usage: sudo bash $0 <app-directory> <https://your-domain>"; exit 1
fi
[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)"; exit 1; }
[[ -f "$APP_DIR/artisan" ]] || { echo "No artisan found in $APP_DIR — wrong directory?"; exit 1; }
APP_URL="${APP_URL%/}"

echo "==> 1/6 FFmpeg"
if ! command -v ffmpeg >/dev/null; then
  if command -v apt-get >/dev/null; then apt-get update -y && apt-get install -y ffmpeg curl
  elif command -v dnf >/dev/null; then dnf install -y ffmpeg curl || { dnf install -y epel-release && dnf install -y ffmpeg curl; }
  else yum install -y epel-release && yum install -y ffmpeg curl; fi
fi
FFMPEG_BIN="$(command -v ffmpeg)"; FFPROBE_BIN="$(command -v ffprobe || echo "${FFMPEG_BIN%ffmpeg}ffprobe")"
echo "    ffmpeg: $FFMPEG_BIN"

echo "==> 2/6 MediaMTX ${MEDIAMTX_VERSION}"
if ! command -v mediamtx >/dev/null; then
  case "$(uname -m)" in x86_64) A=amd64;; aarch64) A=arm64v8;; armv7l) A=armv7;; *) A=amd64;; esac
  curl -fsSL "https://github.com/bluenviron/mediamtx/releases/download/v${MEDIAMTX_VERSION}/mediamtx_v${MEDIAMTX_VERSION}_linux_${A}.tar.gz" | tar -xz -C /tmp
  install -m 755 /tmp/mediamtx /usr/local/bin/mediamtx
  rm -f /tmp/mediamtx /tmp/LICENSE /tmp/mediamtx.yml
fi
id -u mediamtx >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin mediamtx
mkdir -p /etc/mediamtx

echo "==> 3/6 Shared secret + application configuration"
ENV_FILE="$APP_DIR/.env"
SECRET="$(grep -E '^STREAM_ENGINE_SECRET=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
if [[ -z "$SECRET" ]]; then SECRET="$(head -c 32 /dev/urandom | base64 | tr -d '/+=' | head -c 40)"; fi

set_env() {  # set_env KEY VALUE
  if grep -qE "^#?\s*$1=" "$ENV_FILE"; then sed -i "s|^#\?\s*$1=.*|$1=$2|" "$ENV_FILE"
  else printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"; fi
}
HOST="$(echo "$APP_URL" | sed -E 's#^https?://##; s#/.*##')"
set_env STREAM_ENGINE mediamtx
set_env STREAM_ENGINE_SECRET "$SECRET"
set_env STREAM_SERVER_URL "rtmp://$HOST/live"
set_env STREAM_SERVER_API_URL "http://127.0.0.1:9997"
set_env STREAM_INTERNAL_RTMP_URL "rtmp://127.0.0.1:1935/live"
set_env STREAM_HLS_URL "http://127.0.0.1:8888"
set_env STREAM_NODE_ID "media-1"
set_env FFMPEG_BINARY "$FFMPEG_BIN"
set_env FFPROBE_BINARY "$FFPROBE_BIN"

echo "==> 4/6 MediaMTX configuration"
TPL="$APP_DIR/scripts/mediamtx/mediamtx.yml"
[[ -f "$TPL" ]] || { echo "Template missing: $TPL"; exit 1; }
sed -e "s#__APP_URL__#$APP_URL#g" -e "s#__ENGINE_SECRET__#$SECRET#g" "$TPL" > /etc/mediamtx/mediamtx.yml
chmod 640 /etc/mediamtx/mediamtx.yml; chown mediamtx:mediamtx /etc/mediamtx/mediamtx.yml

echo "==> 5/6 Services"
PHP_BIN="$(command -v php)"
WEB_USER="$(stat -c '%U' "$APP_DIR")"
for unit in mediamtx akstream-supervisor akstream-queue; do
  src="$APP_DIR/scripts/systemd/$unit.service"
  [[ -f "$src" ]] || continue
  sed -e "s#/var/www/akstream/current#$APP_DIR#g" -e "s#/usr/bin/php#$PHP_BIN#g" \
      -e "s#^User=www-data#User=$WEB_USER#" -e "s#^Group=www-data#Group=$WEB_USER#" "$src" > "/etc/systemd/system/$unit.service"
done
systemctl daemon-reload
systemctl enable --now mediamtx
sleep 2
systemctl enable --now akstream-supervisor akstream-queue

echo "==> 6/6 Firewall + scheduler"
if command -v ufw >/dev/null && ufw status | grep -q active; then ufw allow 1935/tcp >/dev/null; ufw allow 8890/udp >/dev/null
elif command -v firewall-cmd >/dev/null && firewall-cmd --state >/dev/null 2>&1; then firewall-cmd --permanent --add-port=1935/tcp >/dev/null; firewall-cmd --permanent --add-port=8890/udp >/dev/null; firewall-cmd --reload >/dev/null; fi
CRON="* * * * * cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
( crontab -u "$WEB_USER" -l 2>/dev/null | grep -v 'artisan schedule:run' ; echo "$CRON" ) | crontab -u "$WEB_USER" -

cd "$APP_DIR" && sudo -u "$WEB_USER" "$PHP_BIN" artisan optimize:clear >/dev/null 2>&1 || true

echo
echo "=============================================================="
echo " Streaming engine ready"
echo "   OBS Server : rtmp://$HOST/live"
echo "   Stream key : Admin → Stream Keys → Generate"
echo
systemctl is-active --quiet mediamtx && echo "   mediamtx            : running" || echo "   mediamtx            : NOT running (journalctl -u mediamtx -n 30)"
systemctl is-active --quiet akstream-supervisor && echo "   akstream-supervisor : running" || echo "   akstream-supervisor : NOT running (journalctl -u akstream-supervisor -n 30)"
systemctl is-active --quiet akstream-queue && echo "   akstream-queue      : running" || echo "   akstream-queue      : NOT running (journalctl -u akstream-queue -n 30)"
echo
echo " Open the panel and run: Admin → Health → Run health check"
echo " If PHP cannot see ffmpeg, add /usr/bin/:/usr/local/bin/ to open_basedir"
echo " (aaPanel: Website → Config → PHP settings) and clear open_basedir for CLI."
echo "=============================================================="
