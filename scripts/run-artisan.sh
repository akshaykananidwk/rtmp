#!/usr/bin/env bash
# Run an artisan command with the few PHP functions the streaming services need.
#
# Hosting panels (aaPanel, cPanel) put proc_open and friends in disable_functions for every
# site sharing that PHP version. Editing php.ini would change behaviour for ALL of those
# sites, so instead this re-enables the needed functions for THIS PROCESS ONLY, via -d.
# Everything else the panel disabled stays disabled, and no other website is affected.
#
# Usage: run-artisan.sh <php-binary> <app-dir> <artisan args...>
set -euo pipefail

PHP_BIN="${1:?php binary required}"
APP_DIR="${2:?app dir required}"
shift 2

# Subtract only what we need from whatever the panel currently disables, so the rest of the
# panel's hardening (exec, system, passthru, …) is left exactly as the administrator set it.
NEEDED='proc_open,proc_get_status,proc_terminate,proc_close,pcntl_signal,pcntl_async_signals'
KEEP="$("$PHP_BIN" -r '
$needed = explode(",", $argv[1]);
$current = array_filter(array_map("trim", explode(",", (string) ini_get("disable_functions"))));
echo implode(",", array_diff($current, $needed));
' -- "$NEEDED" 2>/dev/null || echo '')"

cd "$APP_DIR"
exec "$PHP_BIN" -d disable_functions="$KEEP" artisan "$@"
