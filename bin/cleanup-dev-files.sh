#!/bin/bash
# Meyvora SEO — Delete internal audit/review docs from plugin root
# Run once from plugin root: bash bin/cleanup-dev-files.sh
set -e
DEV_FILES=(
  "ARCHITECTURE-AUDIT-PHASE1.md"
  "AUDIT-PHASE1.md"
  "ELEMENTOR-INTEGRATION.md"
  "FINAL-RELEASE-REVIEW.md"
  "PRE-LAUNCH-AUDIT.md"
  "RELEASE-REVIEW.md"
  "SCORING.md"
  "SHIPPED-FILES.txt"
  "STRUCTURE.md"
)
for f in "${DEV_FILES[@]}"; do
  if [ -f "$f" ]; then
    rm "$f"
    echo "✅ Deleted: $f"
  else
    echo "⏭  Not found: $f"
  fi
done
echo ""
echo "Cleanup complete."
