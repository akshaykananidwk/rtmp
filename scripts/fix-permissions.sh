#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE
# Repairs ownership/permissions after files were uploaded or edited as root
# (the usual cause of a sudden HTTP 500 that shows no message).
#
#   sudo bash scripts/fix-permissions.sh [app-directory]
#   WEB_USER=www sudo -E bash scripts/fix-permissions.sh   # force a user
###############################################################################
set -euo pipefail

APP_DIR="${1:-$PWD}"
cd "$APP_DIR"
[[ -f artisan ]] || { echo "ERROR: $APP_DIR is not the application directory (artisan missing)."; exit 1; }

# shellcheck source=/dev/null
[[ -f scripts/lib-detect.sh ]] && source scripts/lib-detect.sh

USER_NAME="$(detect_web_user 2>/dev/null || echo www-data)"
PHPBIN="$(detect_php 2>/dev/null || true)"

if [[ "$USER_NAME" == "root" ]]; then
  echo "ERROR: could not detect the web-server user."
  echo "       Find it with:  ps -eo user,comm | grep -E 'php-fpm|nginx|httpd'"
  echo "       Then run:      WEB_USER=<user> sudo -E bash $0 $APP_DIR"
  exit 1
fi

echo "==> Web user : $USER_NAME"
echo "==> PHP      : ${PHPBIN:-not found}"

chown -R "$USER_NAME":"$USER_NAME" "$APP_DIR"
find "$APP_DIR" -type d -not -path '*/vendor/*' -exec chmod 755 {} \;
find "$APP_DIR" -type f -not -path '*/vendor/*' -exec chmod 644 {} \;
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod 640 "$APP_DIR/.env" 2>/dev/null || true
chmod +x "$APP_DIR"/artisan "$APP_DIR"/scripts/*.sh 2>/dev/null || true

rm -f "$APP_DIR"/bootstrap/cache/*.php
[[ -n "$PHPBIN" ]] && sudo -u "$USER_NAME" "$PHPBIN" artisan optimize:clear >/dev/null 2>&1 || true

# Restart the services so they pick up the corrected ownership
for unit in akstream-supervisor akstream-queue; do
  systemctl is-enabled --quiet "$unit" 2>/dev/null && systemctl restart "$unit" 2>/dev/null || true
done

echo
echo "=============================================================="
echo " Permissions repaired. Reload the site."
echo " Still failing? Open  https://YOUR-DOMAIN/diagnose.php"
echo "=============================================================="
