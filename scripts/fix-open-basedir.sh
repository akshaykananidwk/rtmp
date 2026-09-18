#!/usr/bin/env bash
###############################################################################
# AK COMPUTER – ONE LIVE EVERYWHERE
# Lets PHP see the system binaries (ffmpeg, ffprobe) when the hosting panel
# restricts open_basedir to the website directory.
#
#   sudo bash scripts/fix-open-basedir.sh [app-directory]
#
# aaPanel/BT keep the setting in <site>/.user.ini and mark it immutable
# (chattr +i); this script removes the flag, appends the extra paths, restores
# the flag and reloads PHP-FPM. Safe to re-run.
###############################################################################
set -uo pipefail

APP_DIR="${1:-$PWD}"
EXTRA="/usr/bin/:/usr/local/bin/:/bin/:/tmp/"
cd "$APP_DIR" 2>/dev/null || { echo "No such directory: $APP_DIR"; exit 1; }
[[ -f artisan ]] || { echo "ERROR: $APP_DIR is not the application directory."; exit 1; }

changed=0

patch_file() {  # patch_file <path> <key-regex>
  local file="$1" had_immutable=0
  [[ -f "$file" ]] || return 1
  grep -qE '^\s*open_basedir\s*=' "$file" || return 1

  if lsattr -d "$file" 2>/dev/null | awk '{print $1}' | grep -q 'i'; then
    had_immutable=1; chattr -i "$file" 2>/dev/null || true
  fi

  local current
  current="$(grep -E '^\s*open_basedir\s*=' "$file" | head -1)"
  if grep -q '/usr/bin/' <<<"$current"; then
    echo "    already allows /usr/bin — $file"
  else
    cp -p "$file" "$file.bak-$(date +%Y%m%d%H%M%S)" 2>/dev/null || true
    # append the extra paths to the existing value (keep the trailing colon style)
    sed -i -E "s#^(\s*open_basedir\s*=\s*)(.*)\$#\1\2:${EXTRA}#" "$file"
    echo "    patched $file"
    changed=1
  fi

  [[ $had_immutable -eq 1 ]] && chattr +i "$file" 2>/dev/null || true
  return 0
}

echo "==> Looking for open_basedir settings"
found=0
# 1) the site's .user.ini (aaPanel / BT panel)
patch_file "$APP_DIR/.user.ini" && found=1
# 2) panel-managed php-fpm pool configs mentioning this site
for f in $(grep -rlE "open_basedir" /www/server/php/*/etc/php-fpm.d/*.conf \
                                    /etc/php/*/fpm/pool.d/*.conf \
                                    /usr/local/php/etc/php-fpm.d/*.conf 2>/dev/null | head -5); do
  grep -q "$APP_DIR" "$f" 2>/dev/null && { patch_file "$f" && found=1; }
done

if [[ $found -eq 0 ]]; then
  echo "    no open_basedir restriction found in .user.ini or the FPM pools"
  echo "    (it may be set in php.ini — check: php -i | grep open_basedir)"
fi

if [[ $changed -eq 1 ]]; then
  echo "==> Reloading PHP"
  reloaded=0
  for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php.*fpm.*\.service$'); do
    systemctl reload "$svc" 2>/dev/null || systemctl restart "$svc" 2>/dev/null || true
    echo "    reloaded $svc"; reloaded=1
  done
  [[ $reloaded -eq 0 ]] && echo "    !! could not find a php-fpm service — restart PHP from the panel"
fi

echo
echo "==> Verification"
# shellcheck source=/dev/null
[[ -f scripts/lib-detect.sh ]] && source scripts/lib-detect.sh
PHPBIN="$(detect_php 2>/dev/null || echo php)"
FF="$(command -v ffmpeg || echo /usr/bin/ffmpeg)"
echo "    ffmpeg on disk      : $FF"
echo "    CLI PHP can see it  : $("$PHPBIN" -r "echo @is_executable('$FF') ? 'yes' : 'NO';" 2>/dev/null)"
echo "    CLI open_basedir    : $("$PHPBIN" -r 'echo ini_get("open_basedir") ?: "(none)";' 2>/dev/null)"
echo
echo "    Now press 'Run health check' in the panel."
echo "    Streaming Engine should turn green. If it stays yellow, the WEB PHP"
echo "    (php-fpm) still has the restriction — set it in the panel UI:"
echo "    aaPanel → Website → your domain → Config → PHP settings → open_basedir"
echo "    and append:  :${EXTRA}"
