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

# Panels protect some files with the immutable attribute (aaPanel does this for
# .user.ini, which holds open_basedir). Those must be skipped, not fought.
PROTECTED=(.user.ini .htaccess.bak)
SKIPPED=()
for f in "${PROTECTED[@]}"; do
  [[ -e "$APP_DIR/$f" ]] && lsattr -d "$APP_DIR/$f" 2>/dev/null | grep -q 'i' && SKIPPED+=("$f")
done

echo "==> Setting ownership to $USER_NAME (immutable files are skipped)"
find "$APP_DIR" -not -name '.user.ini' -print0 2>/dev/null \
  | xargs -0 -r chown -h "$USER_NAME":"$USER_NAME" 2>/dev/null || true

echo "==> Setting permissions"
find "$APP_DIR" -type d -not -path '*/vendor/*' -print0 2>/dev/null | xargs -0 -r chmod 755 2>/dev/null || true
find "$APP_DIR" -type f -not -path '*/vendor/*' -not -name '.user.ini' -print0 2>/dev/null | xargs -0 -r chmod 644 2>/dev/null || true
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true
chmod 640 "$APP_DIR/.env" 2>/dev/null || true
chmod +x "$APP_DIR"/artisan "$APP_DIR"/scripts/*.sh 2>/dev/null || true

if [[ ${#SKIPPED[@]} -gt 0 ]]; then
  echo "    skipped (immutable, set by the hosting panel): ${SKIPPED[*]}"
fi

rm -f "$APP_DIR"/bootstrap/cache/*.php
[[ -n "$PHPBIN" ]] && sudo -u "$USER_NAME" "$PHPBIN" artisan optimize:clear >/dev/null 2>&1 || true

# Restart the services so they pick up the corrected ownership
for unit in akstream-supervisor akstream-queue; do
  systemctl is-enabled --quiet "$unit" 2>/dev/null && systemctl restart "$unit" 2>/dev/null || true
done

# Verify the result: ownership first (what we control), then a real write test.
WRONG_OWNER=()
NOT_WRITABLE=()
for d in storage/logs storage/framework/views storage/framework/sessions storage/app bootstrap/cache; do
  [[ -d "$APP_DIR/$d" ]] || continue
  [[ "$(stat -c '%U' "$APP_DIR/$d")" == "$USER_NAME" ]] || WRONG_OWNER+=("$d")
  sudo -u "$USER_NAME" test -w "$APP_DIR/$d" 2>/dev/null || NOT_WRITABLE+=("$d")
done

echo
echo "=============================================================="
if [[ ${#WRONG_OWNER[@]} -gt 0 ]]; then
  echo " STILL OWNED BY THE WRONG USER (expected $USER_NAME):"
  printf "   %s\n" "${WRONG_OWNER[@]}"
  echo " Try:  chown -R $USER_NAME:$USER_NAME \"$APP_DIR/storage\" \"$APP_DIR/bootstrap/cache\""
elif [[ ${#NOT_WRITABLE[@]} -gt 0 ]]; then
  echo " Ownership is correct ($USER_NAME), but a write test still failed for:"
  printf "   %s\n" "${NOT_WRITABLE[@]}"
  echo " A parent directory is probably not traversable by $USER_NAME. Check with:"
  echo "   namei -l \"$APP_DIR/storage\""
  echo " Every directory in that path needs at least o+x (or $USER_NAME ownership)."
else
  echo " Permissions repaired — $USER_NAME can write to storage and cache."
  echo " Reload the site."
fi
echo " Check anytime:  https://YOUR-DOMAIN/diagnose.php"
echo "=============================================================="
