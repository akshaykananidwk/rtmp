#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE
# Updates an installed copy straight from GitHub when the directory is NOT a
# git clone (files uploaded by FTP / File Manager).
#
#   sudo bash sync-from-github.sh [app-directory] [owner/repo] [branch]
#
# Defaults: current directory, akshaykananidwk/rtmp, claude/ak-computer-streaming-saas-868j8s
#
# Never touches: .env  storage/  vendor/  public/uploads/  installed.lock
# For a private repository export GITHUB_TOKEN=ghp_... before running.
###############################################################################
set -euo pipefail

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
EXCLUDES=(--exclude=.env --exclude=.env.* --exclude=storage --exclude=vendor --exclude=public/uploads --exclude=node_modules --exclude=.git)

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

OWNER="$(stat -c '%U' artisan)"
echo "==> Restoring ownership to $OWNER and permissions"
chown -R "$OWNER":"$OWNER" . 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
chmod +x scripts/*.sh 2>/dev/null || true

PHP_BIN="$(command -v php || echo php)"
sudo -u "$OWNER" "$PHP_BIN" artisan optimize:clear >/dev/null 2>&1 || "$PHP_BIN" artisan optimize:clear >/dev/null 2>&1 || true

echo
echo "=============================================================="
echo " Updated to version $NEW_VERSION"
echo " Backup of the previous files: $(cd .. && pwd)/$(basename "$BACKUP")"
echo " Next: sudo bash scripts/install-mediamtx.sh \"$APP_DIR\" https://YOUR-DOMAIN"
echo "=============================================================="
