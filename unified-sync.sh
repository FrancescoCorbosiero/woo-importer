#!/bin/bash
# =============================================================================
# Unified Sync Pipeline - Crontab Entrypoint
# =============================================================================
#
# Full pipeline: discover → taxonomies → media → unified-transform → delta sync → import
# Source: KicksDB (catalog master) + Golden Sneakers (pricing/stock overlay)
#
# Merges both feeds at the variation level:
# - Sizes available in GS get real supplier pricing and stock
# - Sizes only in KicksDB get market-based pricing and synthetic stock
# - GS-only products pass through with real inventory
#
# Usage:
#   ./unified-sync.sh                     # Full sync
#   ./unified-sync.sh --dry-run           # Preview everything
#   ./unified-sync.sh --skip-media        # Skip image upload step
#   ./unified-sync.sh --skip-discover     # Skip discovery (use cached assortment)
#   ./unified-sync.sh --force-full        # Force full import (ignore delta)
#   ./unified-sync.sh --verbose           # Detailed output from all steps
#   ./unified-sync.sh --limit=100         # Limit assortment size
#   ./unified-sync.sh --skip-gs           # Skip GS overlay (KicksDB only)
#   ./unified-sync.sh --env=environment/clientA.env  # Multi-customer mode
#
# Multi-customer crontab (single install, multiple stores):
#   0 */6 * * * cd /path/to/woo-importer && ./unified-sync.sh --env=environment/clientA.env >> logs/clientA-unified.log 2>&1
#   0 */6 * * * cd /path/to/woo-importer && ./unified-sync.sh --env=environment/clientB.env >> logs/clientB-unified.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
SKIP_MEDIA=""
SKIP_DISCOVER=""
SKIP_GS=""
FORCE_FULL=""
LIMIT_ARG=""
ENV_ARG=""

for arg in "$@"; do
    case $arg in
        --dry-run)         DRY_RUN="--dry-run" ;;
        --verbose|-v)      VERBOSE="--verbose" ;;
        --skip-media)      SKIP_MEDIA="1" ;;
        --skip-discover)   SKIP_DISCOVER="1" ;;
        --skip-gs)         SKIP_GS="--skip-gs" ;;
        --force-full)      FORCE_FULL="--force-full" ;;
        --limit=*)         LIMIT_ARG="$arg" ;;
        --env=*)           ENV_ARG="$arg" ;;
        --help|-h)
            echo "Usage: ./unified-sync.sh [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run           Preview all changes without writing"
            echo "  --skip-media        Skip image upload step"
            echo "  --skip-discover     Skip discovery (use cached assortment)"
            echo "  --skip-gs           Skip GS overlay (KicksDB only)"
            echo "  --force-full        Force full import (ignore delta)"
            echo "  --limit=N           Limit assortment size"
            echo "  --verbose, -v       Detailed output from all steps"
            echo "  --help, -h          Show this help"
            echo ""
            echo "Crontab:"
            echo "  0 */6 * * * cd /path/to/woo-importer && ./unified-sync.sh >> logs/cron-unified.log 2>&1"
            exit 0
            ;;
    esac
done

# Resolve DATA_DIR for file paths (must match what PHP sees via Config::dataDir())
if [ -n "$ENV_ARG" ]; then
    ENV_PATH="${ENV_ARG#--env=}"
    DATA_DIR=$(grep -s '^DATA_DIR=' "$ENV_PATH" 2>/dev/null | head -1 | cut -d= -f2 | tr -d '"'"'" | tr -d ' \r' || true)
fi
DATA_DIR="${DATA_DIR:-data}"
mkdir -p "$DATA_DIR" logs

echo ""
echo "========================================"
echo "  Unified Sync Pipeline"
echo "  KicksDB + GS → WooCommerce"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

# Step 1: Discover popular products from KicksDB
if [ -z "$SKIP_DISCOVER" ]; then
    echo "[Step 1/5] Discovering KicksDB assortment..."
    php bin/kicksdb-discover $VERBOSE $LIMIT_ARG $DRY_RUN $ENV_ARG
    echo ""
else
    echo "[Step 1/5] Skipping discovery (--skip-discover)"
    echo ""
fi

# Step 2: Ensure taxonomies exist (hierarchical categories + brands from catalog)
echo "[Step 2/5] Preparing taxonomies..."
php bin/prepare-taxonomies --from-catalog $DRY_RUN $VERBOSE $ENV_ARG
echo ""

# Step 3: Upload new images to WordPress media library
if [ -z "$SKIP_MEDIA" ]; then
    echo "[Step 3/5] Preparing media..."
    php bin/prepare-media --from-kicksdb $DRY_RUN $VERBOSE $ENV_ARG
    echo ""
else
    echo "[Step 3/5] Skipping media (--skip-media)"
    echo ""
fi

# Step 3.5: Validate image map (remove stale media references)
echo "[Step 3.5] Validating image map..."
php bin/prepare-media --validate $VERBOSE $ENV_ARG
echo ""

# Step 4: Unified transform — merge KicksDB + GS → WooCommerce format
echo "[Step 4/5] Transforming unified feed (KicksDB + GS merge)..."
php bin/unified-transform $VERBOSE $LIMIT_ARG $SKIP_GS $ENV_ARG
echo ""

# Step 5: Delta sync + import
echo "[Step 5/5] Running delta sync..."
php bin/sync-wc \
    --feed="$DATA_DIR/feed-unified-wc-latest.json" \
    --baseline="$DATA_DIR/feed-unified-wc.json" \
    --diff="$DATA_DIR/diff-unified-wc.json" \
    $DRY_RUN $VERBOSE $FORCE_FULL $ENV_ARG

echo ""
echo "========================================"
echo "  Pipeline complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
