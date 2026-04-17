#!/usr/bin/env bash
set -euo pipefail

# Sinkron domains_isp dari GitHub Release (hasil Actions), hanya unduh besar bila checksum berubah.
#
# Env:
#   APP_DIR               root project (default: /var/www/nawala-api2-vps)
#   GITHUB_REPO           owner/repo (default: rproject753/nawala-api2-vps)
#   GITHUB_RELEASE_TAG    tag release (default: domains-isp-cache)

APP_DIR="${APP_DIR:-/var/www/nawala-api2-vps}"
GITHUB_REPO="${GITHUB_REPO:-rproject753/nawala-api2-vps}"
GITHUB_RELEASE_TAG="${GITHUB_RELEASE_TAG:-domains-isp-cache}"

DEST="${APP_DIR}/cache/blocklist_files/domains_isp"
STATE="${DEST}.applied.sha256"
BASE="https://github.com/${GITHUB_REPO}/releases/download/${GITHUB_RELEASE_TAG}"
TMP="${DEST}.part"

mkdir -p "$(dirname "${DEST}")"

REMOTE_SHA_RAW="$(curl -sSfL --connect-timeout 25 --max-time 60 "${BASE}/domains_isp.sha256")"
REMOTE_SHA="$(echo "$REMOTE_SHA_RAW" | awk '{print $1}' | head -1)"
if [[ ! "$REMOTE_SHA" =~ ^[a-f0-9]{64}$ ]]; then
  echo "ERROR: checksum remote tidak valid (pastikan Release + workflow sudah jalan)." >&2
  echo "       Cek URL: ${BASE}/domains_isp.sha256" >&2
  exit 1
fi

if [[ -f "$STATE" ]] && [[ -f "$DEST" ]] && [[ -s "$DEST" ]]; then
  LOCAL_APPLIED="$(tr -d ' \t\n\r' < "$STATE")"
  if [[ "$LOCAL_APPLIED" == "$REMOTE_SHA" ]]; then
    echo "$(date -Iseconds) domains_isp unchanged (sha256 sama), lewati unduhan."
    exit 0
  fi
fi

echo "$(date -Iseconds) Mengunduh domains_isp (checksum berubah atau belum pernah sinkron)..."
curl -fL --retry 3 --connect-timeout 30 --max-time 0 \
  -o "${TMP}" "${BASE}/domains_isp"
test -s "${TMP}"

DL_SHA="$(sha256sum "${TMP}" | awk '{print $1}')"
if [[ "$DL_SHA" != "$REMOTE_SHA" ]]; then
  echo "ERROR: sha256 file tidak cocok (korup / tidak lengkap). remote=${REMOTE_SHA} got=${DL_SHA}" >&2
  rm -f "${TMP}"
  exit 1
fi

mv -f "${TMP}" "${DEST}"
printf '%s\n' "$REMOTE_SHA" > "${STATE}"

rm -f "${APP_DIR}/cache/trustpositif_member_cache.ser" 2>/dev/null || true

echo "$(date -Iseconds) OK: ${DEST} (sha256 ${REMOTE_SHA})"
