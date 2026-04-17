#!/usr/bin/env bash
set -euo pipefail

# Ambil domains_isp dari GitHub Release (mirror Actions), bukan langsung dari Komdigi.
# Pakai setelah workflow .github/workflows/komdigi-domains_isp.yml sukses minimal sekali.
#
# Env:
#   APP_DIR          root project (default: /var/www/nawala-api2-vps)
#   GITHUB_REPO      owner/repo (default: rproject753/nawala-api2-vps)
#   GITHUB_RELEASE_TAG  tag release (default: domains-isp-cache)

APP_DIR="${APP_DIR:-/var/www/nawala-api2-vps}"
GITHUB_REPO="${GITHUB_REPO:-rproject753/nawala-api2-vps}"
GITHUB_RELEASE_TAG="${GITHUB_RELEASE_TAG:-domains-isp-cache}"

DEST="${APP_DIR}/cache/blocklist_files/domains_isp"
BASE="https://github.com/${GITHUB_REPO}/releases/download/${GITHUB_RELEASE_TAG}"
TMP="${DEST}.part"

mkdir -p "$(dirname "${DEST}")"

curl -fL --retry 3 --connect-timeout 30 --max-time 0 \
  -o "${TMP}" "${BASE}/domains_isp"
test -s "${TMP}"

mv -f "${TMP}" "${DEST}"

# Cache checker member (opsional): supaya pembacaan list TP konsisten setelah file ganti
rm -f "${APP_DIR}/cache/trustpositif_member_cache.ser" 2>/dev/null || true

echo "OK: ${DEST}"
