#!/usr/bin/env bash
set -euo pipefail

# Standalone runner untuk VPS AlmaLinux.
# Tujuan: jalankan updater pada instance terpisah agar tidak mengganggu instance PHP utama.
#
# Gunakan env:
#   APP_DIR=/var/www/nawala-api2-vps
#   PHP_BIN=/usr/bin/php
#
# Contoh:
#   APP_DIR=/var/www/nawala-api2-vps ./update_sources.sh

PHP_BIN="${PHP_BIN:-php}"
APP_DIR="${APP_DIR:-/var/www/nawala-api2-vps}"

if [[ ! -f "${APP_DIR}/cron/update_sources.php" ]]; then
  echo "ERROR: ${APP_DIR}/cron/update_sources.php tidak ditemukan." >&2
  echo "Set APP_DIR ke folder instance VPS yang benar." >&2
  exit 1
fi

exec "${PHP_BIN}" "${APP_DIR}/cron/update_sources.php" "$@"
