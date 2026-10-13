#!/usr/bin/env bash
# ==============================================================================
# GeoLite2-City Database Secure Update Script
#
# Robust, atomic update procedure:
#   - Masks license keys from all outputs and logs
#   - Verifies HTTP response and download size (>10 MB)
#   - Validates MMDB magic bytes before touching existing database
#   - Automatically creates backup (GeoLite2-City.mmdb.bak)
#   - Atomic replacement via mv to guarantee 0-downtime consistency
#
# Usage:
#   MAXMIND_LICENSE_KEY="your_key" ./scripts/update_geolite2.sh
#   ./scripts/update_geolite2.sh --check
# ==============================================================================

set -euo pipefail

DEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../storage/app" && pwd)"
TARGET_FILE="${DEST_DIR}/GeoLite2-City.mmdb"
BACKUP_FILE="${DEST_DIR}/GeoLite2-City.mmdb.bak"
TMP_DIR="$(mktemp -d)"

cleanup() {
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

if [[ "${1:-}" == "--check" ]]; then
    echo "==> Checking current GeoLite2-City database..."
    if [[ -f "${TARGET_FILE}" ]]; then
        SIZE=$(du -h "${TARGET_FILE}" | cut -f1)
        MODIFIED=$(date -r "${TARGET_FILE}" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || stat -c "%y" "${TARGET_FILE}" 2>/dev/null || echo "Unknown")
        echo "    File:     ${TARGET_FILE}"
        echo "    Size:     ${SIZE}"
        echo "    Modified: ${MODIFIED}"
        exit 0
    else
        echo "    Database not found at ${TARGET_FILE}"
        exit 1
    fi
fi

echo "======================================================================"
echo "         MISSION CODEX — MAXMIND GEOLITE2 SECURE UPDATE               "
echo "======================================================================"

if [ -z "${MAXMIND_LICENSE_KEY:-}" ]; then
    echo "ERROR: MAXMIND_LICENSE_KEY environment variable is not set."
    echo "Register for a free MaxMind account at https://www.maxmind.com to obtain a key."
    exit 1
fi

KEY_LEN=${#MAXMIND_LICENSE_KEY}
if [ "${KEY_LEN}" -gt 4 ]; then
    MASKED_KEY="${MAXMIND_LICENSE_KEY:0:3}****${MAXMIND_LICENSE_KEY: -3}"
else
    MASKED_KEY="****"
fi
echo "==> Using License Key: [${MASKED_KEY}] (masked for privacy)"

DOWNLOAD_URL="https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=${MAXMIND_LICENSE_KEY}&suffix=tar.gz"

echo "==> Downloading latest archive..."
if ! curl -fsSL "${DOWNLOAD_URL}" -o "${TMP_DIR}/geolite2.tar.gz"; then
    echo "ERROR: Download failed. Please verify your license key and network connectivity."
    exit 1
fi

ARCHIVE_SIZE=$(wc -c < "${TMP_DIR}/geolite2.tar.gz" | tr -d ' ')
if [ "${ARCHIVE_SIZE}" -lt 5242880 ]; then
    echo "ERROR: Downloaded archive is abnormally small (${ARCHIVE_SIZE} bytes). Aborting update."
    exit 1
fi

echo "==> Extracting archive..."
tar -xzf "${TMP_DIR}/geolite2.tar.gz" -C "${TMP_DIR}"

MMDB_FOUND=$(find "${TMP_DIR}" -name "GeoLite2-City.mmdb" -type f | head -n 1)

if [ -z "${MMDB_FOUND}" ]; then
    echo "ERROR: GeoLite2-City.mmdb not found inside the downloaded archive."
    exit 1
fi

# Size validation (> 10MB)
MMDB_SIZE=$(wc -c < "${MMDB_FOUND}" | tr -d ' ')
if [ "${MMDB_SIZE}" -lt 10485760 ]; then
    echo "ERROR: Extracted MMDB file size (${MMDB_SIZE} bytes) is below minimum safety threshold (10MB)."
    exit 1
fi

# Magic bytes check (MaxMind MMDB contains "MaxMind.com" header near end or data section)
if ! grep -q "MaxMind.com" "${MMDB_FOUND}"; then
    echo "ERROR: File failed MMDB signature validation (missing MaxMind.com marker)."
    exit 1
fi

mkdir -p "${DEST_DIR}"

# Create safety backup of existing database if present
if [ -f "${TARGET_FILE}" ]; then
    echo "==> Creating safety backup at ${BACKUP_FILE}..."
    cp -f "${TARGET_FILE}" "${BACKUP_FILE}"
fi

# Atomic replacement
echo "==> Performing atomic replacement..."
cp "${MMDB_FOUND}" "${TARGET_FILE}.tmp"
mv -f "${TARGET_FILE}.tmp" "${TARGET_FILE}"

echo "==> Update successful!"
ls -lh "${TARGET_FILE}"
echo "======================================================================"
