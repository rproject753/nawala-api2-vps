#!/usr/bin/env bash
set -euo pipefail

# Pasang cron untuk updater standalone.
# Default: jalan tiap 30 menit.
#
# Env opsional:
#   APP_DIR=/var/www/nawala-api2-vps
#   PHP_BIN=/usr/bin/php
#   CRON_EXPR="*/30 * * * *"
#   LOG_FILE=/var/log/nawala-update.log

APP_DIR="${APP_DIR:-/var/www/nawala-api2-vps}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_EXPR="${CRON_EXPR:-*/30 * * * *}"
LOG_FILE="${LOG_FILE:-/var/log/nawala-update.log}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNNER="${SCRIPT_DIR}/update_sources.sh"

if [[ ! -x "${RUNNER}" ]]; then
  chmod +x "${RUNNER}"
fi

ENTRY="${CRON_EXPR} APP_DIR=${APP_DIR} PHP_BIN=${PHP_BIN} ${RUNNER} >> ${LOG_FILE} 2>&1"

TMP_FILE="$(mktemp)"
trap 'rm -f "${TMP_FILE}"' EXIT

crontab -l 2>/dev/null | grep -v "vps-almalinux-standalone/update_sources\\.sh" > "${TMP_FILE}" || true
echo "${ENTRY}" >> "${TMP_FILE}"
crontab "${TMP_FILE}"

echo "Cron terpasang:"
crontab -l | grep "vps-almalinux-standalone/update_sources\\.sh" || true
