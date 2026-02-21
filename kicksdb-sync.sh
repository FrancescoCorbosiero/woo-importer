#!/bin/bash
# =============================================================================
# KicksDB Sync Pipeline - Crontab Entrypoint
# =============================================================================
#
# Full pipeline: discover → taxonomies → media → transform → delta sync → import
# Source: KicksDB API (popular products auto-discovery)
#
# Usage:
#   ./kicksdb-sync.sh                     # Full sync
#   ./kicksdb-sync.sh --dry-run           # Preview everything
#   ./kicksdb-sync.sh --skip-media        # Skip image upload step
#   ./kicksdb-sync.sh --skip-discover     # Skip discovery (use cached assortment)
#   ./kicksdb-sync.sh --force-full        # Force full import (ignore delta)
#   ./kicksdb-sync.sh --verbose           # Detailed output from all steps
#   ./kicksdb-sync.sh --limit=100         # Limit assortment size
#   ./kicksdb-sync.sh --env=environment/clientA.env  # Multi-customer mode
#
# Multi-customer crontab (single install, multiple stores):
#   0 */6 * * * cd /path/to/woo-importer && ./kicksdb-sync.sh --env=environment/clientA.env >> logs/clientA-kicksdb.log 2>&1
#   0 */6 * * * cd /path/to/woo-importer && ./kicksdb-sync.sh --env=environment/clientB.env >> logs/clientB-kicksdb.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
SKIP_MEDIA=""
SKIP_DISCOVER=""
FORCE_FULL=""
LIMIT_ARG=""
ENV_ARG=""

for arg in "$@"; do
    case $arg in
        --dry-run)         DRY_RUN="--dry-run" ;;
        --verbose|-v)      VERBOSE="--verbose" ;;
        --skip-media)      SKIP_MEDIA="1" ;;
        --skip-discover)   SKIP_DISCOVER="1" ;;
        --force-full)      FORCE_FULL="--force-full" ;;
        --limit=*)         LIMIT_ARG="$arg" ;;
        --env=*)           ENV_ARG="$arg" ;;
        --help|-h)
            echo "Usage: ./kicksdb-sync.sh [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run           Preview all changes without writing"
            echo "  --skip-media        Skip image upload step"
            echo "  --skip-discover     Skip discovery (use cached assortment)"
            echo "  --force-full        Force full import (ignore delta)"
            echo "  --limit=N           Limit assortment size"
            echo "  --verbose, -v       Detailed output from all steps"
            echo "  --help, -h          Show this help"
            echo ""
            echo "Crontab:"
            echo "  0 */6 * * * cd /path/to/woo-importer && ./kicksdb-sync.sh >> logs/cron-kicksdb.log 2>&1"
            exit 0
            ;;
    esac
done

# Resolve DATA_DIR for file paths (must match what PHP sees via Config::dataDir())
if [ -n "$ENV_ARG" ]; then
    ENV_PATH="${ENV_ARG#--env=}"
    DATA_DIR=$(grep -s '^DATA_DIR=' "$ENV_PATH" 2>/dev/null | head -1 | cut -d= -f2 | tr -d '"'"'" || true)
fi
DATA_DIR="${DATA_DIR:-data}"
mkdir -p "$DATA_DIR" logs

echo ""
echo "========================================"
echo "  KicksDB Sync Pipeline"
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

# Step 4: Transform KicksDB feed → WooCommerce format
echo "[Step 4/5] Transforming feed..."
php bin/kicksdb-transform $VERBOSE $LIMIT_ARG $ENV_ARG
echo ""

# Step 5: Delta sync + import
echo "[Step 5/5] Running delta sync..."
php bin/sync-wc \
    --feed="$DATA_DIR/feed-kicksdb-wc-latest.json" \
    --baseline="$DATA_DIR/feed-kicksdb-wc.json" \
    --diff="$DATA_DIR/diff-kicksdb-wc.json" \
    $DRY_RUN $VERBOSE $FORCE_FULL $ENV_ARG

echo ""
echo "========================================"
echo "  Pipeline complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
