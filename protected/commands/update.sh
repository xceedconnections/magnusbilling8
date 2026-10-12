#!/bin/bash
clear
echo
echo
echo
echo "=======================WWW.MAGNUSBILLING.COM===========================";
echo "_      _                               ______ _ _ _ _  			     ";
echo "|\    /|                               | ___ (_) | (_) 			     ";
echo "| \  / | ___  ____ _ __  _   _   _____ | |_/ /_| | |_ _ __   __ _ 	 ";
echo "|  \/  |/   \/  _ \| '_ \| | | \| ___| | ___ \ | | | | '_ \ /  _ \	 ";
echo "| |\/| |  | |  (_| | | | | |_| ||____  | |_/ / | | | | | | |  (_| |	 ";
echo "|_|  |_|\___|\___  |_| | |_____|_____|  \___/|_|_|_|_|_| |_|\___  |	 ";
echo "                _/ |                                           _/ |	 ";
echo "               |__/                                           |__/ 	 ";
echo "														                 ";
echo "============================== UPDATE =================================";
echo

set -euo pipefail

MBILLING_DIR="/var/www/html/mbilling"
PACKAGE_NAME="mbilling-source.tar.gz"
PACKAGE_URL="https://github.com/xceedconnections/magnusbilling8/archive/refs/heads/source.tar.gz"
OFFICIAL_UI_URL="https://magnusbilling.org/download/MagnusBilling8-current.tar.gz"
UPDATE_COMMAND="$MBILLING_DIR/protected/commands/updateCommand.sh"
LOCK_FILE="/run/lock/magnusbilling-update.lock"

# Impede atualizações simultâneas
exec 9>"$LOCK_FILE"
flock -n 9 || {
    echo "Another MagnusBilling update is already running."
    exit 1
}

if [[ -e "$MBILLING_DIR/protected/commands/update2.sh" ]]; then
    bash "$MBILLING_DIR/protected/commands/update2.sh"
    exit
fi

cd "$MBILLING_DIR"

rm -f -- "$PACKAGE_NAME"

wget \
    --https-only \
    --output-document="$PACKAGE_NAME" \
    "$PACKAGE_URL"

# Confirma que o download é um arquivo TAR válido
tar tzf "$PACKAGE_NAME" >/dev/null

# GitHub archive root is magnusbilling8-source/
tar xzf "$PACKAGE_NAME" --strip-components=1

# Git source still references bootstrap.js (not in git). Keep/restore production UI.
need_ui_fix=0
if [[ ! -f "$MBILLING_DIR/black-neptune.json" ]]; then
    need_ui_fix=1
fi
if grep -q 'src="bootstrap.js"' "$MBILLING_DIR/index.html" 2>/dev/null; then
    need_ui_fix=1
fi
if [[ "$need_ui_fix" -eq 1 ]]; then
    echo "Restoring ExtJS production UI from official package..."
    ui_tmp="$(mktemp -d /tmp/mbilling-ui-update.XXXXXX)"
    wget --https-only --output-document="$ui_tmp/MagnusBilling8-current.tar.gz" "$OFFICIAL_UI_URL"
    mkdir -p "$ui_tmp/pkg"
    tar xzf "$ui_tmp/MagnusBilling8-current.tar.gz" -C "$ui_tmp/pkg"
    cp -f "$ui_tmp/pkg/index.html" "$MBILLING_DIR/index.html"
    shopt -s nullglob
    for f in "$ui_tmp/pkg/"*.json "$ui_tmp/pkg/"*.jsonp; do
        cp -f "$f" "$MBILLING_DIR/"
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
        if [[ -d "$ui_tmp/pkg/$d" ]]; then
            rm -rf "$MBILLING_DIR/$d"
            cp -a "$ui_tmp/pkg/$d" "$MBILLING_DIR/"
        fi
    done
    shopt -u nullglob
    rm -rf "$ui_tmp"
fi

if [[ ! -f "$UPDATE_COMMAND" ]]; then
    echo "ERROR: updateCommand.sh was not found in the package."
    exit 1
fi

bash "$UPDATE_COMMAND"

if [[ -e "$MBILLING_DIR/protected/commands/update3.sh" ]]; then
    bash "$MBILLING_DIR/protected/commands/update3.sh"
fi

echo "MagnusBilling updated successfully."