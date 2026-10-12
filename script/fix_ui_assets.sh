#!/bin/bash
# Restore MagnusBilling ExtJS production UI (fixes splash stuck on logo / Starting...).
# GitHub source installs omit compiled themes + use bootstrap.js (gitignored).
# Run as root on the portal host:
#   bash <(curl -sSL https://raw.githubusercontent.com/xceedconnections/magnusbilling8/source/script/fix_ui_assets.sh)

set -euo pipefail

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:${PATH}}"

if [[ ${EUID} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

MBILLING_DIR="${MBILLING_DIR:-/var/www/html/mbilling}"
OFFICIAL_URL="${MB_OFFICIAL_PACKAGE_URL:-https://magnusbilling.org/download/MagnusBilling8-current.tar.gz}"

if [[ ! -d "${MBILLING_DIR}" ]]; then
  echo "MagnusBilling directory not found: ${MBILLING_DIR}"
  exit 1
fi

tmp="$(mktemp -d /tmp/mbilling-ui-fix.XXXXXX)"
cleanup() { rm -rf "${tmp}"; }
trap cleanup EXIT

echo "Downloading official UI package..."
wget -O "${tmp}/MagnusBilling8-current.tar.gz" "${OFFICIAL_URL}"
mkdir -p "${tmp}/pkg"
tar xzf "${tmp}/MagnusBilling8-current.tar.gz" -C "${tmp}/pkg"

echo "Installing production index.html + ExtJS theme builds into ${MBILLING_DIR}..."
cp -f "${tmp}/pkg/index.html" "${MBILLING_DIR}/index.html"

# Theme manifests + folders shipped only in the official package
shopt -s nullglob
for f in "${tmp}/pkg/"*.json "${tmp}/pkg/"*.jsonp; do
  cp -f "${f}" "${MBILLING_DIR}/"
done

for d in \
  black-crisp black-neptune black-triton \
  blue-crisp blue-neptune blue-triton \
  gray-crisp gray-neptune gray-triton \
  green-crisp green-neptune green-triton \
  orange-crisp orange-neptune orange-triton \
  purple-crisp purple-neptune purple-triton \
  red-classic red-crisp red-neptune red-triton \
  yellow-crisp yellow-neptune yellow-triton \
  crisp-touch custom-theme custom-triton \
  magnusbilling-modern-theme
do
  if [[ -d "${tmp}/pkg/${d}" ]]; then
    rm -rf "${MBILLING_DIR}/${d}"
    cp -a "${tmp}/pkg/${d}" "${MBILLING_DIR}/"
  fi
done
shopt -u nullglob

# Web user ownership (Debian/Ubuntu)
if id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "${MBILLING_DIR}"
elif id apache >/dev/null 2>&1; then
  chown -R apache:apache "${MBILLING_DIR}"
fi

if [[ ! -f "${MBILLING_DIR}/black-neptune.json" ]]; then
  echo "ERROR: black-neptune.json still missing after fix."
  exit 1
fi

# Hetzner / cloud VMs often log "Time jumped backwards" until NTP is healthy
if command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get install -y -qq chrony >/dev/null 2>&1 || true
fi
if command -v timedatectl >/dev/null 2>&1; then
  timedatectl set-ntp true >/dev/null 2>&1 || true
fi
if systemctl list-unit-files chrony.service >/dev/null 2>&1; then
  systemctl enable --now chrony >/dev/null 2>&1 || true
elif systemctl list-unit-files chronyd.service >/dev/null 2>&1; then
  systemctl enable --now chronyd >/dev/null 2>&1 || true
fi

echo "UI assets restored."
echo "Hard-refresh the browser (Ctrl+F5) on http://YOUR_IP/mbilling/"
echo "Console 'time jumped backwards' should stop after chrony/NTP sync."
