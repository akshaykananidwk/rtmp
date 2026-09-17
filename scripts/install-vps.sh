#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE : Ubuntu 22.04/24.04 VPS bootstrap
#
#   sudo bash scripts/install-vps.sh stream.example.com
#
# Installs Apache, PHP 8.3, MariaDB, Redis, FFmpeg, MediaMTX, Composer,
# creates the release layout /var/www/akstream/{releases,shared,current},
# the systemd services and the cron entry. Idempotent where possible.
###############################################################################
set -euo pipefail
DOMAIN="${1:-}"
APP_DIR=/var/www/akstream
MEDIAMTX_VERSION="${MEDIAMTX_VERSION:-1.9.3}"

if [[ -z "$DOMAIN" ]]; then echo "Usage: $0 <domain>"; exit 1; fi
if [[ $EUID -ne 0 ]]; then echo "Run as root"; exit 1; fi

echo "==> Packages"
apt-get update -y
apt-get install -y software-properties-common curl unzip git ufw
add-apt-repository -y ppa:ondrej/php || true
apt-get update -y
apt-get install -y apache2 mariadb-server redis-server ffmpeg certbot python3-certbot-apache \
  php8.3 php8.3-cli php8.3-fpm libapache2-mod-php8.3 php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl \
  php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis php8.3-sqlite3 php8.3-opcache

echo "==> Composer"
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> MediaMTX ${MEDIAMTX_VERSION}"
if ! command -v mediamtx >/dev/null; then
  ARCH=$(uname -m); case "$ARCH" in x86_64) A=amd64;; aarch64) A=arm64v8;; *) A=amd64;; esac
  curl -sL "https://github.com/bluenviron/mediamtx/releases/download/v${MEDIAMTX_VERSION}/mediamtx_v${MEDIAMTX_VERSION}_linux_${A}.tar.gz" | tar -xz -C /tmp
  install -m 755 /tmp/mediamtx /usr/local/bin/mediamtx
  id -u mediamtx >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin mediamtx
  mkdir -p /etc/mediamtx
fi

echo "==> Directory layout (atomic releases)"
mkdir -p "$APP_DIR"/{releases,shared/storage,shared/public/uploads}
chown -R www-data:www-data "$APP_DIR"

echo "==> Apache"
a2enmod rewrite headers ssl proxy proxy_http >/dev/null
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
sed "s/stream.example.com/${DOMAIN}/g" "$SCRIPT_DIR/apache/akstream.conf" > /etc/apache2/sites-available/akstream.conf
a2dissite 000-default >/dev/null || true
a2ensite akstream >/dev/null
systemctl reload apache2 || true

echo "==> Firewall"
ufw allow OpenSSH >/dev/null; ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null; ufw allow 1935/tcp >/dev/null; ufw allow 8890/udp >/dev/null
ufw --force enable >/dev/null

echo "==> systemd units"
cp "$SCRIPT_DIR"/systemd/*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable mediamtx akstream-supervisor akstream-queue >/dev/null

echo "==> Cron (Laravel scheduler)"
( crontab -u www-data -l 2>/dev/null | grep -v 'schedule:run' ; echo "* * * * * cd $APP_DIR/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1" ) | crontab -u www-data -

cat <<MSG

Next steps
----------
1. Deploy the code:      sudo -u www-data bash scripts/deploy.sh <git-url> main
2. Open https://$DOMAIN/install and complete the installer.
3. Copy STREAM_ENGINE_SECRET from $APP_DIR/shared/.env into /etc/mediamtx/mediamtx.yml
   (template: scripts/mediamtx/mediamtx.yml) then: systemctl restart mediamtx
4. systemctl start akstream-supervisor akstream-queue
5. certbot --apache -d $DOMAIN   (SSL)
MSG
