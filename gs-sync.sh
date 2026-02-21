#!/bin/bash
# =============================================================================
# Golden Sneakers Sync Pipeline - Crontab Entrypoint
# =============================================================================
#
# Full pipeline: taxonomies → media → transform → delta sync → import
# Source: Golden Sneakers API
#
# Usage:
#   ./gs-sync.sh                     # Full sync
#   ./gs-sync.sh --dry-run           # Preview everything
#   ./gs-sync.sh --skip-media        # Skip image upload step
#   ./gs-sync.sh --force-full        # Force full import (ignore delta)
#   ./gs-sync.sh --verbose           # Detailed output from all steps
#   ./gs-sync.sh --env=environment/clientA.env  # Multi-customer mode
#
# Multi-customer crontab (single install, multiple stores):
#   */30 * * * * cd /path/to/woo-importer && ./gs-sync.sh --env=environment/clientA.env >> logs/clientA-gs.log 2>&1
#   */30 * * * * cd /path/to/woo-importer && ./gs-sync.sh --env=environment/clientB.env >> logs/clientB-gs.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
SKIP_MEDIA=""
FORCE_FULL=""
ENV_ARG=""

for arg in "$@"; do
    case $arg in
        --dry-run)     DRY_RUN="--dry-run" ;;
        --verbose|-v)  VERBOSE="--verbose" ;;
        --skip-media)  SKIP_MEDIA="1" ;;
        --force-full)  FORCE_FULL="--force-full" ;;
        --env=*)       ENV_ARG="$arg" ;;
        --help|-h)
            echo "Usage: ./gs-sync.sh [options]"
            echo ""
            echo "Options:"
            echo "  --dry-run       Preview all changes without writing"
            echo "  --skip-media    Skip image upload step"
            echo "  --force-full    Force full import (ignore delta)"
            echo "  --verbose, -v   Detailed output from all steps"
            echo "  --help, -h      Show this help"
            echo ""
            echo "Crontab:"
            echo "  */30 * * * * cd /path/to/woo-importer && ./gs-sync.sh >> logs/cron.log 2>&1"
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
echo "  Golden Sneakers Sync Pipeline"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

# Step 1: Ensure taxonomies exist (categories, attributes, brands from GS feed)
echo "[Step 1/4] Preparing taxonomies..."
php bin/prepare-taxonomies --from-gs $DRY_RUN $VERBOSE $ENV_ARG
echo ""

# Step 2: Upload new images to WordPress media library
if [ -z "$SKIP_MEDIA" ]; then
    echo "[Step 2/4] Preparing media..."
    php bin/prepare-media --from-gs $DRY_RUN $VERBOSE $ENV_ARG
    echo ""
else
    echo "[Step 2/4] Skipping media (--skip-media)"
    echo ""
fi

# Step 2.5: Validate image map (remove stale media references)
echo "[Step 2.5] Validating image map..."
php bin/prepare-media --validate $VERBOSE $ENV_ARG
echo ""

# Step 3: Transform GS feed → WooCommerce format
echo "[Step 3/4] Transforming feed..."
php bin/gs-transform $VERBOSE $ENV_ARG
echo ""

# Step 4: Delta sync + import
echo "[Step 4/4] Running delta sync..."
php bin/sync-wc --feed="$DATA_DIR/feed-wc-latest.json" $DRY_RUN $VERBOSE $FORCE_FULL $ENV_ARG

echo ""
echo "========================================"
echo "  Pipeline complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
