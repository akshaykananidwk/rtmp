#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE
# Updates an installed copy straight from GitHub when the directory is NOT a
# git clone (files uploaded by FTP / File Manager).
#
#   sudo bash sync-from-github.sh [app-directory] [owner/repo] [branch]
#
# The web-server user and PHP binary are detected automatically; override with
#   WEB_USER=www PHP_BIN=/www/server/php/83/bin/php sudo -E bash sync-from-github.sh ...
#
# Defaults: current directory, akshaykananidwk/rtmp, claude/ak-computer-streaming-saas-868j8s
#
# Never touches: .env  storage/  vendor/  public/uploads/  installed.lock
# For a private repository export GITHUB_TOKEN=ghp_... before running.
###############################################################################
set -euo pipefail

# Bash reads a script while it runs, and this one overwrites itself when it copies
# the new files in — which silently truncates the rest of the run. Re-exec from a
# private copy first so the update can never corrupt the updater.
if [[ "${AK_SYNC_RELOCATED:-0}" != "1" ]]; then
    SELF_COPY="$(mktemp -t ak-sync-XXXXXX.sh)"
    cat "${BASH_SOURCE[0]}" > "$SELF_COPY"
    export AK_SYNC_RELOCATED=1
    trap 'rm -f "$SELF_COPY"' EXIT
    bash "$SELF_COPY" "$@"
    exit $?
fi

APP_DIR="${1:-$PWD}"
REPO="${2:-akshaykananidwk/rtmp}"
BRANCH="${3:-claude/ak-computer-streaming-saas-868j8s}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cd "$APP_DIR"
[[ -f artisan && -f composer.json ]] || { echo "ERROR: $APP_DIR does not look like the application (artisan/composer.json missing)."; exit 1; }

echo "==> Downloading $REPO@$BRANCH"
AUTH=()
[[ -n "${GITHUB_TOKEN:-}" ]] && AUTH=(-H "Authorization: Bearer $GITHUB_TOKEN")
curl -fsSL "${AUTH[@]}" -o "$TMP/src.tar.gz" "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" \
  || { echo "ERROR: download failed. Check the repository/branch name (and GITHUB_TOKEN for a private repo)."; exit 1; }

mkdir -p "$TMP/src"
tar -xzf "$TMP/src.tar.gz" -C "$TMP/src" --strip-components=1
[[ -f "$TMP/src/artisan" ]] || { echo "ERROR: downloaded archive does not contain the application."; exit 1; }

NEW_VERSION="$(cat "$TMP/src/VERSION" 2>/dev/null || echo '?')"
OLD_VERSION="$(cat VERSION 2>/dev/null || echo '?')"
echo "==> $OLD_VERSION  ->  $NEW_VERSION"

echo "==> Backing up current files (without vendor/storage) to $APP_DIR/../ak-backup-$(date +%Y%m%d-%H%M%S).tar.gz"
BACKUP="../ak-backup-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$BACKUP" --exclude=vendor --exclude=storage --exclude=node_modules . 2>/dev/null || echo "    (backup skipped)"

# Protected paths are never overwritten
EXCLUDES=(--exclude=.env --exclude=.env.* --exclude=storage --exclude=vendor --exclude=public/uploads --exclude=node_modules --exclude=.git --exclude=.user.ini)

echo "==> Copying updated files"
if command -v rsync >/dev/null; then
  rsync -a "${EXCLUDES[@]}" "$TMP/src"/ ./
else
  ( cd "$TMP/src" && find . -type f \
      ! -path './.env*' ! -path './storage/*' ! -path './vendor/*' ! -path './public/uploads/*' ! -path './.git/*' \
      -print0 | while IFS= read -r -d '' f; do
        mkdir -p "$APP_DIR/$(dirname "$f")"
        cp -p "$f" "$APP_DIR/$f"
      done )
fi
# .env.example is a template (no secrets) and is needed by the installer
cp -p "$TMP/src/.env.example" ./.env.example 2>/dev/null || true

echo "==> Clearing compiled caches"
rm -f bootstrap/cache/*.php

if command -v composer >/dev/null && [[ "$(md5sum composer.lock 2>/dev/null | cut -d' ' -f1)" != "$(md5sum "$TMP/src/composer.lock" 2>/dev/null | cut -d' ' -f1)" ]]; then
  echo "==> Dependencies changed – running composer"
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction || echo "    composer failed – run it manually"
fi

# shellcheck source=/dev/null
[[ -f scripts/lib-detect.sh ]] && source scripts/lib-detect.sh
# The media server config ships with the app; an old copy on disk silently breaks
# features added later (the branded/ path used by overlays, for example).
if [[ -f /etc/mediamtx/mediamtx.yml && -f scripts/mediamtx/mediamtx.yml ]]; then
  APP_URL_ENV="$(grep -E '^APP_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d '\"' || true)"
  SECRET_ENV="$(grep -E '^STREAM_ENGINE_SECRET=' .env 2>/dev/null | cut -d= -f2- | tr -d '\"' || true)"
  if [[ -n "$APP_URL_ENV" && -n "$SECRET_ENV" ]]; then
    RENDERED="$(mktemp)"
    sed -e "s#__APP_URL__#${APP_URL_ENV%/}#g" -e "s#__ENGINE_SECRET__#$SECRET_ENV#g" scripts/mediamtx/mediamtx.yml > "$RENDERED"
    if ! diff -q "$RENDERED" /etc/mediamtx/mediamtx.yml >/dev/null 2>&1; then
      echo "==> Updating the streaming engine configuration"
      cp -p /etc/mediamtx/mediamtx.yml "/etc/mediamtx/mediamtx.yml.bak-$(date +%Y%m%d%H%M%S)" 2>/dev/null || true
      if command -v mediamtx >/dev/null && timeout 5 mediamtx "$RENDERED" 2>&1 | head -3 | grep -qiE '^ERR|unknown field'; then
        echo "!!  new configuration rejected by MediaMTX – keeping the old one"
      else
        cat "$RENDERED" > /etc/mediamtx/mediamtx.yml
        chown mediamtx:mediamtx /etc/mediamtx/mediamtx.yml 2>/dev/null || true
        systemctl restart mediamtx 2>/dev/null && echo "    mediamtx restarted with the new configuration" || echo "    !! restart mediamtx manually"
      fi
    fi
    rm -f "$RENDERED"
  fi
fi

OWNER="$(detect_web_user 2>/dev/null || echo www-data)"
if [[ "$OWNER" == "root" ]]; then
  echo "!!  Could not detect the web-server user; leaving ownership unchanged."
  echo "!!  If the site returns 500, run:  chown -R <web-user>:<web-user> \"$APP_DIR\""
else
  echo "==> Restoring ownership to $OWNER and permissions"
  find . -not -name '.user.ini' -print0 2>/dev/null | xargs -0 -r chown -h "$OWNER":"$OWNER" 2>/dev/null || true
fi
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
chmod +x scripts/*.sh 2>/dev/null || true

PHPBIN="$(detect_php 2>/dev/null || true)"
run_artisan() {
  if [[ -z "$PHPBIN" ]]; then return 1; fi
  if [[ "$OWNER" != "root" ]]; then sudo -u "$OWNER" "$PHPBIN" artisan "$@"; else "$PHPBIN" artisan "$@"; fi
}

if [[ -n "$PHPBIN" ]]; then
  run_artisan optimize:clear >/dev/null 2>&1 || true

  # New code almost always ships new tables/columns; without this the panel throws
  # "table not found" errors. A database backup is taken first.
  if [[ -f storage/app/installed.lock ]]; then
    echo "==> Backing up the database"
    run_artisan backup:run --type=database --trigger=manual >/dev/null 2>&1 \
      && echo "    database backup created" \
      || echo "    !! database backup failed – continuing, files backup is at $BACKUP"

    echo "==> Running database migrations"
    if MIGRATE_OUTPUT="$(run_artisan migrate --force 2>&1)"; then
      echo "$MIGRATE_OUTPUT" | grep -E 'DONE|Nothing to migrate|INFO' | sed 's/^/    /' | tail -12
    else
      echo "!!  MIGRATIONS FAILED:"
      echo "$MIGRATE_OUTPUT" | tail -15 | sed 's/^/    /'
      echo "!!  The panel may show errors until this is fixed."
    fi
  else
    echo "==> Skipping migrations (application not installed yet – finish /install first)"
  fi
else
  echo "!!  PHP binary not found – run these manually:"
  echo "      php artisan migrate --force && php artisan optimize:clear"
fi

for unit in akstream-supervisor akstream-queue; do
  if systemctl is-enabled --quiet "$unit" 2>/dev/null; then
    systemctl restart "$unit" 2>/dev/null && echo "==> Restarted $unit" || echo "!!  Could not restart $unit"
  fi
done

echo
echo "=============================================================="
echo " Updated to version $NEW_VERSION"
echo " Backup of the previous files: $(cd .. && pwd)/$(basename "$BACKUP")"
echo " Next: sudo bash scripts/install-mediamtx.sh \"$APP_DIR\" https://YOUR-DOMAIN"
echo "=============================================================="
