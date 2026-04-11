#!/usr/bin/env bash
# Build a client-ready distribution zip: staging dir with only runtime files.
# Output: dist/meyvora-seo-<version>.zip (single zip for upload)
#
# INCLUDE (copied into staging):
#   meyvora-seo.php, readme.txt, uninstall.php, index.php
#   includes/, admin/, assets/, blocks/, integrations/, languages/, modules/
#
# EXCLUDE (dev-only, never copied):
#   build-zip.sh, bin/, dist/, .release/, .git*
#   node_modules (anywhere), *.map, package*.json
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$SCRIPT_DIR"
STAGING_DIR="${PLUGIN_ROOT}/.release/meyvora-seo"
DIST_DIR="${PLUGIN_ROOT}/dist"
SLUG="meyvora-seo"
VERSION="${1:-$(grep "Version:" "$PLUGIN_ROOT/meyvora-seo.php" 2>/dev/null | head -1 | sed 's/.*Version: *//' | tr -d '\r')}"
[ -z "$VERSION" ] && ZIP_NAME="${SLUG}.zip" || ZIP_NAME="${SLUG}-${VERSION}.zip"

echo "=== Meyvora SEO build-zip ==="
echo "Plugin root: $PLUGIN_ROOT"
echo "Staging:     $STAGING_DIR"
echo "Version:     ${VERSION:-unknown}"
echo ""

# 1) Clear dist and staging, then create structure
rm -rf "${DIST_DIR}"
rm -rf "${PLUGIN_ROOT}/.release"
mkdir -p "$STAGING_DIR"
cd "$PLUGIN_ROOT"

# 2) Copy ONLY runtime plugin files (see INCLUDE/EXCLUDE at top of script)
[ -f "$PLUGIN_ROOT/meyvora-seo.php" ]  && cp "$PLUGIN_ROOT/meyvora-seo.php" "$STAGING_DIR/"
[ -f "$PLUGIN_ROOT/readme.txt" ]       && cp "$PLUGIN_ROOT/readme.txt" "$STAGING_DIR/"
[ -f "$PLUGIN_ROOT/uninstall.php" ]    && cp "$PLUGIN_ROOT/uninstall.php" "$STAGING_DIR/"
[ -f "$PLUGIN_ROOT/index.php" ]        && cp "$PLUGIN_ROOT/index.php" "$STAGING_DIR/"

for dir in includes admin assets blocks integrations languages modules; do
  if [ -d "$PLUGIN_ROOT/$dir" ]; then
    cp -R "$PLUGIN_ROOT/$dir" "$STAGING_DIR/"
  fi
done

# 3) Remove dev-only files from staging (e.g. if copied from subdirs)
find "$STAGING_DIR" -name "*.map" -delete 2>/dev/null || true
find "$STAGING_DIR" -name "package.json" -delete 2>/dev/null || true
find "$STAGING_DIR" -name "package-lock.json" -delete 2>/dev/null || true

# 4) Delete Mac junk inside staging
echo "--- Removing Mac junk from staging ---"
find "$STAGING_DIR" -type d -name '__MACOSX' -exec rm -rf {} + 2>/dev/null || true
find "$STAGING_DIR" -type f -name '._*' -delete 2>/dev/null || true
find "$STAGING_DIR" -type f -name '.DS_Store' -delete 2>/dev/null || true
find "$STAGING_DIR" -type d -name '__MACOSX' 2>/dev/null | while read -r d; do rm -rf "$d"; done
echo "Done."
echo ""

# 5) Create single zip from .release/ so zip root is meyvora-seo/
mkdir -p "$DIST_DIR"
cd "${PLUGIN_ROOT}/.release"
# COPYFILE_DISABLE=1 prevents macOS zip from adding __MACOSX and resource-fork files
COPYFILE_DISABLE=1 zip -r "${DIST_DIR}/${ZIP_NAME}" meyvora-seo
cd "$PLUGIN_ROOT"
echo "Built: $DIST_DIR/$ZIP_NAME"
echo ""

# 6) ZIP CONTENTS SUMMARY
echo "--- ZIP CONTENTS SUMMARY ---"
echo "Top-level list (first 50 entries):"
unzip -l "$DIST_DIR/$ZIP_NAME" | head -n 55
echo ""
ZIP_LIST="$(unzip -l "$DIST_DIR/$ZIP_NAME" | awk 'NR>3 && NF>=4 {print $NF}')"
echo "Checks (on zip entry names only):"
if echo "$ZIP_LIST" | grep -q '__MACOSX'; then
  echo "  [FAIL] __MACOSX is present in zip"
else
  echo "  [OK] __MACOSX not present"
fi
if echo "$ZIP_LIST" | grep -q '\.DS_Store'; then
  echo "  [FAIL] .DS_Store is present in zip"
else
  echo "  [OK] .DS_Store not present"
fi
if echo "$ZIP_LIST" | grep -q 'dist/'; then
  echo "  [FAIL] dist/ is present in zip"
else
  echo "  [OK] dist/ not present"
fi
if echo "$ZIP_LIST" | grep -q 'build-zip\.sh\|/bin/\|package\.json'; then
  echo "  [FAIL] dev-only file(s) present in zip"
else
  echo "  [OK] no dev-only files (build-zip.sh, bin/, package.json)"
fi
echo ""
echo "Total files and size:"
unzip -l "$DIST_DIR/$ZIP_NAME" | tail -1
echo ""
echo "=== Done: $DIST_DIR/${ZIP_NAME} ==="
