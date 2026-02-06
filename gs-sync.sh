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
#
# Crontab example (every 30 minutes):
#   */30 * * * * cd /path/to/woo-importer && ./gs-sync.sh >> logs/cron.log 2>&1
#
# =============================================================================

set -e
cd "$(dirname "$0")"

# Parse options
DRY_RUN=""
VERBOSE=""
SKIP_MEDIA=""
FORCE_FULL=""

for arg in "$@"; do
    case $arg in
        --dry-run)     DRY_RUN="--dry-run" ;;
        --verbose|-v)  VERBOSE="--verbose" ;;
        --skip-media)  SKIP_MEDIA="1" ;;
        --force-full)  FORCE_FULL="--force-full" ;;
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

# Ensure logs directory exists
mkdir -p logs

echo ""
echo "========================================"
echo "  Golden Sneakers Sync Pipeline"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
echo ""

# Step 1: Ensure taxonomies exist (categories, attributes, brands from GS feed)
echo "[Step 1/4] Preparing taxonomies..."
php prepare-taxonomies.php --from-gs $DRY_RUN $VERBOSE
echo ""

# Step 2: Upload new images to WordPress media library
if [ -z "$SKIP_MEDIA" ]; then
    echo "[Step 2/4] Preparing media..."
    php prepare-media.php --from-gs $DRY_RUN $VERBOSE
    echo ""
else
    echo "[Step 2/4] Skipping media (--skip-media)"
    echo ""
fi

# Step 3: Transform GS feed → WooCommerce format
echo "[Step 3/4] Transforming feed..."
php gs-transform.php $VERBOSE
echo ""

# Step 4: Delta sync + import
echo "[Step 4/4] Running delta sync..."
php sync-wc.php --feed=data/feed-wc-latest.json $DRY_RUN $VERBOSE $FORCE_FULL

echo ""
echo "========================================"
echo "  Pipeline complete"
echo "  $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"
