#!/bin/bash
# fix-scripts.sh
#
# Fixes Windows line endings (\r\n → \n) and sets executable permissions
# on all CLI scripts. Run this once after cloning or deploying to a VPS.
#
# Usage: bash fix-scripts.sh

files=(
    # Shell pipeline wrappers
    "gs-sync.sh"
    "kicksdb-sync.sh"
    "unified-sync.sh"

    # PHP CLI scripts (bin/)
    "bin/bulk-upload"
    "bin/gs-transform"
    "bin/import-dir"
    "bin/import-kicksdb"
    "bin/import-wc"
    "bin/kicksdb-discover"
    "bin/kicksdb-transform"
    "bin/nuke-products"
    "bin/prepare-media"
    "bin/prepare-taxonomies"
    "bin/pricing-reconcile"
    "bin/sync-wc"
    "bin/unified-transform"

    # This script itself
    "fix-scripts.sh"
)

for f in "${files[@]}"; do
    if [ -f "$f" ]; then
        sed -i 's/\r//' "$f"
        chmod +x "$f"
        echo "✅ $f"
    else
        echo "⚠️  $f (not found, skipping)"
    fi
done

echo ""
echo "Done. All scripts fixed."
