#!/bin/bash
# Meyvora SEO — Build distribution zip (delegates to root build-zip.sh).
# Usage: bash bin/build-dist.sh [version]
# Output: dist/meyvora-seo.zip and dist/meyvora-seo-<version>.zip (same as build-zip.sh)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
exec "$PLUGIN_ROOT/build-zip.sh" "$@"
