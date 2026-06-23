#!/bin/bash
# Remove built artifacts. (FPP removes the plugin folder itself on uninstall.)
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGINDIR"
make clean || true
echo "credits cleaned. Restart fppd so the lights are no longer gated."
