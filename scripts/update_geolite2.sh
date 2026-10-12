#!/usr/bin/env bash
# ==============================================================================
# GeoLite2-City Database Update Script
#
# Usage:
#   MAXMIND_LICENSE_KEY="your_key" ./scripts/update_geolite2.sh
# or configure MAXMIND_LICENSE_KEY in your environment.
# ==============================================================================

set -euo pipefail

DEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../storage/app" && pwd)"
TARGET_FILE="${DEST_DIR}/GeoLite2-City.mmdb"
TMP_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

echo "==> Updating GeoLite2-City database..."

if [ -z "${MAXMIND_LICENSE_KEY:-}" ]; then
    echo "ERROR: MAXMIND_LICENSE_KEY environment variable is not set."
    echo "Register for a free MaxMind account at https://www.maxmind.com to obtain a key."
    exit 1
fi

DOWNLOAD_URL="https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz"

echo "==> Downloading archive..."
curl -fsSL "${DOWNLOAD_URL}" -o "${TMP_DIR}/geolite2.tar.gz"

echo "==> Extracting mmdb..."
tar -xzf "${TMP_DIR}/geolite2.tar.gz" -C "${TMP_DIR}"

MMDB_FOUND=$(find "${TMP_DIR}" -name "GeoLite2-City.mmdb" -type f | head -n 1)

if [ -z "${MMDB_FOUND}" ]; then
    echo "ERROR: GeoLite2-City.mmdb not found in downloaded archive."
    exit 1
fi

mkdir -p "${DEST_DIR}"
cp "${MMDB_FOUND}" "${TARGET_FILE}.tmp"
mv "${TARGET_FILE}.tmp" "${TARGET_FILE}"

echo "==> Successfully updated GeoLite2-City.mmdb at ${TARGET_FILE}"
ls -lh "${TARGET_FILE}"
