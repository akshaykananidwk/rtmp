#!/usr/bin/env bash
# Shared helpers: find the PHP binary and the web-server user on any panel/distro.
# Sourced by the other scripts — not meant to be run directly.

detect_php() {
  local c
  for c in "${PHP_BIN:-}" "$(command -v php 2>/dev/null || true)"; do
    [[ -n "$c" && -x "$c" ]] && { echo "$c"; return 0; }
  done
  # aaPanel / BT Panel, cPanel (EA4), CloudPanel, Plesk, custom builds — newest first
  local p
  for p in $(ls -1d /www/server/php/*/bin/php /usr/local/php/bin/php /opt/cpanel/ea-php8*/root/usr/bin/php \
                    /opt/plesk/php/8.*/bin/php /usr/bin/php8.* /usr/local/bin/php 2>/dev/null | sort -rV); do
    [[ -x "$p" ]] && { echo "$p"; return 0; }
  done
  return 1
}

# The user PHP-FPM / Apache actually runs as — never root.
detect_web_user() {
  local u
  if [[ -n "${WEB_USER:-}" ]]; then echo "$WEB_USER"; return 0; fi

  # 1) the user running php-fpm / apache / nginx workers
  for u in $(ps -eo user:32,comm 2>/dev/null | awk '$2 ~ /^(php-fpm|httpd|apache2|nginx)/ {print $1}' | sort -u); do
    [[ "$u" != "root" ]] && { echo "$u"; return 0; }
  done

  # 2) a user that exists on this kind of panel
  for u in www www-data nginx apache daemon; do
    id -u "$u" >/dev/null 2>&1 && { echo "$u"; return 0; }
  done

  echo "root"
}
