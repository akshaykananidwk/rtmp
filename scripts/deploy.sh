#!/usr/bin/env bash
###############################################################################
# First deployment (or manual deployment) into the atomic release layout.
#   sudo -u www-data bash scripts/deploy.sh https://github.com/OWNER/REPO.git main
# Later updates are performed from the admin panel (System → Updates).
###############################################################################
set -euo pipefail
REPO="${1:?git url}"; BRANCH="${2:-main}"
APP_DIR=/var/www/akstream
REL="$APP_DIR/releases/$(date +%Y%m%d%H%M%S)-manual"

git clone --depth 1 --branch "$BRANCH" "$REPO" "$REL"
cd "$REL"
git rev-parse HEAD > COMMIT
rm -rf .git
composer install --no-dev --optimize-autoloader --no-interaction

# shared resources
[[ -f "$APP_DIR/shared/.env" ]] || cp .env.example "$APP_DIR/shared/.env"
rm -rf storage && ln -s "$APP_DIR/shared/storage" storage
ln -sfn "$APP_DIR/shared/.env" .env
mkdir -p "$APP_DIR/shared/storage"/{app/public,app/backups,app/recordings,app/releases,app/private,framework/cache,framework/sessions,framework/views,logs}
ln -sfn "$APP_DIR/shared/public/uploads" public/uploads

# atomic switch
ln -sfn "$REL" "$APP_DIR/current.tmp" && mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"
cd "$APP_DIR/current"
php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize:clear >/dev/null
echo "Deployed $(cat COMMIT) to $REL → $APP_DIR/current"
echo "Open https://<domain>/install if this is a fresh server."
